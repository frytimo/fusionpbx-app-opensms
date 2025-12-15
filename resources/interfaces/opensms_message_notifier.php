<?php

interface opensms_message_notifier {
    /**
     * Notify about an OpenSMS message event.
     *
     * Implementations should handle the provided opensms_message instance and perform any
     * necessary actions such as logging, alerting, or triggering workflows. This method is
     * expected to be non-blocking where possible; long-running tasks should be delegated to
     * background workers.
     *
     * @param settings        $settings The global settings object for configuration access.
     * @param opensms_message $message  The message to notify about.
     * 
     * @return void
     * @throws \InvalidArgumentException If the provided message is malformed or missing required data.
     * @throws \RuntimeException         If notification cannot be completed due to runtime errors (I/O, network, etc.).
     */
    public function send(settings $settings, opensms_message $message): void;
}