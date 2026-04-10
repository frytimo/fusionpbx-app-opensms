<?php

class opensms_service extends base_websocket_system_service {

	/** @var settings */
	private $settings;

	/** @var resource|false */
	private $switch_socket;

	/** @var event_socket */
	private $event_socket;

	/** @var filter */
	private $event_filter;

	const SWITCH_EVENTS = [
		['Event-Name' => 'MESSAGE'],
	];

	const EVENT_KEYS = [
		'event_name',
		'login',
		'from',
		'from_user',
		'to_user',
		'to_host',
		'from_sip_ip',
		'from_sip_port',
		'to',
		'SUBJECT',
		'context',
		'from_full',
		'sip_profile',
		'content_length',
		'_body',
	];

	/**
	 * Returns the service name for opensms.
	 *
	 * This method provides a unique identifier for the opensms service.
	 *
	 * @return string The service name as a string.
	 */
	public static function get_service_name(): string {
		return "opensms";
	}

	/**
	 * Reloads settings from database, config file and websocket server.
	 *
	 * @return void
	 */
	protected function reload_settings(): void {
		// re-read the config file to get any possible changes
		parent::$config->read();

		$this->debug('Reloading settings for opensms service');

		// register the event listener for the switch events
		if ($this->connect_to_event_socket()) {
			$this->register_event_socket_filters();
		}

		// re-connect to the websocket server if required
		if ($this->connect_to_ws_server()) {
			$this->debug('Successfully connected to websocket server');
		} else {
			$this->error('Failed to connect to websocket server');
		}
	}

	/**
	 * Registers topics for broadcasting opensms information.
	 *
	 * This method is responsible for setting up any topics and callbacks
	 * for the opensms service. It is only called once during initial startup.
	 *
	 * @return void
	 */
	protected function register_topics(): void {
		// Create a filter so the switch events are filtered for opensms only
		$this->event_filter = filter_chain::or_link([new event_key_filter(self::EVENT_KEYS)]);

		// Register websocket topics
		$this->on_topic('send', [$this, 'handle_send_request']);
		$this->on_topic('history', [$this, 'handle_history_request']);
		$this->on_topic('mark_read', [$this, 'handle_mark_read_request']);
		$this->on_topic('ping', [$this, 'handle_ping_request']);
		$this->on_topic('block_number', [$this, 'handle_block_number_request']);
		$this->on_topic('unblock_number', [$this, 'handle_unblock_number_request']);
		$this->on_topic('list_blocked', [$this, 'handle_list_blocked_request']);
		$this->on_topic('hide_thread', [$this, 'handle_hide_thread_request']);
		$this->on_topic('unhide_thread', [$this, 'handle_unhide_thread_request']);
		$this->on_topic('list_hidden', [$this, 'handle_list_hidden_request']);

		// get the settings from the global defaults
		$this->reload_settings();
	}

	/**
	 * Handle ping request (keep-alive)
	 *
	 * @param websocket_message $websocket_message
	 */
	protected function handle_ping_request(websocket_message $websocket_message): void {
		$response = new websocket_message();
		$response->service_name(self::get_service_name());
		$response->topic('pong');
		$response->status_string('ok');
		$response->status_code(200);
		$response->request_id($websocket_message->request_id());
		$response->resource_id($websocket_message->resource_id());
		$response->payload(['time' => time()]);
		$this->respond($response);
	}

