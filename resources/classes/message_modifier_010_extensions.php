<?php

/**
 * Class message_modifier_010_extensions
 *
 * This class implements the opensms_message_modifier interface to resolve and assign
 * extensions for user UUIDs referenced by an opensms_message.
 */
class message_modifier_010_extensions implements opensms_message_modifier {
	/**
	 * Resolve and assign extensions for user UUIDs referenced by an opensms_message.
	 *
	 * This static method looks up extension information for the user UUIDs present in the
	 * provided $message using the given $database connection/helper. It updates/mutates the
	 * $message object with the resolved extension data and returns an associative mapping
	 * of user UUIDs to their resolved extension (or extension detail array).
	 *
	 * Side effects:
	 * - Mutates properties on the supplied opensms_message instance to include extension data.
	 *
	 * Parameters:
	 * @param settings        $settings Settings object providing configuration and context.
	 * @param opensms_message $message  Message instance containing user UUIDs to resolve; will be updated with extensions.
	 *
	 * Errors:
	 * @throws \Exception If required data is missing or an application-level error occurs during processing.
	 *                   Database-related errors during lookup may also be propagated as exceptions.
	 */
	public function modify(settings $settings, opensms_message $message): void {

        // Get the database connection from settings
        $database = $settings->database();

		$extensions = [];
		if (!empty($message->user_uuids)) {
			foreach ($message->user_uuids as $user_uuid) {
				$sql = "select * from v_domains as d, v_extensions as e ";
				$sql .= "where extension_uuid in ( ";
				$sql .= "	select extension_uuid ";
				$sql .= "	from v_extension_users ";
				$sql .= "	where user_uuid = :user_uuid ";
				$sql .= ") ";
				$sql .= "and e.domain_uuid = d.domain_uuid ";
				$sql .= "and e.enabled = 'true' ";
				$parameters['user_uuid'] = $user_uuid;
				$user_extensions = $database->select($sql, $parameters, 'all');
				if (!empty($user_extensions)) {
					$extensions = array_merge($extensions, $user_extensions);
				}
			}
            $message->extensions = $extensions;
		}
		return;
	}
}
