<?php

/**
 * Class opensms_switch_writer
 *
 * This class implements the opensms_message_listener interface to send incoming
 * OpenSMS messages to the switch via event socket.
 */
class opensms_message_switch_writer implements opensms_message_listener {

	/**
	 * Process the incoming OpenSMS message and send it to the switch.
	 *
	 * This method constructs a SIP MESSAGE event with the details from the
	 * opensms_message instance and sends it to the switch using event socket.
	 *
	 * @param opensms_message $opensms_message The message object containing SMS details.
	 * @return void
	 */
	public function on_message(settings $settings, opensms_message $opensms_message): void {
        $config = $settings->database()->config();

        // Get the switch credentials from the config file and supply a default value
        $host = $config->get('switch.hostname', '127.0.0.1');
        $port = $config->get('switch.port', 8021);
        $password = $config->get('switch.password', 'ClueCon');

        // Create the connection
        $event_socket = event_socket::create($host, $port, $password);

        // Check if connected
        if (!$event_socket->connected()) {
            throw new \RuntimeException("Unable to connect to event socket");
        }

		$sip_profile = $opensms_message->sip_profile ?? 'internal';

		foreach ($opensms_message->broadcast_destinations as $destination) {
			if (empty($destination)) {
				continue; // Skip empty destinations
			}
			if (!$this->is_registered($event_socket, $destination, $sip_profile)) {
				$this->reschedule_message($settings, $opensms_message, $destination, $event_socket);
				continue; // Skip sending to unregistered destination
			}

			$event = $this->construct_raw_switch_event($opensms_message);

			// Send the event to the switch
			$response = $event_socket->request($event);

		}
	}

	private function construct_raw_switch_event(opensms_message $opensms_message, string $header = 'sendevent CUSTOM'): string {
		// Construct the SIP MESSAGE event
		$event = "$header\n";
		$event .= "Event-Subclass: SMS::SEND_MESSAGE\n";
		$event .= "proto: sip\n";
		$event .= "dest_proto: sip\n";
		$event .= "from: ".$opensms_message->from_number."\n";
		$event .= "from_full: sip:".$opensms_message->from_number."\n";
		$event .= "to: ".$opensms_message->to_number."\n";
		$event .= "subject: sip:".$opensms_message->to_number."\n";
		$event .= "type: text/plain\n";
		$event .= "replying: true\n";
		$event .= "sip_profile: ".$opensms_message->sip_profile."\n";
		$event .= "_body: ". $opensms_message->sms;
		return $event;
	}

	private function is_registered(event_socket $event_socket, string $destination, string $sip_profile = 'internal'): bool {
		// Check if the destination is registered on the switch
		$response = $event_socket->request("api sofia status profile $sip_profile reg $destination");
		return strpos($response, 'Registered') !== false;
	}

	private function construct_failed_raw_switch_event(opensms_message $opensms_message, string $reason): string {
		// Construct the event for a failed message delivery
		$event = "sendevent CUSTOM\n";
		$event .= "Event-Subclass: SMS::FAILED_MESSAGE\n";
		$event .= "proto: sip\n";
		$event .= "dest_proto: sip\n";
		$event .= "from: ".$opensms_message->from_number."\n";
		$event .= "from_full: sip:".$opensms_message->from_number."\n";
		$event .= "to: ".$opensms_message->to_number."\n";
		$event .= "subject: sip:".$opensms_message->to_number."\n";
		$event .= "type: text/plain\n";
		$event .= "replying: true\n";
		$event .= "sip_profile: ".$opensms_message->sip_profile."\n";
		$event .= "error: $reason\n";
		$event .= "_body: ". $opensms_message->sms;
		$event .= "\n\n";
		return $event;
	}

