<?php

/**
 * Simple HTTP consumer that returns `php://input` as the raw payload.
 *
 * This is an example implementation of `opensms_message_consumer`. Real
 * deployments can add queue consumers, file consumers, or more advanced
 * HTTP consumers that validate signatures and authentication before
 * returning the payload.
 */
class opensms_http_input_consumer implements opensms_message_consumer {

    /**
     * Return the HTTP request body, or null if empty.
     *
     * @param settings $settings
     * @return string|null
     */
    public function __invoke(settings $settings): ?string {
        $raw = file_get_contents('php://input');
        return ($raw === false || $raw === '') ? null : $raw;
    }


	/**
	 * Hook in to the app_defaults
	 *
	 * @return void
	 */
	public static function app_defaults(database $database): void {}

	/**
	 * Hook in to the app_config
	 *
	 * @return array|null
	 */
	public static function app_config(): ?array { return null; }

	/**
	 * Hook in to the app_menu
	 *
	 * @return array|null
	 */
	public static function app_menu(): ?array { return null; }
}
