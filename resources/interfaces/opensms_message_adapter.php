<?php

/**
 * OpenSMS Adapter Interface
 */
interface opensms_message_adapter {

	/**
	 * Parse provider input into an opensms_message instance.
	 *
	 * Implementations should extract message data (for example: sender, recipient,
	 * message body, timestamps, attachments and any provider-specific metadata)
	 * from the supplied settings object and return a populated opensms_message.
	 *
	 * If the supplied settings do not represent a valid or supported message
	 * (for example, verification callbacks, non-message events, or unparseable
	 * payloads), implementations should return null.
	 *
	 * @param settings $settings Settings object containing configuration.
	 * 
	 * @return opensms_message|null The parsed message object, or null if the input
	 *                              does not contain a valid/supported message.
	 * 
	 * @throws \InvalidArgumentException If required fields are missing or of an
	 *                                   unexpected type.
	 * @throws \RuntimeException         For unrecoverable parsing/decoding errors.
	 */
	public function parse(settings $settings): ?opensms_message;

	/**
	 * Determine if the provider should handle the request based on the IP address.
	 *
	 * This static method checks if the given IP address matches the criteria defined
	 * by the provider (e.g., falls within a specific CIDR range). It allows the
	 * system to route incoming requests to the appropriate SMS provider based on
	 * their source IP address.
	 *
	 * @param settings $settings Configuration container providing options for this instance.
	 * @param string $ip_address The IP address of the incoming request to evaluate.
	 * 
	 * @return bool True if the provider should handle the request, false otherwise.
	 * 
	 * @throws \TypeError If a non-settings value is passed (enforced by the method signature).
	 */
	public static function has(settings $settings, string $ip_address): bool;

	/**
	 * Hook in to the app_defaults
	 *
	 * @return void
	 */
	public static function app_defaults(database $database): void;

	/**
	 * Hook in to the app_config
	 *
	 * @return array|null
	 */
	public static function app_config(): ?array;
}