	/**
	 * Handle history request from clients
	 *
	 * @param websocket_message $websocket_message
	 */
	protected function handle_history_request(websocket_message $websocket_message): void {
		$this->debug('Handling history request');
		$this->debug('Request ID: ' . $websocket_message->request_id());
		$this->debug('Resource ID: ' . $websocket_message->resource_id());

		try {
			$payload = $websocket_message->payload();
			$this->debug('History payload: ' . json_encode($payload));

			$thread_number = $payload['thread_number'] ?? '';
			$user_uuid = $payload['user_uuid'] ?? '';
			$domain_uuid = $payload['domain_uuid'] ?? '';
			$limit = min((int)($payload['limit'] ?? 50), 100);

			if (empty($thread_number) || empty($user_uuid) || empty($domain_uuid)) {
				throw new \InvalidArgumentException('Thread number, user UUID, and domain UUID are required');
			}

			$this->debug("Fetching history for thread: {$thread_number}, user: {$user_uuid}, domain: {$domain_uuid}, limit: {$limit}");

			// Get database singleton instance
			$database = database::new();

			// Get messages for this thread - use string concatenation for LIMIT to avoid PDO issues
			$sql = "SELECT message_uuid, message_direction as direction, message_from, message_to, ";
			$sql .= "message_text, message_type, message_date as message_time, message_read, message_json ";
			$sql .= "FROM v_messages ";
			$sql .= "WHERE domain_uuid = :domain_uuid ";
			$sql .= "AND (message_from = :thread_number1 OR message_to = :thread_number2) ";
			$sql .= "ORDER BY message_date DESC ";
			$sql .= "LIMIT " . (int)$limit;

			$parameters = [
				'domain_uuid' => $domain_uuid,
				'thread_number1' => $thread_number,
				'thread_number2' => $thread_number
			];

			$this->debug('Executing SQL: ' . $sql);
			$this->debug('Parameters: ' . json_encode($parameters));

			$messages = $database->select($sql, $parameters, 'all');

			$this->debug('Query result type: ' . gettype($messages));
			$this->debug('Query result count: ' . (is_array($messages) ? count($messages) : 'N/A'));

			// Ensure messages is always an array
			if (!is_array($messages)) {
				$messages = [];
			}

			// Reverse to get chronological order
			$messages = array_reverse($messages);

			$this->debug('Sending history response with ' . count($messages) . ' messages');

			$response = new websocket_message();
			$response->service_name(self::get_service_name());
			$response->topic('history_response');
			$response->status_string('ok');
			$response->status_code(200);
			$response->request_id($websocket_message->request_id());
			$response->resource_id($websocket_message->resource_id());
			$response->payload([
				'success' => true,
				'thread_number' => $thread_number,
				'messages' => $messages
			]);

			// Use to_json() or (string) cast to properly serialize the response
			$this->debug('Response JSON: ' . $response->to_json());
			$this->respond($response);
			$this->debug('History response sent successfully');

		} catch (\Exception $e) {
			$this->error('History request failed: ' . $e->getMessage());

			$response = new websocket_message();
			$response->service_name(self::get_service_name());
			$response->topic('history_response');
			$response->status_string('error');
			$response->status_code(400);
			$response->request_id($websocket_message->request_id());
			$response->resource_id($websocket_message->resource_id());
			$response->payload([
				'success' => false,
				'error' => $e->getMessage()
			]);
			$this->respond($response);
		}
	}

	/**
	 * Handle mark read request from clients
	 *
	 * @param websocket_message $websocket_message
	 */
	protected function handle_mark_read_request(websocket_message $websocket_message): void {
		$this->debug('Handling mark read request');

		try {
			$payload = $websocket_message->payload();
			$message_uuids = $payload['message_uuids'] ?? [];

			if (empty($message_uuids)) {
				throw new \InvalidArgumentException('Message UUIDs are required');
			}

			// Get domain_uuid from the authenticated websocket message
			$domain_uuid = $payload['domain_uuid'] ?? $websocket_message->domain_uuid();
			if (empty($domain_uuid)) {
				throw new \InvalidArgumentException('Domain UUID is required');
			}

			// Get database singleton instance
			$database = database::new();

			// Update messages as read (with domain_uuid filter for security)
			foreach ($message_uuids as $uuid) {
				if (!is_uuid($uuid)) continue;

				$sql = "UPDATE v_messages SET message_read = true WHERE message_uuid = :message_uuid AND domain_uuid = :domain_uuid";
				$database->execute($sql, ['message_uuid' => $uuid, 'domain_uuid' => $domain_uuid]);
			}

			$response = new websocket_message();
			$response->service_name(self::get_service_name());
			$response->topic('mark_read_response');
			$response->status_string('ok');
			$response->status_code(200);
			$response->request_id($websocket_message->request_id());
			$response->resource_id($websocket_message->resource_id());
			$response->payload([
				'success' => true,
				'marked_count' => count($message_uuids)
			]);
			$this->respond($response);

		} catch (\Exception $e) {
			$this->error('Mark read request failed: ' . $e->getMessage());

			$response = new websocket_message();
			$response->service_name(self::get_service_name());
			$response->topic('mark_read_response');
			$response->status_string('error');
			$response->status_code(400);
			$response->request_id($websocket_message->request_id());
			$response->resource_id($websocket_message->resource_id());
			$response->payload([
				'success' => false,
				'error' => $e->getMessage()
			]);
			$this->respond($response);
		}
	}


