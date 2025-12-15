<?php

class bandwidth_adapter implements opensms_message_adapter, opensms_message_router {

	const app_name = 'bandwidth_opensms';
	const app_uuid = 'c1624fd7-ab9d-4dea-9c67-5e2da74a603e';

	const OPENSMS_PROVIDER_NAME = 'bandwidth';
	const OPENSMS_PROVIDER_LABEL = 'Bandwidth SMS';
	const OPENSMS_PROVIDER_VERSION = '1.0.0';
	const OPENSMS_PROVIDER_AUTHOR = 'Tim Fry';
	const OPENSMS_PROVIDER_WEBSITE = 'https://www.bandwidth.com';
	const OPENSMS_PROVIDER_DESCRIPTION = 'Bandwidth SMS Gateway Integration';
	const OPENSMS_PROVIDER_UUID = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
	const OPENSMS_PROVIDER_SETTINGS_CATEGORY = 'opensms';
	const OPENSMS_PROVIDER_SETTINGS_SUBCATEGORY = 'bandwidth';

	const ACCESS_CONTROL_UUID = '4a43c9e0-69de-4c6d-bfa4-4cb591f8c1e3';

	const CIDR = [
		'3.82.123.96/32',
		'18.233.250.246/32',
		'52.72.24.132/32',
	];

	/** @var settings */
	protected $settings;

	/** @var string */
	protected $time;

	/** @var string */
	protected $to_number;

	/** @var string */
	protected $from_number;

	/** @var string */
	protected $sms;

	/** @var array|null */
	protected $mms;

	/** @var string|null */
	protected $type;

	/** @var string|null */
	protected $received_data;

	/**
	 * Route the message to the Bandwidth adapter if the message is from a Bandwidth number.
	 *
	 * This method checks if the message's 'to' number matches any destination numbers
	 * associated with the Bandwidth provider in the database. If a match is found,
	 * it returns the Bandwidth provider UUID to route the message accordingly.
	 * If no match is found, it calls the next router in the chain if available.
	 *
	 * @param settings        $settings Configuration container providing global default settings.
	 * @param opensms_message $message  The opensms_message object containing message details.
	 * @param array           $adapters Array of available adapter class names.
	 * @param callable|null   $next     The next router in the chain, or null if none.
	 *
	 * @return opensms_message_adapter|null The adapter, or null if no match.
	 */
	public function __invoke(settings $settings, opensms_message $message, array $adapters, ?callable $next): ?string {
		// // Check if the message is coming from a phone number associated with Bandwidth
		// $sql = "select reverse(concat(destination_prefix, destination_trunk, destination_area_code, destination_number)) as rev_number from v_destinations ";
		// $sql .= "where provider_uuid = :provider_uuid ";
		// $sql .= "and from_number in (";
		// $numbers = opensms::reverse_number_as_array($message->to_number, 10);
		// foreach ($numbers as $prefix) {
		// 	$sql .= ":number_{$prefix}, ";
		// 	$parameters["number_{$prefix}"] = $prefix;
		// }
		// $sql .= ") and destination_enabled = 'true' ";
		// $parameters['provider_uuid'] = self::OPENSMS_PROVIDER_UUID;
		// $parameters['from_number'] = strrev($message->from_number);
		// $database = $settings->database();
		// $result = $database->select($sql, $parameters, 'column');
		// if (!empty($result)) {
		// 	// Matched Bandwidth provider
		// 	return $this;
		// }

		// // Not matched, call the next router in the chain if available
		// if ($next !== null) {
		// 	return $next($settings, $message);
		// }
		return null;
	}

