<?php

interface opensms_message_listener {
	/**
	 * Process an OpenSMS message.
	 *
	 * Implementations should handle the provided opensms_message instance and perform any
	 * necessary actions such as validation, routing, delivery, persistence, logging, or
	 * generating replies. This method is expected to be non-blocking where possible;
	 * long-running tasks should be delegated to background workers.
	 *
	 * @param settings        $settings The global settings object for configuration access.
	 * @param opensms_message $message  The message to process.
	 * 
	 * @return void
	 * @throws \InvalidArgumentException If the provided message is malformed or missing required data.
	 * @throws \RuntimeException         If processing cannot be completed due to runtime errors (I/O, network, etc.).
	 */
	public function on_message(settings $settings, opensms_message $message): void;
}
