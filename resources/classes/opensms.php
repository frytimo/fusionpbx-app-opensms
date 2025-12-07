<?php

class opensms {
	/**
	 * Check if the current user has ACL permissions for a specific message
	 *
	 * @param database $database The database instance used to query permissions
	 * @param string $uuid The unique identifier of the message to check ACL for
	 * @return bool Returns true if the user has ACL permissions, false otherwise
	 */
	public static function has_acl(database $database, string $uuid): bool {
		$access_controls = $database->select('select count(access_control_uuid) from v_access_controls where access_control_uuid = :access_control_uuid', ['access_control_uuid' => $uuid], 'column');
		return !empty($access_controls);
	}

	/**
	 * Checks if the user has access control list (ACL) permission by name
	 *
	 * @param database $database The database instance used for querying ACL permissions
	 * @param string $name The name of the ACL permission to check
	 * @return bool Returns true if the user has the specified ACL permission, false otherwise
	 */
	public static function has_acl_by_name(database $database, string $name): bool {
		$access_controls = $database->select('select count(access_control_uuid) from v_access_controls where access_control_name = :access_control_name', ['access_control_name' => $name], 'column');
		return !empty($access_controls);
	}

	/**
	 * Add multiple CIDR blocks to an access control list
	 *
	 * @param database $database The database instance to perform operations on
	 * @param string $access_control_uuid The UUID of the access control list to add CIDRs to
	 * @param array $cidrs An array of CIDR notation IP address ranges to be added
	 * @param string $description A description for the CIDR entries being added
	 * @return void
	 */
	public static function create_access_control(database $database, string $access_control_uuid, string $name, string $description): void {
		$array['access_controls'][] = [
			'access_control_uuid' => $access_control_uuid,
			'access_control_name' => $name,
			'access_control_default' => 'deny',
			'access_control_description' => $description,
		];
		$database->save($array);
	}

	/**
	 * Retrieves CIDR (Classless Inter-Domain Routing) blocks associated with a specific access control UUID
	 *
	 * @param database $database The database instance used to query CIDR information
	 * @param string $access_control_uuid The unique identifier for the access control entry
	 * @return array An array of CIDR blocks associated with the specified access control UUID
	 */
	public static function get_cidrs(database $database, string $access_control_uuid): array {
		$cidrs = [];
		$sql = 'select access_control_node_uuid, node_cidr from v_access_control_nodes where access_control_uuid = :access_control_uuid';
		$result = $database->select($sql, ['access_control_uuid' => $access_control_uuid], 'all');
		if (!empty($result)) {
			$cidrs = array_column($result, 'node_cidr', 'access_control_node_uuid');
		}
		return $cidrs;
	}

	/**
	 * Add CIDR blocks to an access control list
	 *
	 * @param database $database            The database connection instance
	 * @param string   $access_control_uuid The UUID of the access control list to add CIDRs to
	 * @param array    $cidrs               Array of CIDR notation IP address ranges to be added
	 * @param string   $description         Description for the CIDR entries being added
	 * @return void
	 *
	 * @throws \InvalidArgumentException If $listeners contains invalid entries.
	 * @throws \Throwable                If a listener throws an exception, it may be propagated
	 *                                   to the caller.
	 */
	public static function add_acl_cidrs(database $database, string $access_control_uuid, array $cidrs, string $description): void {
		foreach ($cidrs as $cidr) {
			$array['access_control_nodes'][] = [
				'access_control_node_uuid' => uuid(),
				'access_control_uuid' => $access_control_uuid,
				'node_type' => 'allow',
				'node_cidr' => $cidr,
				'node_description' => $description,
			];
		}

		$database->save($array);
	}

	/**
	 * Retrieves messages from multiple SMS adapters
	 *
	 *
	 *
	 *
	 *
	 * @param array $adapters An array of SMS adapter instances to query for messages
	 *
	 *
	 * @param settings $settings The settings object containing configuration parameters
	 *
	 *
	 * @return array Returns an array of messages retrieved from all adapters
	 */
	public static function messages(array $adapters, settings $settings): array {
		$messages = [];
		// Iterate through each adapter class
		/** @var opensms_message_adapter $adapter_class */
		foreach ($adapters as $adapter_class) {
			// Process any request that is valid for the adapter
			if ($adapter_class::has($settings, $_SERVER['REMOTE_ADDR'])) {
				// Create the adapter instance
				/** @var opensms_message_adapter $adapter */
				$adapter = new $adapter_class();
				// Call the adapter to parse the message
				$message = $adapter->parse($settings);
				// adapters must make sure to return to_number and from_number
				if (empty($message->to_number) || empty($message->from_number)) {
					// Parse error, skip to next adapter
					continue;
				}
				// Do not exit the loop, in case multiple adapters match
				$messages[] = $message;
			}
		}
		return $messages;
	}

	/**
	 * Notifies all listeners with the opensms_message object
	 *
	 * This method should be called after the message modifiers to ensure there is a complete message
	 *
	 * @param array $listeners
	 * @param settings $settings
	 * @param opensms_message $message
	 * @return void
	 */
	public static function notify(array $listeners, settings $settings, opensms_message $message): void {
		foreach ($listeners as $class_name) {
			/** @var opensms_message_listener $listener */
			$listener = new $class_name();
			if ($listener instanceof opensms_message_listener) {
				$listener->on_message($settings, $message);
			}
		}
	}

	/**
	 * Modify an OpenSMS message using the provided modifiers and settings.
	 *
	 * Applies each modifier in $modifiers (in the order provided) to the given $message
	 * instance. Modifications may include changing the message body, recipients,
	 * metadata, delivery flags, templates, or other message properties. The method
	 * mutates the $message object in-place and does not return a value.
	 *
	 * Expected modifier forms (implementation-dependent, examples):
	 *  - callable: function(settings $settings, opensms_message $message): void
	 *  - associative array describing the change (e.g. ['field' => 'body', 'action' => 'append', 'value' => '...'])
	 *  - string key referencing a predefined modifier
	 *
	 * Order of execution matters: later modifiers can override or augment earlier ones.
	 *
	 * @param array $modifiers   Array of modifier definitions (callables, arrays, or keys).
	 * @param settings $settings Configuration/settings object used when applying modifiers.
	 * @param opensms_message $message The message object to be modified (mutated in-place).
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException If a modifier has an invalid format or unsupported type.
	 * @throws \RuntimeException If a modifier fails during processing.
	 */
	public static function modify(array $modifiers, settings $settings, opensms_message $message): void {
		// Sort to ensure dependency order
		sort($modifiers);

		// Call each modifier to edit the message
		foreach ($modifiers as $modifier_class) {
			/** @var opensms_message_modifier $modifier */
			$modifier = new $modifier_class();
			if ($modifier instanceof opensms_message_modifier) {
				$modifier->modify($settings, $message);
			}
		}
	}
}
