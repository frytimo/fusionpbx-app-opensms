<?php

/**
 * Lightweight payload object passed from consumers to adapters.
 */
class opensms_consumer_payload {

    /** @var string */
    protected $raw;

    /** @var array|null */
    protected $json;

    public function __construct(?string $raw) {
        $this->raw = $raw ?? '';
        $this->json = null;
        if ($this->raw !== '') {
            $this->json = json_decode($this->raw, true);
        }
    }

    public function raw(): string {
        return $this->raw;
    }

    public function json(): ?array {
        return $this->json;
    }

    public function is_empty(): bool {
        return $this->raw === '';
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
