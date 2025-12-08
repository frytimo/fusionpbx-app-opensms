<?php

/**
 * Class message_modifier_000_user_uuids
 *
 * This class is responsible for populating the UUIDs on an opensms_message
 * instance based on the destination number. It queries the database to find
 * the corresponding destination, user, and group UUIDs and assigns them to the message.
 */
class modifier_add_uuids implements opensms_message_modifier {
	/**
	 * Populate the user UUID on the given opensms_message.
	 *
	 * Looks up the appropriate user identifier using the provided database instance
	 * and assigns that UUID to the supplied opensms_message object. This method
	 * mutates the $message object and does not return a value.
	 *
	 * @param settings $settings Settings object containing configuration and context.
	 * @param opensms_message $message The message object to update with the user's UUID.
	 * @return void
	 */
	public function __invoke(settings $settings, opensms_message $message): void {

		// Get the database connection from settings
		$database = $settings->database();

		// Look up the destination_uuid, user_uuid, and group_uuid based on the to_number (PSTN number)
		$sql = "SELECT destination_uuid, user_uuid, group_uuid, domain_uuid FROM v_destinations ";
		$sql .= "WHERE ( ";
		$sql .= "	destination_prefix || destination_area_code || destination_number = :destination_number ";
		$sql .= "	OR destination_trunk_prefix || destination_area_code || destination_number = :destination_number ";
		$sql .= "	OR destination_prefix || destination_number = :destination_number ";
		$sql .= "	OR '+' || destination_prefix || destination_number = :destination_number ";
		$sql .= "	OR '+' || destination_prefix || destination_area_code || destination_number = :destination_number ";
		$sql .= "	OR destination_area_code || destination_number = :destination_number ";
		$sql .= "	OR destination_number = :destination_number ";
		$sql .= ") ";
		$sql .= "and destination_enabled = true; ";
		$parameters['destination_number'] = $message->to_number;
		$parameters['destination_number'] = '19022012170';
		$result = $database->select($sql, $parameters, 'row');

		// Return a rejection to the provider here
		if ($result === false) {
			//opensms::send_reject($message);
		}

		// Set the required UUIDs
		$destination_uuid = $result['destination_uuid'] ?? null;
		if (!empty($destination_uuid) && is_uuid($destination_uuid)) {
			$message->destination_uuid = $destination_uuid;
		}
		$user_uuid = $result['user_uuid'] ?? null;
		if (!empty($user_uuid)) {
			$message->user_uuids = [$user_uuid];
		}
		$group_uuid = $result['group_uuid'] ?? null;
		if (!empty($group_uuid) && is_uuid($group_uuid)) {
			$message->group_uuid = $group_uuid;
		}
		$domain_uuid = $result['domain_uuid'] ?? null;
		if (!empty($domain_uuid) && is_uuid($domain_uuid)) {
			$message->domain_uuid = $domain_uuid;
		}

	}

	public function priority(): int {
		return 5; // Priority after removing plus
	}
}
