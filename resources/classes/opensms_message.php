<?php

/**
 * Class opensms_message
 *
 * Encapsulates composition, validation, transmission and management of SMS messages
 * for the OpenSMS integration in FusionPBX.
 *
 * @author Tim Fry
 */
class opensms_message {
	/**
	 * The recipient phone number for the outgoing SMS message.
	 *
	 * Expected to be a non-empty string representing a telephone number, preferably
	 * in E.164 format (for example: "+14155552671"). Implementations should validate:
	 * - presence (not empty)
	 * - allowed characters: digits with an optional leading '+' for international format
	 * - recommended maximum length according to E.164 (up to 15 digits, not counting the '+')
	 *
	 * @var string Recipient phone number (E.164 recommended), e.g. "+14155552671"
	 */
	public $to_number;

	/**
	 * Sender phone number for outgoing SMS messages.
	 *
	 * Expected in international E.164 format (e.g. "+12025550123").
	 * This value is displayed to the recipient and used as the message originator/sender ID.
	 *
	 * @var string
	 */
	public $from_number;

	/**
	 * The SMS message body.
	 *
	 * Contains the text payload of the SMS to be sent or received via OpenSMS.
	 *
	 * @var string
	 */
	public $sms;

	/**
	 * MMS parts for the message.
	 *
	 * Each entry represents a multimedia part/attachment associated with the message.
	 * Typical keys for each part may include:
	 *  - filename (string): the attachment filename
	 *  - content_type (string): MIME type, e.g. "image/jpeg", "video/mp4"
	 *  - data (string): raw or base64-encoded payload of the attachment
	 *  - url (string): optional remote URL to fetch the attachment instead of embedding
	 *  - cid (string): optional content ID for inline embedding
	 *  - size (int): optional size of the attachment in bytes
	 *
	 * Handlers working with this property should encode or transmit parts according to
	 * the target carrier/protocol requirements.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public $mms;

	/**
	 * Message timestamp.
	 *
	 * ISO 8601 formatted date/time string representing when the message was created or received.
	 * Preferred format: "YYYY-MM-DDTHH:MM:SSZ" or with timezone offset (e.g. "2023-08-01T14:30:00+00:00").
	 * This value is used for display, sorting, and any time-based logic. Should include timezone or be in UTC.
	 *
	 * @var string ISO 8601 datetime (non-nullable)
	 */
	public $time;

	/**
	 * Array of user UUIDs associated with this message.
	 *
	 * Each element is a canonical UUID string (for example "550e8400-e29b-41d4-a716-446655440000")
	 * identifying a user related to the message (such as sender, recipient, or users permitted to view it).
	 * If no users are associated, this should be an empty array.
	 *
	 * @var string[] List of user UUIDs (each a 36-character UUID string)
	 */
	public $user_uuids;

	/**
	 * Extensions associated with this OpenSMS message.
	 *
	 * This public array contains the extensions related to the message. Each
	 * element may be a simple extension identifier (string/int) or an associative
	 * array providing extension metadata (for example: number, type, label, status).
	 *
	 * @var array<int|string,mixed> Array of extensions or extension metadata
	 */
	public $extensions;

	/**
	 * The SIP profile name used for routing/sending this OpenSMS message.
	 *
	 * Identifies the SIP profile (as configured in FusionPBX) that should be
	 * used by the messaging subsystem to send or route the message. Expected
	 * to match an existing SIP profile identifier such as "internal" or
	 * "external". This value must be a non-empty string when a profile is
	 * required for delivery.
	 *
	 * @var string
	 */
	public $sip_profile;

	/**
	 * The domain name associated with this message.
	 *
	 * Used to scope and route the message within a multi-tenant installation.
	 * Typically contains the host or tenant identifier (for example: "tenant.mypbx.com").
	 *
	 * @var string
	 */
	public $domain_name;

	/**
	 * @var string The unique identifier for the message destination
	 */
	public $destination_uuid;

	/**
	 * The UUID of the user associated with the message
	 *
	 * @var string
	 */
	public $user_uuid;

	/**
	 * The unique identifier for the message group
	 * @var string|null
	 */
	public $group_uuid;

	/**
	 * Stores the message fields/properties
	 *
	 * @var array Array containing message field data
	 */
	private $fields;

	/**
	 * Stores the destination addresses for broadcast messages
	 *
	 * @var array Array of destination phone numbers or addresses for broadcasting messages
	 */
	public $broadcast_destinations;

	/**
	 * Array of offline message destinations
	 *
	 * Stores the destinations where messages should be delivered when the primary
	 * destination is offline or unavailable.
	 *
	 * @var array
	 */
	public $offline_destinations;

	/**
	 * Construct a new OpenSMS message instance.
	 *
	 * Initializes a message with recipient/sender information, message content,
	 * optional media attachments, scheduling, associated users, and delivery options.
	 *
	 * @param string $to_number   Recipient phone number (e.g. in international format).
	 * @param string $from_number Sender phone number.
	 * @param string $sms         SMS message body text.
	 * @param array  $mms         Optional array of MMS attachments (URLs or file paths).
	 * @param string $time        Optional send time as a string (e.g. ISO 8601 or Unix timestamp). Empty to send immediately.
	 * @param array  $user_uuids  Optional array of user UUIDs associated with this message.
	 * @param array  $extensions  Optional associative array of extension data / metadata.
	 * @param string $sip_profile SIP profile to use for sending (defaults to 'internal').
	 * @param string $domain_name Domain name associated with the message (optional).
	 */
	public function __construct(string $to_number = '', string $from_number = '', string $sms = '', array $mms = [], string $time = '', array $user_uuids = [], array $extensions = [], string $sip_profile = 'internal', string $domain_name = '') {
		$this->to_number = $to_number;
		$this->from_number = $from_number;
		$this->sms = $sms;
		$this->mms = $mms;
		$this->time = $time;
		$this->user_uuids = $user_uuids;
		$this->extensions = $extensions;
		$this->sip_profile = $sip_profile;
		$this->fields = [];
		$this->domain_name = $domain_name;
		$this->broadcast_destinations = [];
		$this->offline_destinations = [];
	}

	/**
	 * Set a field value for the message object
	 *
	 * @param string $field_name The name of the field to set
	 * @param mixed $value The value to assign to the field
	 * @return void
	 */
	public function set_field(string $field_name, $value): void {
		$this->fields[$field_name] = $value;
	}

	/**
	 * Retrieves the value of a specified field
	 *
	 * @param string $field_name The name of the field to retrieve
	 * @return mixed The value of the specified field
	 */
	public function get_field(string $field_name) {
		return $this->fields[$field_name] ?? null;
	}

	/**
	 * Get the fields for the OpenSMS message
	 *
	 * Retrieves an array of fields that are associated with or required for
	 * OpenSMS message operations.
	 *
	 * @return array An array containing the message fields
	 */
	public function get_fields(): array {
		return $this->fields;
	}

	/**
	 * Check if a specific field exists in the message object
	 *
	 * @param string $field_name The name of the field to check for existence
	 * @return bool Returns true if the field exists, false otherwise
	 */
	public function has_field(string $field_name): bool {
		return isset($this->fields[$field_name]);
	}
}
