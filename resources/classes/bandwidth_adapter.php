<?php

class bandwidth_adapter implements opensms_message_adapter, opensms_message_router {

	const app_name = 'bandwidth_opensms';
	const app_uuid = 'c1624fd7-ab9d-4dea-9c67-5e2da74a603e';

	const OPENSMS_PROVIDER_NAME = 'bandwidth';
	const OPENSMS_PROVIDER_LABEL = 'Bandwidth SMS';
	const OPENSMS_PROVIDER_DESCRIPTION = 'Bandwidth SMS Gateway Integration';
	const OPENSMS_PROVIDER_UUID = '491fa47c-1b82-4381-9a81-2c410cdfd770';

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
	public function __invoke(settings $settings, opensms_message $message, ?callable $next): ?opensms_message_adapter {
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
		$sql = "select provider_uuid from v_destinations as d, v_providers as p ";
		$sql .= "where d.provider_uuid = :provider_uuid ";
		$sql .= "and d.destination_enabled = true ";
		$sql .= "and reverse(concat(d.destination_prefix, d.destination_trunk, d.destination_area_code, d.destination_number)) in (:destination_number) ";
		$parameters['provider_uuid'] = self::OPENSMS_PROVIDER_UUID;
		$parameters['destination_number'] = implode(',', opensms::reverse_number_as_array($message->to_number, 10));
		$result = $settings->database()->select($sql, $parameters, 'column');
		if (!empty($result)) {
			// Matched Bandwidth provider
			return $this;
		}
		return null;
	}

