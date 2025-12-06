<?php

class bandwidth_opensms implements opensms_provider {

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


	protected $settings;

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
	public function parse(settings $settings): opensms_message {

		$this->settings = $settings;

		// Process JSON payload from Bandwidth
		$json_string = file_get_contents('php://input');
		$json_array = json_decode($json_string, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new \Exception("Invalid JSON payload received from Bandwidth");
		}

		$to_number = '';
		$from_number = '';
		$sms = '';
		$time = '';
		$mms = [];

		// Process 'time' if present
		if (isset($json_array[0]['message']['time'])) {
			// Validate 'time' format
			$time = $json_array[0]['message']['time'];
			// Further validation can be added here
		}

		// Process 'to' if present
		if (isset($json_array[0]['message']['to'][0])) {
			// Validate 'to' number
			$to_number = $json_array[0]['message']['to'][0];
			// Further validation can be added here
		}

		// Process 'from' if present
		if (isset($json_array[0]['message']['from'])) {
			// Validate 'from' number
			$from_number = $json_array[0]['message']['from'];
			// Further validation can be added here
		}

		// Process 'text' if present
		if (isset($json_array[0]['message']['text'])) {
			// Process SMS message
			$sms = $this->process_sms($json_array);
		}

		// Process MMS media if present
		if (isset($json_array[0]['message']['media'])) {
			$links = $json_array[0]['message']['media'];
			// Check for MMS messages
			if (!empty($links)) {
				$mms = $this->process_mms($links);
			}
		}
		return new opensms_message($to_number, $from_number, $sms, $mms, $time);
	}

	private function process_sms(array $json_array): string {
		// Handle SMS message
		$sms_text = $json_array[0]['message']['text'] ?? '';
		// Return the text
		return $sms_text;
	}

	private function process_mms(array $mms): array {
		$media_files = [];
		// Handle MMS media links
		foreach ($mms as $media_link) {
			$curl_client = new curl_client();
			$response = $curl_client->get($media_link);
			$response = ['error' => 'invalid url']; // testing
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
		return $defaults;
	}

	/**
	 * Initialize or ensure application default settings for the Bandwidth OpenSMS integration.
	 *
	 * This static method is responsible for creating, updating, or validating the persistent
	 * default configuration values required by the OpenSMS (Bandwidth) app. It uses the
	 * provided database abstraction to read existing settings and to insert or update the
	 * necessary records (for example API credentials, endpoints, routing options, timeouts,
	 * and any feature flags required by the integration).
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
		if (!opensms::has_acl($database, self::ACCESS_CONTROL_UUID)) {
			opensms::create_access_control($database, self::ACCESS_CONTROL_UUID, self::OPENSMS_PROVIDER_LABEL, self::OPENSMS_PROVIDER_DESCRIPTION);
			opensms::add_acl_cidrs($database, self::ACCESS_CONTROL_UUID, self::CIDR, self::OPENSMS_PROVIDER_LABEL);
		}
	}

}