	/**
	 * Connects to the switch event socket
	 * @return bool
	 */
	private function connect_to_event_socket(): bool {
		// check if we have defined it already
		if (!isset($this->switch_socket)) {
			// default to false for the while loop below
			$this->switch_socket = false;
		}

		// When no command line option is used to set the switch host, port, or password, get it from
		// the config file. If it is not in the config file, then set a default value
		$host     = self::$switch_host ?? parent::$config->get('switch.event_socket.host', '127.0.0.1');
		$port     = self::$switch_port ?? parent::$config->get('switch.event_socket.port', 8021);
		$password = self::$switch_password ?? parent::$config->get('switch.event_socket.password', 'ClueCon');

		try {
			// set up the socket away from the event_socket object so we have control over blocking
			$this->switch_socket = stream_socket_client("tcp://$host:$port", $errno, $errstr, 5);
		} catch (\RuntimeException $re) {
			$this->warning('Unable to connect to event socket');
		}

		// If we didn't connect then return back false
		if (!$this->switch_socket) {
			return false;
		}

		// Block (wait) for responses so we can authenticate
		stream_set_blocking($this->switch_socket, true);

		// Create the event_socket object using the connected socket
		$this->event_socket = new event_socket($this->switch_socket);

		// The host and port are already provided when we connect the socket so just provide password
		$this->event_socket->connect(null, null, $password);

		// No longer need to wait for events
		stream_set_blocking($this->switch_socket, false);

		// Add the switch socket to the event loop
		$this->add_listener($this->switch_socket, function() {
			$this->handle_switch_event();
		});

		return $this->event_socket->is_connected();
	}

	/**
	 * Registers the switch events needed for opensms
	 */
	private function register_event_socket_filters() {
		$this->event_socket->request('event plain all');

		//
		// CUSTOM and API are required to handle events such as:
		//   - 'valet_parking::info'
		//   - 'SMS::SEND_MESSAGE'
		//   - 'cache::flush'
		//   - 'sofia::register'
		//
		//	$event_filter = [
		//		'CUSTOM',		// Event-Name is swapped with Event-Subclass
		//		'API',			// Event-Name is swapped with API-Command
		//	];
		// Merge API and CUSTOM with the events listening
		//	$events = array_merge(self::SWITCH_EVENTS, $event_filter);
		// Add filters for MESSAGE only
		foreach (self::SWITCH_EVENTS as $events) {
			foreach ($events as $event_key => $event_name) {
				$this->debug("Requesting event filter for [$event_key]=[$event_name]");
				$response = $this->event_socket->request("filter $event_key $event_name");
				while (!is_array($response)) {
					$response = $this->event_socket->read_event();
				}
				if (is_array($response)) {
					while (($response = array_pop($response)) !== "+OK filter added. [$event_key]=[$event_name]") {
						$response = $this->event_socket->read_event();
						usleep(1000);
					}
				}
				$this->debug("Response: " . $response);
			}
		}
	}

	private function handle_switch_event() {
		$raw_event = $this->event_socket->read_event();

		//$this->debug("=====================================");
		//$this->debug("RAW EVENT: " . ($raw_event['$'] ?? ''));
		//$this->debug("=====================================");

		// get the switch message event object
		$event = event_message::create_from_switch_event($raw_event, $this->event_filter);

		$this->debug("Received event: " . $event->__toString());
		$this->debug("Text Message: " . $event->body());

		// Broadcast to websocket clients
		$this->broadcast_message_event($event);

		// Set global $settings for opensms::broadcast_event()
		global $settings;
		$settings = $this->settings;

		// Send to provider (outbound routing)
		opensms::broadcast_event($event);
	}

	/**
	 * Broadcast MESSAGE event to websocket clients
	 */
	private function broadcast_message_event($event) {
		$this->debug("Received MESSAGE event from FreeSWITCH: " . $event->__toString());

		if (!$this->ws_client || !$this->ws_client->is_connected()) {
			$this->debug('Not connected to websocket host. Dropping Event');
			return;
		}

		$this->debug('Connected to websocket host, broadcasting event');

		// Create a message to send on websocket
		$message = new websocket_message();

		// Set the service name so subscribers can filter
		$message->service_name(self::get_service_name());

		// Set the topic to MESSAGE
		$message->topic('MESSAGE');

		// The event is the payload
		$message->payload($event->to_array());

		// Notify system log of the message
		$this->debug("Broadcasting SMS Event to websocket clients: " . json_encode($event->to_array()));

		//send event to the web socket routing service
		websocket_client::send($this->ws_client->socket(), $message);

		$this->debug('Event sent to websocket server');
	}

