<?php

/**
 * Interface opensms_provider
 *
 * Contract for OpenSMS provider implementations used by FusionPBX.
 *
 * Implementations of this interface encapsulate integration with an SMS
 * gateway/provider and are expected to provide functionality such as:
 *  - sending SMS messages
 *  - querying delivery status for submitted messages
 *  - retrieving account information (balance, rates, limits)
 *  - configuring sender/credentials and handling provider-specific options
 *
 * Implementations should return consistent, well-documented response structures
 * and throw or surface provider-specific errors in a predictable manner so the
 * caller can handle transient failures, rate limiting, and permanent errors.
 *
 * Location: /var/www/fusionpbx/app/opensms/resources/interfaces/opensms_provider.php
 *
 * @package FusionPBX\OpenSMS
 * @subpackage Resources\Interfaces
 * @author FusionPBX
 * @since 1.0.0
 * @license See project LICENSE file
 * @link https://github.com/fusionpbx/fusionpbx
 */
interface opensms_provider {

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
