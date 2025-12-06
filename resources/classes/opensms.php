<?php

class opensms {

	public static function has_acl(database $database, string $uuid): bool {
		$access_controls = $database->select('select count(access_control_uuid) from v_access_controls where access_control_uuid = :access_control_uuid', ['access_control_uuid' => $uuid], 'column');
		return !empty($access_controls);
	}

	public static function has_acl_by_name(database $database, string $name): bool {
		$access_controls = $database->select('select count(access_control_uuid) from v_access_controls where access_control_name = :access_control_name', ['access_control_name' => $name], 'column');
		return !empty($access_controls);
	}

	public static function add_acl_cidrs(database $database, string $access_control_uuid, array $cidrs, string $description): void {
		foreach ($cidrs as $cidr) {
			$array['access_control_nodes'][] = [
				'access_control_node_uuid' => uuid(),
				'access_control_uuid' => $access_control_uuid,
				'node_cidr' => $cidr,
				'node_type' => 'allow',
				'node_description' => $description,
			];
		}
		$database->save($array);
	}

	/**
	 * Create a new access control entry for the OpenSMS module.
	 *
	 * Inserts a record representing an access control entry into the database.
	 *
	 * @param database $database            Database connection/helper used to perform the insert.
	 * @param string   $access_control_uuid The UUID of the access control to be created.
	 * @param string   $name                Unique name for the access control entry.
	 * @param string   $description         Human-readable description of the access control entry.
	 *
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

	public static function get_cidrs(database $database, string $node_uuid): array {
		$cidrs = [];
		$sql = 'select access_control_node_uuid, node_cidr from v_access_control_nodes where access_control_uuid = :access_control_uuid';
		$result = $database->select($sql, ['access_control_uuid' => $node_uuid], 'all');
		if (!empty($result)) {
			$cidrs = array_column($result, 'node_cidr', 'access_control_node_uuid');
		}
		return $cidrs;
	}

	public static function messages(array $providers, settings $settings): array {
		$messages = [];
		// Iterate through each provider class
		/** @var opensms_provider $provider_class */
		foreach ($providers as $provider_class) {
			// Process any request that is valid for the provider
			if ($provider_class::has($settings, $_SERVER['REMOTE_ADDR'])) {
				// Create the provider instance
				/** @var opensms_provider $provider */
				$provider = new $provider_class();

				// Call the provider to parse the message
				$message = $provider->parse($settings);

				// Providers must make sure to return to_number and from_number
				if (empty($message->to_number) || empty($message->from_number)) {
					// Parse error, skip to next provider
					continue;
				}

				// Do not exit the loop, in case multiple providers match
			}
		}
		return $messages;
	}
	
	/**
	 * Notify registered listeners about an opensms_message.
	 *
	 * Dispatches the provided opensms_message to each listener in the $listeners
	 * array. Each listener is expected to be a callable or an object implementing
	 * the appropriate listener contract and will be invoked with the message as
	 * its argument. Listeners are invoked in the order they appear in the array.
	 *
	 * @param opensms_message $message The message to be delivered to listeners.
	 * @param array $listeners Array of listeners to notify. Each element should be
	 *                         a callable or an object implementing the listener
	 *                         interface/contract.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException If $listeners contains invalid entries.
	 * @throws \Throwable If a listener throws an exception, it may be propagated
	 *                    to the caller.
	 */
	public static function notify(array $listeners, settings $settings, opensms_message $message): void {
		foreach ($listeners as $class_name) {
			/** @var opensms_listener $listener */
			$listener = new $class_name();
			if ($listener instanceof opensms_listener) {
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