	/**
	 * Handle websocket send requests from clients
	 *
	 * @param websocket_message $websocket_message The send request message
	 */
	protected function handle_send_request(websocket_message $websocket_message) {
		$this->debug('Handling send request');
		$message = null;

		try {
			$payload = $websocket_message->payload();
			$this->debug('Send payload: ' . json_encode($payload));

			// Validate required fields
			if (empty($payload['from_destination_uuid']) ||
				empty($payload['from_number']) ||
				empty($payload['to_number'])) {
				throw new \InvalidArgumentException('Missing required fields');
			}

			// Validate user has permission to use this destination
			if (!$this->validate_destination_access($payload['from_destination_uuid'], $payload['user_uuid'] ?? null)) {
				throw new \Exception('User does not have permission to send from this destination');
			}

			// Get provider UUID for the destination
			$provider_uuid = $this->get_provider_for_destination($payload['from_destination_uuid']);
			if (!$provider_uuid) {
				throw new \Exception('No provider found for destination');
			}

			// Look up the provider name to match against adapters
			$provider_name = $this->get_provider_name($provider_uuid);

			$this->info('Sending message via provider: ' . $provider_name . ' (' . $provider_uuid . ')');

			// Create message object
			$message = new opensms_message(uuid(), $provider_uuid);
			$message->to_number = $payload['to_number'];
			$message->from_number = $payload['from_number'];
			$message->sms = $payload['message_text'] ?? '';
			$message->type = $payload['message_type'] ?? 'sms';
			$message->domain_uuid = $payload['domain_uuid'] ?? $websocket_message->domain_uuid();
			$message->user_uuid = $payload['user_uuid'] ?? '';
			$message->destination_uuid = $payload['from_destination_uuid'] ?? '';
			$message->time = date('Y-m-d H:i:s');

			// Attach MMS media if present
			if (!empty($payload['mms']) && is_array($payload['mms'])) {
				$message->mms = $payload['mms'];
				$message->type = 'mms';
			}

			// Get adapters and send
			$auto_loader = new auto_loader(true);
			$auto_loader->reload_classes();
			$adapters = $auto_loader->get_interface_list('opensms_message_adapter');

			$success = false;
			foreach ($adapters as $adapter_class) {
				// Match by provider name (case-insensitive) rather than UUID
				// since the database provider_uuid may differ from the adapter constant
				if (defined($adapter_class . '::OPENSMS_PROVIDER_NAME')) {
					$adapter_provider_name = constant($adapter_class . '::OPENSMS_PROVIDER_NAME');
					if (!empty($provider_name) && $this->provider_matches_adapter($provider_name, $adapter_provider_name)) {
						$this->info('Found matching adapter: ' . $adapter_class);
						$send_start = microtime(true);
						$success = $adapter_class::send($this->settings ?? new settings(), $message);
						$send_elapsed = round((microtime(true) - $send_start) * 1000);
						$this->info("Adapter send completed in {$send_elapsed}ms");
						break;
					}
				}
			}

			if (!$success) {
				throw new \Exception('Failed to send message via provider' . ($provider_name ? " '{$provider_name}'" : ''));
			}

			$this->debug('Message sent successfully');

			// Provider accepted the outbound message; persist as provider success
			$this->save_outbound_message($message, 'provider_success');

			// Respond with success
			$response = new websocket_message();
			$response->service_name(self::get_service_name());
			$response->topic('send_response');
			$response->status_string('ok');
			$response->status_code(200);
			$response->request_id($websocket_message->request_id());
			$response->resource_id($websocket_message->resource_id());
			$response->payload([
				'success' => true,
				'message_uuid' => $message->uuid,
				'message' => 'Message sent successfully'
			]);

		} catch (\Exception $e) {
			if ($message instanceof opensms_message) {
				$this->save_outbound_message($message, 'failed', $e->getMessage());
			}
			$error_msg = $e->getMessage();
			// Truncate very long error messages (e.g. those containing base64 data)
			if (strlen($error_msg) > 600) {
				$error_msg = substr($error_msg, 0, 600) . '... [truncated]';
			}
			$this->error('Send failed: ' . $error_msg);

			// Respond with error
			$response = new websocket_message();
			$response->service_name(self::get_service_name());
			$response->topic('send_response');
			$response->status_string('error');
			$response->status_code(400);
			$response->request_id($websocket_message->request_id());
			$response->resource_id($websocket_message->resource_id());
			$response->payload([
				'success' => false,
				'error' => $e->getMessage(),
				'message_uuid' => $message instanceof opensms_message ? $message->uuid : null
			]);
		}

		$this->respond($response);
	}

