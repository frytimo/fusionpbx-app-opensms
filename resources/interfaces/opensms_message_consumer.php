<?php

/**
 * OpenSMS Consumer Interface
 *
 * Consumers are plugins that provide raw input data for adapters to parse.
 * Implementations should return a string containing the raw payload (for
 * example the HTTP request body, a message from a queue, or contents read
 * from a file). Return null or an empty string when there is nothing to
 * consume for the current request.
 */
interface opensms_message_consumer {

    /**
     * Consume and return raw data for processing by adapters.
     *
     * @param settings $settings The global settings object.
     * @return string|null Raw payload string, or null when no payload available.
     */
    public function __invoke(settings $settings): ?string;

}