	public static function has_destination(settings $settings, opensms_message $message): bool {
		$sql = "select provider_uuid from v_destinations as d, v_providers as p ";
		$sql .= "where d.provider_uuid = :provider_uuid ";
		$sql .= "and d.destination_enabled = true ";
		$sql .= "and reverse(concat(d.destination_prefix, d.destination_trunk, d.destination_area_code, d.destination_number)) in (:destination_number) ";
		$parameters['provider_uuid'] = self::OPENSMS_PROVIDER_UUID;
		$parameters['destination_number'] = implode(',', opensms::reverse_number_as_array($message->to_number, 10));
		$result = $settings->database()->select($sql, $parameters, 'column');
		return !empty($result);
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

		// Determine callback type
		// Bandwidth sends different callback types:
		//   message-received  = inbound SMS/MMS from external number
		//   message-delivered  = delivery receipt - carrier confirmed delivery
		//   message-sending    = outbound message being sent to carrier
		//   message-sent       = outbound message accepted by carrier
		//   message-failed     = outbound message failed
		$callback_type = $json_array[0]['type'] ?? '';

		// Map Bandwidth callback types to human-readable delivery statuses
		$delivery_receipt_types = [
			'message-sending'   => 'sending',
			'message-sent'      => 'sent',
			'message-delivered'  => 'delivered',
			'message-failed'    => 'failed',
		];

		// Handle delivery receipts separately from inbound messages
		if (isset($delivery_receipt_types[$callback_type])) {
			$message = new opensms_message(uuid(), self::OPENSMS_PROVIDER_UUID);
			$message->type = 'delivery_receipt';
			$message->delivery_status = $delivery_receipt_types[$callback_type];

			// The tag field contains our original message_uuid (set during send)
			$message->delivery_original_uuid = $json_array[0]['message']['tag'] ?? '';

			// Populate from/to from the callback (note: for outbound receipts,
			// "from" is our number and "to" is the remote number)
			$message->from_number = $json_array[0]['message']['from'] ?? '';
			$message->to_number = $json_array[0]['message']['to'][0] ?? '';
			$message->time = $json_array[0]['message']['time'] ?? date('c');
			$message->sms = $json_array[0]['description'] ?? '';

			// Populate these from the adapter's getters
			$this->from_number = $message->from_number;
			$this->to_number = $message->to_number;
			$this->time = $message->time;
			$this->sms = $message->sms;
			$this->type = 'delivery_receipt';

			return $message;
		}

		// For non-received types we don't recognize, skip
		if ($callback_type !== '' && $callback_type !== 'message-received') {
			return null;
		}

		// Process inbound message fields
		// Process 'time' if present
		if (isset($json_array[0]['message']['time'])) {
			$this->time = $json_array[0]['message']['time'];
		}

		// Process 'to' if present
		if (isset($json_array[0]['message']['to'][0])) {
			$this->to_number = $json_array[0]['message']['to'][0];
		}

		// Process 'from' if present
		if (isset($json_array[0]['message']['from'])) {
			$this->from_number = $json_array[0]['message']['from'];
		}

		// Process 'text' if present
		if (isset($json_array[0]['message']['text'])) {
			$this->sms = $this->process_sms($json_array);
			$this->type = 'sms';
		}

		// Process MMS media if present
		if (isset($json_array[0]['message']['media'])) {
			$links = $json_array[0]['message']['media'];
			if (!empty($links)) {
				$this->mms = $this->process_mms($links);
				$this->type = 'mms';
			}
		}

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
		$url = $settings->get(self::OPENSMS_PROVIDER_NAME, 'api_url', "https://messaging.bandwidth.com/api/v2/users/{$account_id}/messages");
		$application_id = $settings->get(self::OPENSMS_PROVIDER_NAME, 'application_id', '');

		// API credentials: prefer api_token/api_secret, fall back to callback_user_id/callback_password
		$api_username = $settings->get(self::OPENSMS_PROVIDER_NAME, 'api_token', '');
		$api_password = $settings->get(self::OPENSMS_PROVIDER_NAME, 'api_secret', '');
		if (empty($api_username)) {
			$api_username = $settings->get(self::OPENSMS_PROVIDER_NAME, 'callback_user_id', '');
		}
		if (empty($api_password)) {
			$api_password = $settings->get(self::OPENSMS_PROVIDER_NAME, 'callback_password', '');
		}

		// Normalize phone numbers to E.164 format for Bandwidth API
		$to_number = self::normalize_to_e164($message->to_number);
		$from_number = self::normalize_to_e164($message->from_number);

		// Payload structure for Bandwidth API
		// Use the message UUID as the tag so delivery receipts can be matched
		$payload = [
			'to'            => [$to_number],
			'from'          => $from_number,
			'applicationId' => $application_id,
			'text'          => $message->sms,
			'tag'           => $message->uuid,
		];

		// Include MMS media URLs if present
		if (!empty($message->mms) && is_array($message->mms)) {
			$media_urls = [];
			foreach ($message->mms as $index => $part) {
				if (!empty($part['url'])) {
					$media_urls[] = $part['url'];
				} elseif (!empty($part['data']) && !empty($part['content_type'])) {
					// Upload base64 media to Bandwidth Media API first, then reference the URL
					// Bandwidth requires publicly accessible HTTP/HTTPS URLs — data URIs are not supported
					$ext_parts = explode('/', $part['content_type']);
					$ext = $ext_parts[1] ?? 'bin';
					$media_name = 'opensms-' . $message->uuid . '-' . $index . '.' . $ext;
					$upload_start = microtime(true);
					$media_urls[] = self::upload_media($settings, $part['data'], $part['content_type'], $media_name);
					$upload_ms = round((microtime(true) - $upload_start) * 1000);
					error_log("[opensms] Media upload [{$index}] completed in {$upload_ms}ms: {$media_name}");
				}
			}
			if (!empty($media_urls)) {
				$payload['media'] = $media_urls;
			}
		}

		// Prepare the request curl client
		$curl_client = new curl_client();

		// Set options for curl POST request
		$headers = [
			'Content-Type: application/json',
			'Accept: application/json',
			'Authorization: Basic ' . base64_encode($api_username . ':' . $api_password),
		];

		// Send the JSON-encoded payload to Bandwidth
		$response = $curl_client->post_json($url, $payload, $headers);

		// Check for cURL transport errors
		if (!empty($response['error'])) {
			throw new curl_exception("Error sending message via Bandwidth: " . $response['error']);
		}

		// Check HTTP status code (Bandwidth returns 202 on success)
		$http_code = $response['info']['http_code'] ?? 0;
		if ($http_code < 200 || $http_code >= 300) {
			$body = $response['content'] ?? '';
			// Truncate long response bodies (e.g. those echoing back base64 data) to keep logs readable
			if (strlen($body) > 500) {
				$body = substr($body, 0, 500) . '... [truncated]';
			}
			throw new \RuntimeException("Bandwidth API returned HTTP {$http_code}: {$body}");
		}

		return true;
	}

