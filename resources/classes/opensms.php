<?php

class opensms {
	/**
	 * Check if the current user has ACL permissions for a specific message
	 *
	 * @param database $database The database instance used to query permissions
	 * @param string   $uuid     The unique identifier of the message to check ACL for
	 *
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
	 * @param string   $name     The name of the ACL permission to check
	 *
	 * @return bool Returns true if the user has the specified ACL permission, false otherwise
	 */
	public static function has_acl_by_name(database $database, string $name): bool {
		$access_controls = $database->select('select count(access_control_uuid) from v_access_controls where access_control_name = :access_control_name', ['access_control_name' => $name], 'column');

		return !empty($access_controls);
	}

	/**
	 * Add multiple CIDR blocks to an access control list
	 *
	 * @param database $database            The database instance to perform operations on
	 * @param string   $access_control_uuid The UUID of the access control list to add CIDRs to
	 * @param array    $cidrs               An array of CIDR notation IP address ranges to be added
	 * @param string   $description         A description for the CIDR entries being added
	 *
	 * @return void
	 */
	public static function create_access_control(database $database, string $access_control_uuid, string $name, string $description): void {
		$array['access_controls'][] = [
			'access_control_uuid'        => $access_control_uuid,
			'access_control_name'        => $name,
			'access_control_default'     => 'deny',
			'access_control_description' => $description,
		];
		$database->save($array);
	}