	private function reschedule_message(settings $settings, opensms_message $opensms_message, string $destination, event_socket $event_socket): void {
		// Get the retry interval and max retries from the settings
		$retry_interval = (int)$settings->get('opensms','switch_retry_interval', 0); // Default to disabled (0 seconds)
		// Retry is disabled, do not reschedule
		if ($retry_interval <= 0) {
			return;
		}
		// Get the max retries from the settings
		$max_retries = (int)$settings->get('opensms','switch_max_retries', 3); // Default to 3 retries

		// Get the current number of retries for this message from the database
		$current_tries = $settings->database()->select('SELECT opensms_message_retries FROM v_opensms_messages WHERE opensms_message_uuid = :uuid', ['uuid' => $opensms_message->uuid], 'column');

		// Max retries reached, do not reschedule
		if ($current_tries >= $max_retries) {
			$event = event_message::create_from_switch_event($this->construct_failed_raw_switch_event($opensms_message, 'max retries reached'));
			opensms::broadcast_event($event);
			// Trigger an event for a failed message delivery
			return;
		}

		// Reschedule the message for later delivery
		$event = $this->construct_raw_switch_event($opensms_message, 'sched_api +'.$retry_interval.' api sendevent CUSTOM');

		// Send the raw switch event to the switch to schedule the retry
		$event_socket->request($event);

		// Update the retry count in the database
		$settings->database()->execute('UPDATE v_opensms_messages SET opensms_message_retries = opensms_message_retries + 1 WHERE opensms_message_uuid = :uuid', ['uuid' => $opensms_message->uuid]);
	}

	/**
	 * Hook in to the app_config
	 *
	 * @return array|null
	 */
	public static function app_config(): ?array {
		// Set the database column for the retries count
		$table_index = 0;
		$field_index = 0;
		$defaults = [];
		$defaults['db'][$table_index]['table']['name'] = 'v_opensms_messages';
		$defaults['db'][$table_index]['table']['parent'] = '';
		$defaults['db'][$table_index]['fields'][$field_index]['name'] = 'opensms_message_uuid';
		$defaults['db'][$table_index]['fields'][$field_index]['type']['pgsql'] = 'uuid';
		$defaults['db'][$table_index]['fields'][$field_index]['type']['mysql'] = 'char(36)';
		$defaults['db'][$table_index]['fields'][$field_index]['type']['sqlite'] = 'text';
		$defaults['db'][$table_index]['fields'][$field_index]['key']['type'] = 'primary';
		$defaults['db'][$table_index]['fields'][$field_index]['description']['en-us'] = 'The UUID of the message.';
		$field_index++;
		$defaults['db'][$table_index]['fields'][$field_index]['name'] = 'opensms_message_retries';
		$defaults['db'][$table_index]['fields'][$field_index]['type'] = 'numeric';
		$defaults['db'][$table_index]['fields'][$field_index]['description']['en-us'] = 'The number of retries attempted for this message.';

		// Set default settings for the app
		$y = 0;
		$defaults['default_settings'][$y]['default_setting_category'] = 'opensms';
		$defaults['default_settings'][$y]['default_setting_subcategory'] = 'switch_retry_interval';
		$defaults['default_settings'][$y]['default_setting_name'] = 'numeric';
		$defaults['default_settings'][$y]['default_setting_value'] = '300';
		$defaults['default_settings'][$y]['default_setting_enabled'] = 'true';
		$defaults['default_settings'][$y]['default_setting_description'] = 'The interval in seconds to wait before retrying to send a message to an unregistered destination. (Default: 5 minutes)';
		$y++;
		$defaults['default_settings'][$y]['default_setting_category'] = 'opensms';
		$defaults['default_settings'][$y]['default_setting_subcategory'] = 'switch_max_retries';
		$defaults['default_settings'][$y]['default_setting_name'] = 'numeric';
		$defaults['default_settings'][$y]['default_setting_value'] = '3';
		$defaults['default_settings'][$y]['default_setting_enabled'] = 'true';
		$defaults['default_settings'][$y]['default_setting_description'] = 'The maximum number of retries to attempt when sending a message to an unregistered destination. (Default: 3 times)';
		return $defaults;
	}

	/**
	 * Hook in to the app_defaults
	 *
	 * @return void
	 */
	public static function app_defaults(database $database): void {}

	/**
	 * Hook in to the app_menu
	 *
	 * @return array|null
	 */
	public static function app_menu(): ?array {	return null; }
}