	/**
	 * Validate user has permission to send from a destination
	 *
	 * @param string $destination_uuid
	 * @param string|null $user_uuid
	 * @return bool
	 */
	private function validate_destination_access(string $destination_uuid, ?string $user_uuid = null): bool {
		// Check if destination belongs to user or their groups
		$resolved_user_uuid = $user_uuid ?: ($_SESSION['user']['user_uuid'] ?? '');

		$sql = "SELECT COUNT(*) FROM v_destinations d ";
		$sql .= "LEFT JOIN v_users u ON d.user_uuid = u.user_uuid ";
		$sql .= "LEFT JOIN v_user_groups ug ON d.group_uuid = ug.group_uuid ";
		$sql .= "WHERE d.destination_uuid = :destination_uuid ";
		$sql .= "AND d.destination_type_text = 1 ";
		$sql .= "AND d.destination_enabled = 'true' ";
		$sql .= "AND (d.user_uuid = :user_uuid1 OR ug.user_uuid = :user_uuid2)";

		$database = parent::$database ?? new database();
		$count = $database->select($sql, [
			'destination_uuid' => $destination_uuid,
			'user_uuid1' => $resolved_user_uuid,
			'user_uuid2' => $resolved_user_uuid,
		], 'column');

		return $count > 0;
	}

	/**
	 * Get provider UUID for a destination
	 *
	 * @param string $destination_uuid
	 * @return string|null
	 */
	private function get_provider_for_destination(string $destination_uuid): ?string {
		$sql = "SELECT provider_uuid FROM v_destinations WHERE destination_uuid = :destination_uuid";
		$database = parent::$database ?? new database();
		return $database->select($sql, ['destination_uuid' => $destination_uuid], 'column');
	}

	/**
	 * Get provider name by its UUID
	 *
	 * @param string $provider_uuid
	 * @return string|null
	 */
	private function get_provider_name(string $provider_uuid): ?string {
		$sql = "SELECT provider_name FROM v_providers WHERE provider_uuid = :provider_uuid";
		$database = parent::$database ?? new database();
		return $database->select($sql, ['provider_uuid' => $provider_uuid], 'column');
	}

	/**
	 * Match provider names in a backwards-compatible way.
	 */
	private function provider_matches_adapter(string $provider_name, string $adapter_provider_name): bool {
		if (strcasecmp($provider_name, $adapter_provider_name) === 0) {
			return true;
		}

		$provider_normalized = preg_replace('/[^a-z0-9]/', '', strtolower($provider_name));
		$adapter_normalized = preg_replace('/[^a-z0-9]/', '', strtolower($adapter_provider_name));

		if ($provider_normalized === '' || $adapter_normalized === '') {
			return false;
		}

		if ($provider_normalized === $adapter_normalized) {
			return true;
		}

		return str_starts_with($provider_normalized, $adapter_normalized)
			|| str_starts_with($adapter_normalized, $provider_normalized);
	}