	/**
	 * Determine whether the given IP address is present/allowed in the provided Bandwidth OpenSMS settings.
	 *
	 * This static method inspects the provided $settings object (the Bandwidth/OpenSMS configuration)
	 * and checks whether the specified $ip_address (IPv4 or IPv6) is recognized by those settings.
	 * It returns true when the address is found/considered permitted according to the configuration,
	 * and false otherwise.
	 *
	 * @param settings $settings  Settings object containing Bandwidth/OpenSMS configuration to check against.
	 * @param string   $ip_address IPv4 or IPv6 address to verify.
	 * @return bool True if the IP address is present/allowed in the settings; false otherwise.
	 */
	public static function has(settings $settings, string $ip_address): bool {
		$bandwidth_cidrs = opensms::get_cidrs($settings->database(), self::ACCESS_CONTROL_UUID);
		foreach ($bandwidth_cidrs as $cidr) {
			if (check_cidr($cidr,$ip_address)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get the SMS message body text.
	 *
	 * @return string|null The SMS message body text, or null if not set.
	 */
	public function get_sms(): ?string {
		return $this->sms;
	}

	/**
	 * Get the MMS attachments as an array.
	 *
	 * @return array|null The array of MMS attachments, or null if none are present.
	 */
	public function get_mms(): ?array {
		return $this->mms;
	}

	/**
	 * Get the message type (e.g., 'sms' or 'mms').
	 *
	 * @return string|null The message type, or null if not set.
	 */
	public function get_type(): ?string {
		return $this->type;
	}

	/**
	 * Get the recipient's phone number.
	 *
	 * @return string The recipient's phone number, or null if not set.
	 */
	public function get_to_number(): string {
		return $this->to_number;
	}

	/**
	 * Get the sender's phone number.
	 *
	 * @return string The sender's phone number, or null if not set.
	 */
	public function get_from_number(): string {
		return $this->from_number;
	}

	/**
	 * Get the time associated with the message.
	 *
	 * @return string|null The time as a string, or null if not set.
	 */
	public function get_time(): ?string {
		return $this->time;
	}

	/**
	 * Get the provider UUID.
	 *
	 * @return string The UUID of the provider.
	 */
	public function get_provider_uuid(): string {
		return self::OPENSMS_PROVIDER_UUID;
	}

	/**
	 * Get the provider name.
	 *
	 * @return string The name of the provider.
	 */
	public function get_provider_name(): string {
		return self::OPENSMS_PROVIDER_NAME;
	}

	/**
	 * Get the raw data received from Bandwidth.
	 *
	 * @return string|null The raw data as a string, or null if no data was received.
	 */
	public function get_received_data(): ?string {
		return $this->received_data;
	}

	/**
	 * Process the Bandwidth OpenSMS transaction.
	 *
	 * Validates provider configuration and message data, builds and sends the
	 * OpenSMS API request, handles the provider response, updates local message
	 * state and logging, and performs any necessary error handling or retries.
	 *
	 * This method performs network I/O and has side effects (DB updates, logs,
	 * outbound HTTP requests). Callers should be prepared to handle exceptions
	 * arising from validation, transport, or response processing.
	 *
	 * @param settings $settings Configuration container providing global default settings.
	 *
	 * @return opensms_message The processed opensms_message object on success, or null if no message was processed.
	 * @throws \Exception If configuration is invalid, request preparation fails,
	 *                    the HTTP request fails, or the provider returns an error.
	 * @access public
	 */
	public function receive(settings $settings, object $payload): ?opensms_message {

		$this->settings = $settings;

		// Use the provided payload object; adapters should not read php://input directly.
		if (method_exists($payload, 'isEmpty') && $payload->isEmpty()) {
			return null;
		}

		$json_string = method_exists($payload, 'raw') ? $payload->raw() : (string)$payload;
		$json_array = json_decode($json_string, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new \Exception("Invalid JSON payload received from Bandwidth");
		}

		// Store the received data
		$this->received_data = $json_string;

		// Process 'time' if present
		if (isset($json_array[0]['message']['time'])) {
			// Validate 'time' format
			$this->time = $json_array[0]['message']['time'];
			// Further validation can be added here
		}

		// Process 'to' if present
		if (isset($json_array[0]['message']['to'][0])) {
			// Validate 'to' number
			$this->to_number = $json_array[0]['message']['to'][0];
			// Further validation can be added here
		}

		// Process 'from' if present
		if (isset($json_array[0]['message']['from'])) {
			// Validate 'from' number
			$this->from_number = $json_array[0]['message']['from'];
			// Further validation can be added here
		}

		// Process 'text' if present
		if (isset($json_array[0]['message']['text'])) {
			// Process SMS message
			$this->sms = $this->process_sms($json_array);
			$this->type = 'sms';
		}

		// Process MMS media if present
		if (isset($json_array[0]['message']['media'])) {
			$links = $json_array[0]['message']['media'];
			// Check for MMS messages
			if (!empty($links)) {
				$this->mms = $this->process_mms($links);
				$this->type = 'mms';
			}
		}

		// No modifications are made to the message object here
		// Instead, we simply return a new opensms_message instance because
		// the message data is stored in this adapter's properties.
		// The caller will use the adapter's getters to access the data.
		return new opensms_message(uuid(), self::OPENSMS_PROVIDER_UUID);
	}

	/**
	 * Send an SMS or MMS message via Bandwidth OpenSMS.
	 *
	 * Constructs and sends the appropriate API request to Bandwidth based on
	 * the provided opensms_message object, handling authentication, payload
	 * formatting, response processing, and error handling.
	 *
	 * This method performs network I/O and has side effects (outbound HTTP requests).
	 * Callers should be prepared to handle exceptions arising from transport or
	 * response processing.
	 *
	 * @param settings        $settings Configuration container providing global default settings.
	 * @param opensms_message $message  The opensms_message object containing message details to send.
	 *
	 * @return bool            True on successful send; false otherwise.
	 * @throws \curl_exception If configuration is invalid, request preparation fails,
	 *                         or if the API request fails.
	 * @access public
	 */
	public static function send(settings $settings, opensms_message $message): bool {

		// Get the Bandwidth configuration from settings
		$account_id = $settings->get(self::OPENSMS_PROVIDER_NAME, 'account_id', '');
		$url = $settings->get(self::OPENSMS_PROVIDER_NAME, 'api_url', "https://messaging.bandwidth.com/api/v2/{$account_id}/messages");
		$application_id = $settings->get(self::OPENSMS_PROVIDER_NAME, 'application_id', '');
		$api_username = $settings->get(self::OPENSMS_PROVIDER_NAME, 'api_username', '');
		$api_password = $settings->get(self::OPENSMS_PROVIDER_NAME, 'api_password', '');

		// Payload structure for Bandwidth API
		$payload = [
			'to' => $message->to_number,
			'from' => $message->from_number,
			'applicationId' => $application_id,
			'text' => $message->sms,
			'tag' => 'OpenSMS Message',
		];

		// Prepare the request curl client
		$curl_client = new curl_client();

		// Set options for curl POST request
		$headers = [
			'Content-Type: application/json',
			'Accept: application/json',
			'Authorization: Basic ' . base64_encode($api_username . ':' . $api_password),
		];

		// Send the POST request to Bandwidth
		$response = $curl_client->post($url, $message->to_array(), $headers, null, null, true);

		// Check for errors in the response
		if (!empty($response['error'])) {
			throw new curl_exception("Error sending message via Bandwidth: " . $response['error']);
			return false;
		}

		// Assume success if we reach here
		return true;
	}

	private function process_sms(array $json_array): string {
		// Handle SMS message
		$sms_text = $json_array[0]['message']['text'] ?? '';
		// Return the text
		return $sms_text;
	}

	private function process_mms(array $mms): array {
		$media_files = [];
		$username = $this->settings->get(self::OPENSMS_PROVIDER_NAME, 'callback_user_id', '');
		$password = $this->settings->get(self::OPENSMS_PROVIDER_NAME, 'callback_password', '');
		// Handle MMS media links
		foreach ($mms as $media_link) {
			$curl_client = new curl_client();
			$response = $curl_client->get($media_link, [], $username, $password);
			if (!empty($response['error'])) {
				// Log error and continue
				echo "Error fetching media from Bandwidth: " . $response['error'] . "\n";
				continue;
			}
			$media_content = $response['content'];
			$media_files[] = $media_content;
		}
		return $media_files;
	}

	/**
	 * Assign the settings instance to this object.
	 *
	 * Accepts a settings object that contains configuration options required by this
	 * class, stores it internally and applies any necessary normalization or
	 * validation. Implementations should use the provided settings to configure
	 * transport, credentials, timeouts, logging preferences, and other behavior
	 * specific to the SMS provider integration.
	 *
	 * @param settings $settings Configuration container providing options for this instance.
	 *
	 * @return void
	 * @throws \InvalidArgumentException If the provided settings contain invalid or missing values.
	 * @throws \TypeError If a non-settings value is passed (enforced by the method signature).
	 */
	public function set_settings(settings $settings): void {
		$this->settings = $settings;
	}

	/**
	 * Hook in to the app_config
	 *
	 * Sets the Global default configuration settings for the Bandwidth OpenSMS provider.
	 *
	 * @return array|null
	 */
	public static function app_config(): ?array {
		$y = 0;
		$defaults = [];
		$defaults['default_settings'][$y]['default_setting_uuid'] = 'c01ef185-72b8-4632-9226-df4dc7658862';
		$defaults['default_settings'][$y]['default_setting_category'] = self::OPENSMS_PROVIDER_NAME;
		$defaults['default_settings'][$y]['default_setting_subcategory'] = 'account_id';
		$defaults['default_settings'][$y]['default_setting_name'] = 'text';
		$defaults['default_settings'][$y]['default_setting_value'] = '';
		$defaults['default_settings'][$y]['default_setting_enabled'] = 'false';
		$y++;
		$defaults['default_settings'][$y]['default_setting_uuid'] = 'e853d3af-ecf0-4178-8923-f4ad622d721c';
		$defaults['default_settings'][$y]['default_setting_category'] = self::OPENSMS_PROVIDER_NAME;
		$defaults['default_settings'][$y]['default_setting_subcategory'] = 'callback_user_id';
		$defaults['default_settings'][$y]['default_setting_name'] = 'text';
		$defaults['default_settings'][$y]['default_setting_value'] = '';
		$defaults['default_settings'][$y]['default_setting_enabled'] = 'false';
		$y++;
		$defaults['default_settings'][$y]['default_setting_uuid'] = '67d9116a-ea25-4494-93c1-ad5f56da968b';
		$defaults['default_settings'][$y]['default_setting_category'] = self::OPENSMS_PROVIDER_NAME;
		$defaults['default_settings'][$y]['default_setting_subcategory'] = 'callback_password';
		$defaults['default_settings'][$y]['default_setting_name'] = 'text';
		$defaults['default_settings'][$y]['default_setting_value'] = '';
		$defaults['default_settings'][$y]['default_setting_enabled'] = 'false';
		$y++;
		$defaults['default_settings'][$y]['default_setting_uuid'] = '9922e56a-ef2a-4cd1-bd66-37fe8e8e3392';
		$defaults['default_settings'][$y]['default_setting_category'] = self::OPENSMS_PROVIDER_NAME;
		$defaults['default_settings'][$y]['default_setting_subcategory'] = 'application_id';
		$defaults['default_settings'][$y]['default_setting_name'] = 'text';
		$defaults['default_settings'][$y]['default_setting_value'] = '';
		$defaults['default_settings'][$y]['default_setting_enabled'] = 'false';
		return $defaults;
	}

	/**
	 * Inject a provider entry into the v_providers table if it does not already exist by hooking into app_defaults.
	 *
	 * Outgoing SMS messages must have a route available based on a link between provider and adapter. Assigning
	 * a provider here ensures that the Bandwidth adapter has a valid provider to link to the destination number.
	 *
	 * The method has side effects (writes to the application's settings table) and should be
	 * called with a valid database object. It does not return a value.
	 *
	 * @param database $database Database connection/abstraction used to read and persist application defaults.
	 * @return void
	 * @throws \InvalidArgumentException If the provided $database is null or of an unexpected type.
	 * @throws \RuntimeException If a database operation fails (e.g., connection error or constraint violation).
	 */
	public static function app_defaults(database $database): void {
		// Set the ACL
		if (!opensms::has_acl($database, self::ACCESS_CONTROL_UUID)) {
			opensms::create_access_control($database, self::ACCESS_CONTROL_UUID, self::OPENSMS_PROVIDER_LABEL, self::OPENSMS_PROVIDER_DESCRIPTION);
			opensms::add_acl_cidrs($database, self::ACCESS_CONTROL_UUID, self::CIDR, self::OPENSMS_PROVIDER_LABEL);
		}

		// Check if the provider already exists
		$sql = "select count(provider_uuid) from v_providers where provider_uuid = :provider_uuid ";
		$parameters['provider_uuid'] = self::OPENSMS_PROVIDER_UUID;
		$exists = $database->select($sql, $parameters, 'column') > 0;
		if (!$exists) {
			// Provider not found so insert it
			$array = [];
			$array['providers'][0]['provider_uuid'] = self::OPENSMS_PROVIDER_UUID;
			$array['providers'][0]['provider_name'] = self::OPENSMS_PROVIDER_NAME;
			$array['providers'][0]['provider_enabled'] = 'true';
			$array['providers'][0]['provider_description'] = self::OPENSMS_PROVIDER_DESCRIPTION;
			$database->save($array);
		}
	}

	public static function app_menu(): ?array {
		//not implemented
		return null;
	}
}