	/**
	 * Retrieves CIDR (Classless Inter-Domain Routing) blocks associated with a specific access control UUID
	 *
	 * @param database $database            The database instance used to query CIDR information
	 * @param string   $access_control_uuid The unique identifier for the access control entry
	 *
	 * @return array An array of CIDR blocks associated with the specified access control UUID
	 */
	public static function get_cidrs(database $database, string $access_control_uuid): array {
		$cidrs  = [];
		$sql    = 'select access_control_node_uuid, node_cidr from v_access_control_nodes where access_control_uuid = :access_control_uuid';
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
				'access_control_uuid'      => $access_control_uuid,
				'node_type'                => 'allow',
				'node_cidr'                => $cidr,
				'node_description'         => $description,
			];
		}

		$database->save($array);
	}

	/**
	 * Retrieves messages from multiple SMS adapters
	 *
	 * @param array    $adapters An array of SMS adapter instances to query for messages
	 * @param settings $settings The settings object containing configuration parameters
	 *
	 * @return array Returns an array of messages retrieved from all adapters
	 */
	public static function messages(array $adapters, settings $settings, ?object $payload = null): array {
		$messages = [];

		// Ensure we always pass a payload object to adapters (may be empty).
		if ($payload === null) {
			$payload = new opensms_consumer_payload('');
		}
		// Iterate through each adapter class
		/** @var opensms_message_adapter $adapter_class */
		foreach ($adapters as $adapter_class) {
			// Process any request that is valid for the adapter
			if ($adapter_class::has($settings, $_SERVER['REMOTE_ADDR'])) {
				// Create the adapter instance
				/** @var opensms_message_adapter $adapter */
				$adapter = new $adapter_class();

				// Call the adapter to parse the message (may return null if adapter has nothing to process)
				$message = $adapter->receive($settings, $payload);
				if ($message === null) {
					// Adapter had no message to process
					continue;
				}

				// Populate standard fields
				$message->to_number   = $adapter->get_to_number();
				$message->from_number = $adapter->get_from_number();
				$message->time        = $adapter->get_time();
				$message->sms         = $adapter->get_sms();
				$message->mms         = $adapter->get_mms();
				$message->type        = $adapter->get_type();

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
	 * Broadcasts an event to all connected clients or subscribers
	 *
	 * @param mixed $event The event data to be broadcast
	 *
	 * @return void
	 */
	public static function broadcast_event($event): void {
		// Set globals
		/** @var settings $settings */
		global $settings;

		// Create a new auto loader with cache disabled
		$auto_loader = new auto_loader(true);
		$auto_loader->reload_classes();

		// Discover all components
		$routers   = $auto_loader->get_interface_list('opensms_message_router');
		$adapters  = $auto_loader->get_interface_list('opensms_message_adapter');
		$modifiers = $auto_loader->get_interface_list('opensms_message_modifier');
		$listeners = $auto_loader->get_interface_list('opensms_message_listener');

		// Create a message from the event
		$message = self::create_message_from_switch_event($settings, $event);

		if ($message === null) {
			return;
		}

		// Build modifier chain and apply
		$modify = self::build_modifier_chain($modifiers);
		$modify($settings, $message);

		// Send outbound via router → adapter
		$success = self::send($routers, $adapters, $settings, $message);

		if ($success) {
			opensms_message::notify($listeners, $settings, $message);
		}
	}

	/**
	 * Send an outbound message via the appropriate provider.
	 *
	 * Uses routers to find the correct adapter from discovered adapters, then calls send().
	 *
	 * @param array           $routers  List of router class names.
	 * @param array           $adapters List of adapter class names.
	 * @param settings        $settings Configuration settings.
	 * @param opensms_message $message  Opensms message to send.
	 *
	 * @return bool True if sent successfully.
	 */
	public static function send(array $routers, array $adapters, settings $settings, opensms_message $message): bool {
		foreach ($adapters as $adapter_class) {
			/** @var opensms_message_adapter $adapter */
			if ($adapter_class::has_destination($settings, $message)) {
				return $adapter_class::send($settings, $message);
			}
		}
		return false;
	}

	/**
	 * Create an opensms_message from a switch event with full routing info.
	 *
	 * @param settings      $settings The settings object containing configuration parameters
	 * @param event_message $event    The switch event message object
	 *
	 * @return opensms_message|null
	 */
	public static function create_message_from_switch_event(settings $settings, $event): ?opensms_message {
		$database = $settings->database();

		// Extract extension and domain from event
		$from_parts  = explode('@', $event->from);
		$extension   = $from_parts[0] ?? '';
		$domain_name = $from_parts[1] ?? '';

		// Create message - provider_uuid will be resolved by router
		$message = new opensms_message(uuid(), '');
		$message->from_number = $extension;
		$message->to_number   = $event->to_user ?? '';
		$message->sms         = $event->body();
		$message->type        = 'sms';
		$message->domain_name  = $domain_name;

		return $message;
	}

	/**
	 * Builds a chain of message consumers using the Chain of Responsibility pattern
	 *
	 * Takes an array of consumer instances and links them together so that each
	 * consumer can process a message and optionally pass it to the next consumer
	 * in the chain.
	 *
	 * @param array $consumers An array of opensms_message_consumer instances to be chained together
	 *
	 * @return opensms_message_consumer The first consumer in the chain, which serves as the entry point
	 */
	public static function build_consumer_chain(array $consumers): opensms_message_consumer {
		$instances = [];

		// Validate consumer classes
		foreach ($consumers as $class) {
			$instance = new $class();
			if (!($instance instanceof opensms_message_consumer)) {
				throw new InvalidArgumentException("Consumer class $class does not implement opensms_message_consumer");
			}
			$instances[] = $instance;
		}

		$chain = new class($instances) implements opensms_message_consumer {
			private $consumers;

			public function __construct(array $consumers) {
				$this->consumers = $consumers;
			}

			public function __invoke(settings $settings): ?string {
				foreach ($this->consumers as $consume) {
					$payload = $consume($settings);
					if (!empty($payload)) {
						return $payload;
					}
				}

				return null;
			}
		};

		return $chain;
	}

	/**
	 * Builds a chain of message modifiers from an array of modifier configurations
	 *
	 * This method creates a chain of responsibility pattern implementation for message
	 * modification. Each modifier in the chain can process the message and pass it to
	 * the next modifier.
	 *
	 * @param array $modifiers An array of modifier configurations used to build the chain
	 *
	 * @return opensms_message_modifier The first modifier in the chain, which can be used
	 *                                   to process messages through the entire chain
	 */
	public static function build_modifier_chain(array $modifiers): opensms_message_modifier {
		$instances = [];

		// Validate modifier classes
		foreach ($modifiers as $class) {
			$instance = new $class();
			if (!($instance instanceof opensms_message_modifier)) {
				throw new InvalidArgumentException("Modifier class $class does not implement opensms_message_modifier");
			}
			$instances[] = $instance;
		}

		// Sort by priority (lower = earlier)
		usort($instances, function ($a, $b) {
			return $a->priority() <=> $b->priority();
		});

		$chain = new class($instances) implements opensms_message_modifier {
			private $modifiers;

			public function __construct(array $modifiers) {
				$this->modifiers = $modifiers;
			}

			public function __invoke(settings $settings, opensms_message $message): void {
				foreach ($this->modifiers as $modifier) {
					$modifier($settings, $message);
				}
			}

			public function priority(): int {
				return -1;  // build chain has to run before any other modifier
			}
		};

		return $chain;
	}

	/**
	 * Builds a chain of message listeners based on the provided listeners
	 *
	 * This method creates and configures a chain of opensms_message_listener objects
	 * according to the listeners array. Each listener in the array is used to construct
	 * and link listeners in a chain of responsibility pattern.
	 *
	 * @param array $listeners An array of listener configurations used to build the listener chain
	 *
	 * @return opensms_message_listener The first listener in the constructed listener chain
	 */
	public static function build_listener_chain(array $listeners): opensms_message_listener {
		$instances = [];

		// Validate modifier classes
		foreach ($listeners as $class) {
			$instance = new $class();
			if (!($instance instanceof opensms_message_listener)) {
				throw new InvalidArgumentException("Modifier class $class does not implement opensms_message_listener");
			}
			$instances[] = $instance;
		}

		$chain = new class($instances) implements opensms_message_listener {
			private $listeners;

			public function __construct(array $listeners) {
				$this->listeners = $listeners;
			}

			public function __invoke(settings $settings, opensms_message $message): void {
				foreach ($this->listeners as $listener) {
					$listener($settings, $message);
				}
			}
		};

		return $chain;
	}

	/**
	 * Reverses a phone number and returns an array of its prefixes
	 *
	 * This method takes a phone number as input, reverses it, and generates
	 * an array containing all possible prefixes of the reversed number.
	 * The prefixes are ordered from longest to shortest.
	 *
	 * Sending a number like "1234567890" would return:
	 * [
	 *  "0987654321",
	 *  "098765432",
	 *  "09876543",
	 *  "0987654",
	 *  "098765",
	 *  "09876",
	 *  "0987",
	 *  "098",
	 *  "09",
	 *  "0"
	 * ]
	 *
	 * If length is specified, only prefixes of at least that length are returned. So, if length
	 * is 5, the above example would return:
	 * [
	 * "0987654321",
	 * "098765432",
	 * "09876543",
	 * "0987654",
	 * "098765",
	 * "09876"
	 * ]
	 *
	 * @param string $number The phone number to be reversed and processed
	 * @param int    $length The smallest length of the phone number to return prefixes for
	 *
	 * @return array An array of prefixes derived from the reversed phone number
	 */
	public static function reverse_number_as_array(string $number, ?int $length = null): array {
		$prefixes        = [];
		$reversed_number = strrev($number);
		$length          = $length ?? strlen($number);
		if ($length >= strlen($number)) {
			return [$reversed_number];
		}
		$count = strlen($reversed_number) - $length;
		for ($i = strlen($reversed_number); $i >= $count; $i--) {
			$prefixes[] = substr($reversed_number, 0, $i);
		}

		return $prefixes;
	}
}