	/**
	 * Save sent message to the database
	 *
	 * @param opensms_message $message
	 * @return void
	 */
	private function save_outbound_message(opensms_message $message, string $delivery_status = 'sent', string $error_message = ''): void {
		$database = parent::$database ?? new database();
		$message->delivery_status = $delivery_status;
		if (!empty($error_message)) {
			$message->received_data = $error_message;
		}

		// Prepare message data for database
		$message_array = [
			'messages' => [
				0 => [
					'message_uuid' => $message->uuid,
					'domain_uuid' => $message->domain_uuid,
					'provider_uuid' => $message->provider_uuid,
					'user_uuid' => $message->user_uuid,
					'contact_uuid' => $message->contact_uuid,
					'message_type' => $message->type,
					'message_direction' => 'outbound',
					'message_date' => 'now()',
					'message_read' => 'true',
					'message_from' => $message->from_number,
					'message_to' => $message->to_number,
					'message_text' => $message->sms,
					'message_json' => json_encode($message->to_array()),
					'insert_date' => 'now()',
					'insert_user' => $message->user_uuid,
					'update_date' => 'now()',
					'update_user' => $message->user_uuid
				]
			]
		];

		$database->save($message_array);
		$this->debug('Saved outbound message to database: ' . $message->uuid . ' [' . $delivery_status . ']');
	}

	/**
	 * Normalize a phone number for consistent matching.
	 */
	private function normalize_number(string $value): string {
		$digits = preg_replace('/\D/', '', $value);
		if (strlen($digits) === 11 && strpos($digits, '1') === 0) {
			return substr($digits, 1);
		}
		return $digits;
	}

	/**
	 * Upsert a user setting entry.
	 */
	private function upsert_user_setting(string $domain_uuid, string $user_uuid, string $subcategory, string $name, string $value, bool $enabled = true): void {
		$database = database::new();
		$sql = "select user_setting_uuid from v_user_settings ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and user_uuid = :user_uuid ";
		$sql .= "and user_setting_category = 'opensms' ";
		$sql .= "and user_setting_subcategory = :subcategory ";
		$sql .= "and user_setting_name = :name ";
		$parameters = [
			'domain_uuid' => $domain_uuid,
			'user_uuid' => $user_uuid,
			'subcategory' => $subcategory,
			'name' => $name,
		];
		$user_setting_uuid = $database->select($sql, $parameters, 'column');
		$user_setting_uuid = is_array($user_setting_uuid) ? ($user_setting_uuid[0] ?? null) : $user_setting_uuid;

		$array['user_settings'][0]['user_setting_uuid'] = $user_setting_uuid ?: uuid();
		$array['user_settings'][0]['domain_uuid'] = $domain_uuid;
		$array['user_settings'][0]['user_uuid'] = $user_uuid;
		$array['user_settings'][0]['user_setting_category'] = 'opensms';
		$array['user_settings'][0]['user_setting_subcategory'] = $subcategory;
		$array['user_settings'][0]['user_setting_name'] = $name;
		$array['user_settings'][0]['user_setting_value'] = $value;
		$array['user_settings'][0]['user_setting_enabled'] = $enabled ? 'true' : 'false';

		$database->save($array);
	}

	/**
	 * Delete a user setting entry.
	 */
	private function delete_user_setting(string $domain_uuid, string $user_uuid, string $subcategory, string $name): void {
		$database = database::new();
		$sql = "delete from v_user_settings ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and user_uuid = :user_uuid ";
		$sql .= "and user_setting_category = 'opensms' ";
		$sql .= "and user_setting_subcategory = :subcategory ";
		$sql .= "and user_setting_name = :name ";
		$parameters = [
			'domain_uuid' => $domain_uuid,
			'user_uuid' => $user_uuid,
			'subcategory' => $subcategory,
			'name' => $name,
		];
		$database->execute($sql, $parameters);
	}

	/**
	 * List user settings by subcategory.
	 */
	private function list_user_settings(string $domain_uuid, string $user_uuid, string $subcategory): array {
		$database = database::new();
		$sql = "select user_setting_name from v_user_settings ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and user_uuid = :user_uuid ";
		$sql .= "and user_setting_category = 'opensms' ";
		$sql .= "and user_setting_subcategory = :subcategory ";
		$sql .= "and user_setting_enabled = 'true' ";
		$parameters = [
			'domain_uuid' => $domain_uuid,
			'user_uuid' => $user_uuid,
			'subcategory' => $subcategory,
		];
		$rows = $database->select($sql, $parameters, 'all');
		if (!is_array($rows)) {
			return [];
		}
		return array_values(array_filter(array_map(function ($row) {
			return $row['user_setting_name'] ?? null;
		}, $rows)));
	}

