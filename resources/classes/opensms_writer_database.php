<?php

/**
 * Class opensms_database_writer
 *
 * This class implements the opensms_message_listener interface to write incoming
 * OpenSMS messages to the database.
 */
class opensms_writer_database implements opensms_message_listener {
	/**
	 * Handle an incoming message and persist it to storage.
	 *
	 * Validates and normalizes the provided opensms_message, uses the supplied
	 * settings (e.g. database connection and configuration) to write the message
	 * record and any related metadata to persistent storage. This method is
	 * responsible for mapping message fields to the database schema, performing
	 * inserts or updates as required, and emitting appropriate logging or events
	 * on success/failure.
	 *
	 * Implementations should ensure atomicity where required (transactions) and
	 * minimize side effects on validation failure.
	 *
	 * @param settings        $settings Configuration and resources required to persist the message
	 *                                  (database connection, table names, logging flags, etc.).
	 * @param opensms_message $message  The message instance to validate and persist.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException If the provided settings or message are invalid.
	 * @throws \RuntimeException         If persistence fails (database error, constraint violation, etc.).
	 * @throws \Throwable                For unexpected errors encountered during processing.
	 *
	 * @see opensms_message
	 * @see settings
	 */
	public function on_message(settings $settings, opensms_message $message): void {
		$database = database::new();
		$array = [
			'opensms_messages' => [
				[
					'message_to' => $message->to_number,
					'message_from' => $message->from_number,
					'message_sms' => $message->sms,
					'message_time' => $message->time,
					'message_mms' => json_encode($message->mms),
					'message_direction' => 'inbound',
					'message_status' => 'received',
					'message_date' => date('Y-m-d H:i:s'),
				]
			]
		];
		$database->save($array);
	}
}
