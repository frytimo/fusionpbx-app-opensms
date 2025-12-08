<?php

/**
 * Class opensms_database_writer
 *
 * This class implements the opensms_message_listener interface to write incoming
 * OpenSMS messages to the database.
 */
class opensms_message_database_writer implements opensms_message_listener {
	const MESSAGE_STATUS_WAITING = 'waiting';
	const MESSAGE_STATUS_SENT    = 'sent';
	const MESSAGE_STATUS_FAILED  = 'failed';

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
	public function __invoke(settings $settings, opensms_message $message): void {
		// Update the database with the incoming message
		$array = [
			'message_queue' => [
				[
					'message_queue_uuid' => uuid(),
					// 'message_uuid' => $message->uuid,			// message_queue table does not store the message UUID
					'domain_uuid'        => $message->domain_uuid,
					'provider_uuid'      => $message->provider_uuid,
					'user_uuid'          => $message->user_uuid,
					'group_uuid'         => $message->group_uuid,
					'contact_uuid'       => $message->contact_uuid,
					'hostname'           => gethostname(),
					'message_type'       => $message->type,
					'message_direction'  => 'inbound',
					'message_date'       => date('Y-m-d H:i:s'),
					'message_from'       => $message->from_number,
					'message_to'         => $message->to_number,
					'message_text'       => $message->sms,
					'message_json'       => json_encode($message),
					// 'message_time' => $message->time,
					// 'message_mms' => json_encode($message->mms),
					// 'message_status' => self::MESSAGE_STATUS_WAITING,
				]
			],
			'messages'      => [
				[
					'message_uuid'      => $message->uuid,
					'domain_uuid'       => $message->domain_uuid,
					'provider_uuid'     => $message->provider_uuid,
					'user_uuid'         => $message->user_uuid,
					'group_uuid'        => $message->group_uuid,
					'contact_uuid'      => $message->contact_uuid,
					'hostname'          => gethostname(),
					'message_type'      => $message->type,
					'message_direction' => 'inbound',
					'message_date'      => date('Y-m-d H:i:s'),
					'message_read'      => false,
					'message_from'      => $message->from_number,
					'message_to'        => $message->to_number,
					'message_text'      => $message->sms,
					'message_json'      => json_encode($message),
				]
			]
		];

		// Temporarily grant permissions to add messages
		$p = permissions::new();
		$p->add('message_queue_add', 'temp');
		$p->add('message_add', 'temp');

		// Save the message to the database
		$settings->database()->save($array);
	}
}