	/**
	 * Handle block number request.
	 */
	protected function handle_block_number_request(websocket_message $websocket_message): void {
		try {
			$payload = $websocket_message->payload();
			$number = $this->normalize_number($payload['number'] ?? '');
			$user_uuid = $payload['user_uuid'] ?? '';
			$domain_uuid = $payload['domain_uuid'] ?? $websocket_message->domain_uuid();
			if (empty($number) || empty($user_uuid) || empty($domain_uuid)) {
				throw new \InvalidArgumentException('Missing required fields');
			}
			$this->upsert_user_setting($domain_uuid, $user_uuid, 'blocked_number', $number, 'true', true);
			$response = new websocket_message();
			$response->service_name(self::get_service_name());
			$response->topic('block_number_response');
			$response->status_string('ok');
			$response->status_code(200);
			$response->request_id($websocket_message->request_id());
			$response->resource_id($websocket_message->resource_id());
			$response->payload(['success' => true, 'number' => $number]);
			$this->respond($response);
		} catch (\Exception $e) {
			$response = new websocket_message();
			$response->service_name(self::get_service_name());
			$response->topic('block_number_response');
			$response->status_string('error');
			$response->status_code(400);
			$response->request_id($websocket_message->request_id());
			$response->resource_id($websocket_message->resource_id());
			$response->payload(['success' => false, 'error' => $e->getMessage()]);
			$this->respond($response);
		}
	}

	/**
	 * Handle unblock number request.
	 */
	protected function handle_unblock_number_request(websocket_message $websocket_message): void {
		try {
			$payload = $websocket_message->payload();
			$number = $this->normalize_number($payload['number'] ?? '');
			$user_uuid = $payload['user_uuid'] ?? '';
			$domain_uuid = $payload['domain_uuid'] ?? $websocket_message->domain_uuid();
			if (empty($number) || empty($user_uuid) || empty($domain_uuid)) {
				throw new \InvalidArgumentException('Missing required fields');
			}
			$this->delete_user_setting($domain_uuid, $user_uuid, 'blocked_number', $number);
			$response = new websocket_message();
			$response->service_name(self::get_service_name());
			$response->topic('unblock_number_response');
			$response->status_string('ok');
			$response->status_code(200);
			$response->request_id($websocket_message->request_id());
			$response->resource_id($websocket_message->resource_id());
			$response->payload(['success' => true, 'number' => $number]);
			$this->respond($response);
		} catch (\Exception $e) {
			$response = new websocket_message();
			$response->service_name(self::get_service_name());
			$response->topic('unblock_number_response');
			$response->status_string('error');
			$response->status_code(400);
			$response->request_id($websocket_message->request_id());
			$response->resource_id($websocket_message->resource_id());
			$response->payload(['success' => false, 'error' => $e->getMessage()]);
			$this->respond($response);
		}
	}

	/**
	 * Handle list blocked numbers request.
	 */
	protected function handle_list_blocked_request(websocket_message $websocket_message): void {
		try {
			$payload = $websocket_message->payload();
			$user_uuid = $payload['user_uuid'] ?? '';
			$domain_uuid = $payload['domain_uuid'] ?? $websocket_message->domain_uuid();
			if (empty($user_uuid) || empty($domain_uuid)) {
				throw new \InvalidArgumentException('Missing required fields');
			}
			$numbers = $this->list_user_settings($domain_uuid, $user_uuid, 'blocked_number');
			$response = new websocket_message();
			$response->service_name(self::get_service_name());
			$response->topic('list_blocked_response');
			$response->status_string('ok');
			$response->status_code(200);
			$response->request_id($websocket_message->request_id());
			$response->resource_id($websocket_message->resource_id());
			$response->payload(['success' => true, 'numbers' => $numbers]);
			$this->respond($response);
		} catch (\Exception $e) {
			$response = new websocket_message();
			$response->service_name(self::get_service_name());
			$response->topic('list_blocked_response');
			$response->status_string('error');
			$response->status_code(400);
			$response->request_id($websocket_message->request_id());
			$response->resource_id($websocket_message->resource_id());
			$response->payload(['success' => false, 'error' => $e->getMessage()]);
			$this->respond($response);
		}
	}