	/**
	 * Upload media to Bandwidth's Media API for use in MMS messages.
	 *
	 * Bandwidth requires MMS media to be referenced by publicly accessible URLs.
	 * This method uploads base64-encoded media via PUT to the Bandwidth Media API,
	 * then returns the URL where the media is accessible.
	 *
	 * @param settings $settings     Configuration container.
	 * @param string   $base64_data  The base64-encoded media data.
	 * @param string   $content_type The MIME type of the media (e.g. image/jpeg).
	 * @param string   $media_name   A unique name for the media resource.
	 *
	 * @return string The URL where the uploaded media is accessible.
	 * @throws \curl_exception    If the upload request fails at the transport level.
	 * @throws \RuntimeException  If the API returns a non-2xx status.
	 */
	private static function upload_media(settings $settings, string $base64_data, string $content_type, string $media_name): string {

		$account_id = $settings->get(self::OPENSMS_PROVIDER_NAME, 'account_id', '');
		$api_username = $settings->get(self::OPENSMS_PROVIDER_NAME, 'api_token', '');
		$api_password = $settings->get(self::OPENSMS_PROVIDER_NAME, 'api_secret', '');
		if (empty($api_username)) {
			$api_username = $settings->get(self::OPENSMS_PROVIDER_NAME, 'callback_user_id', '');
		}
		if (empty($api_password)) {
			$api_password = $settings->get(self::OPENSMS_PROVIDER_NAME, 'callback_password', '');
		}

		// URL-encode the media name to handle special characters
		$encoded_name = rawurlencode($media_name);
		$media_url = "https://messaging.bandwidth.com/api/v2/users/{$account_id}/media/{$encoded_name}";

		// Decode base64 to raw binary for upload
		$binary_data = base64_decode($base64_data, true);
		if ($binary_data === false) {
			throw new \RuntimeException('Failed to decode base64 media data');
		}

		$curl_client = new curl_client();
		$response = $curl_client->request($media_url, 'PUT', [
			CURLOPT_CUSTOMREQUEST => 'PUT',
			CURLOPT_POSTFIELDS    => $binary_data,
			'headers' => [
				'Content-Type: ' . $content_type,
				'Content-Length: ' . strlen($binary_data),
			],
			'username' => $api_username,
			'password' => $api_password,
		]);

		if (!empty($response['error'])) {
			throw new curl_exception('Error uploading media to Bandwidth: ' . $response['error']);
		}

		$http_code = $response['info']['http_code'] ?? 0;
		if ($http_code < 200 || $http_code >= 300) {
			$body = $response['content'] ?? '';
			if (strlen($body) > 500) {
				$body = substr($body, 0, 500) . '... [truncated]';
			}
			throw new \RuntimeException("Bandwidth Media upload returned HTTP {$http_code}: {$body}");
		}

		return $media_url;
	}

	/**
	 * Normalize a phone number to E.164 format for Bandwidth API.
	 * Ensures numbers have a leading +1 for North American numbers.
	 *
	 * @param string $number The phone number to normalize
	 * @return string The E.164 formatted number
	 */
	private static function normalize_to_e164(string $number): string {
		// Strip any non-digit characters except leading +
		$cleaned = preg_replace('/[^\d+]/', '', $number);

		// Already in E.164 format
		if (str_starts_with($cleaned, '+')) {
			return $cleaned;
		}

		// 11 digits starting with 1 (e.g. 19022012170) -> +19022012170
		if (strlen($cleaned) === 11 && str_starts_with($cleaned, '1')) {
			return '+' . $cleaned;
		}

		// 10 digits (e.g. 9022012170) -> +19022012170
		if (strlen($cleaned) === 10) {
			return '+1' . $cleaned;
		}

		// Fallback: prepend + and hope for the best
		return '+' . $cleaned;
	}

	/**
	 * Process the SMS message text from the JSON array.
	 * @param array $json_array The decoded JSON array from Bandwidth.
	 * @return string The SMS message text.
	 */
	private function process_sms(array $json_array): string {
		// Handle SMS message
		$sms_text = $json_array[0]['message']['text'] ?? '';
		// Return the text
		return $sms_text;
	}

	/**
	 * Process MMS media links from the JSON array.
	 *
	 * Fetches media content from the provided links using optional
	 * authentication and returns an array of media file contents.
	 *
	 * @param array $mms Array of media links from the Bandwidth payload.
	 * @return array Array of media file contents.
	 */
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
			if (empty($media_content)) {
				continue;
			}
			// Determine content type from the response or URL
			$content_type = $response['info']['content_type'] ?? 'application/octet-stream';
			// Strip charset suffix if present (e.g. "image/jpeg; charset=UTF-8")
			if (strpos($content_type, ';') !== false) {
				$content_type = trim(explode(';', $content_type)[0]);
			}
			$filename = basename(parse_url($media_link, PHP_URL_PATH)) ?: 'media';
			$media_files[] = [
				'content_type' => $content_type,
				'data'         => base64_encode($media_content),
				'url'          => $media_link,
				'filename'     => $filename,
				'size'         => strlen($media_content),
			];
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
		$y++;
		$defaults['default_settings'][$y]['default_setting_uuid'] = '60b148d1-5ea6-4561-909a-935bb4c99000';
		$defaults['default_settings'][$y]['default_setting_category'] = self::OPENSMS_PROVIDER_NAME;
		$defaults['default_settings'][$y]['default_setting_subcategory'] = 'api_token';
		$defaults['default_settings'][$y]['default_setting_name'] = 'text';
		$defaults['default_settings'][$y]['default_setting_value'] = '';
		$defaults['default_settings'][$y]['default_setting_enabled'] = 'false';
		$y++;
		$defaults['default_settings'][$y]['default_setting_uuid'] = 'c4b8d2e3-5f60-7890-1bcd-ef2345678901';
		$defaults['default_settings'][$y]['default_setting_category'] = self::OPENSMS_PROVIDER_NAME;
		$defaults['default_settings'][$y]['default_setting_subcategory'] = 'api_secret';
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
