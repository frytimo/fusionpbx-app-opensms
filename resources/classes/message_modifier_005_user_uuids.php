<?php

/**
 * Class message_modifier_000_user_uuids
 *
 * This class is responsible for populating the user UUIDs on an opensms_message
 * instance based on the destination number. It queries the database to find
 * the corresponding user UUIDs and assigns them to the message.
 */
class message_modifier_005_user_uuids implements opensms_message_modifier {
    /**
     * 	 * Populate the user UUID on the given opensms_message.
	 *
	 * Looks up the appropriate user identifier using the provided database instance
	 * and assigns that UUID to the supplied opensms_message object. This method
	 * mutates the $message object and does not return a value.
     *
     * @param settings $settings Settings object containing configuration and context.
	 * @param opensms_message $message The message object to update with the user's UUID.
     * @return void
     */
    public function modify(settings $settings, opensms_message $message): void {

        // Get the database connection from settings
        $database = $settings->database();

		// Look up the user_uuid based on the to_number (PSTN number)
		$sql = "select user_uuid from v_destinations where destination_number = :destination_number ";
		$parameters['destination_number'] = $message->to_number;
		$result = $database->select($sql, $parameters, 'row');
		if (!empty($result['user_uuid'])) {
			$user_uuid = $result['user_uuid'];
			if (is_uuid($user_uuid)) {
				$message->user_uuids[] = $user_uuid;
			}
		}
    }
}