	/**
	 * Handle hide thread request (user-only delete).
	 */
	protected function handle_hide_thread_request(websocket_message $websocket_message): void {
		try {
			$payload = $websocket_message->payload();
			$thread = trim($payload['thread_number'] ?? '');
			$user_uuid = $payload['user_uuid'] ?? '';
			$domain_uuid = $payload['domain_uuid'] ?? $websocket_message->domain_uuid();
			if (empty($thread) || empty($user_uuid) || empty($domain_uuid)) {
				throw new \InvalidArgumentException('Missing required fields');
			}
			$this->upsert_user_setting($domain_uuid, $user_uuid, 'hidden_thread', $thread, 'true', true);
			$response = new websocket_message();
			$response->service_name(self::get_service_name());
			$response->topic('hide_thread_response');
			$response->status_string('ok');
			$response->status_code(200);
			$response->request_id($websocket_message->request_id());
			$response->resource_id($websocket_message->resource_id());
			$response->payload(['success' => true, 'thread_number' => $thread]);
			$this->respond($response);
		} catch (\Exception $e) {
			$response = new websocket_message();
			$response->service_name(self::get_service_name());
			$response->topic('hide_thread_response');
			$response->status_string('error');
			$response->status_code(400);
			$response->request_id($websocket_message->request_id());
			$response->resource_id($websocket_message->resource_id());
			$response->payload(['success' => false, 'error' => $e->getMessage()]);
			$this->respond($response);
		}
	}

	/**
	 * Handle unhide thread request.
	 */
	protected function handle_unhide_thread_request(websocket_message $websocket_message): void {
		try {
			$payload = $websocket_message->payload();
			$thread = trim($payload['thread_number'] ?? '');
			$user_uuid = $payload['user_uuid'] ?? '';
			$domain_uuid = $payload['domain_uuid'] ?? $websocket_message->domain_uuid();
			if (empty($thread) || empty($user_uuid) || empty($domain_uuid)) {
				throw new \InvalidArgumentException('Missing required fields');
			}
			$this->delete_user_setting($domain_uuid, $user_uuid, 'hidden_thread', $thread);
			$response = new websocket_message();
			$response->service_name(self::get_service_name());
			$response->topic('unhide_thread_response');
			$response->status_string('ok');
			$response->status_code(200);
			$response->request_id($websocket_message->request_id());
			$response->resource_id($websocket_message->resource_id());
			$response->payload(['success' => true, 'thread_number' => $thread]);
			$this->respond($response);
		} catch (\Exception $e) {
			$response = new websocket_message();
			$response->service_name(self::get_service_name());
			$response->topic('unhide_thread_response');
			$response->status_string('error');
			$response->status_code(400);
			$response->request_id($websocket_message->request_id());
			$response->resource_id($websocket_message->resource_id());
			$response->payload(['success' => false, 'error' => $e->getMessage()]);
			$this->respond($response);
		}
	}

	/**
	 * Handle list hidden threads request.
	 */
	protected function handle_list_hidden_request(websocket_message $websocket_message): void {
		try {
			$payload = $websocket_message->payload();
			$user_uuid = $payload['user_uuid'] ?? '';
			$domain_uuid = $payload['domain_uuid'] ?? $websocket_message->domain_uuid();
			if (empty($user_uuid) || empty($domain_uuid)) {
				throw new \InvalidArgumentException('Missing required fields');
			}
			$threads = $this->list_user_settings($domain_uuid, $user_uuid, 'hidden_thread');
			$response = new websocket_message();
			$response->service_name(self::get_service_name());
			$response->topic('list_hidden_response');
			$response->status_string('ok');
			$response->status_code(200);
			$response->request_id($websocket_message->request_id());
			$response->resource_id($websocket_message->resource_id());
			$response->payload(['success' => true, 'threads' => $threads]);
			$this->respond($response);
		} catch (\Exception $e) {
			$response = new websocket_message();
			$response->service_name(self::get_service_name());
			$response->topic('list_hidden_response');
			$response->status_string('error');
			$response->status_code(400);
			$response->request_id($websocket_message->request_id());
			$response->resource_id($websocket_message->resource_id());
			$response->payload(['success' => false, 'error' => $e->getMessage()]);
			$this->respond($response);
		}
	}

	/**
	 * Creates a filter chain for the opensms service.
	 *
	 * This method generates a filter based on the subscriber's permissions,
	 * allowing them to receive only relevant opensms information.
	 *
	 * @param subscriber $subscriber The subscriber object with permission data.
	 *
	 * @return ?filter A filter chain that matches the subscriber's permissions, or null if no match is found.
	 */
	public static function create_filter_chain_for(subscriber $subscriber): ?filter {
		return null;
	}
}
