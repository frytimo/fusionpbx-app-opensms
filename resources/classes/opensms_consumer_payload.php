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

}
