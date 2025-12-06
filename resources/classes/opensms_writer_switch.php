<?php

/** 
 * Class opensms_switch_writer
 *
 * This class implements the opensms_listener interface to send incoming
 * OpenSMS messages to the switch via event socket.
 */
class opensms_writer_switch implements opensms_listener {

	/**
	 * Process the incoming OpenSMS message and send it to the switch.
	 *
	 * This method constructs a SIP MESSAGE event with the details from the
	 * opensms_message instance and sends it to the switch using event socket.
	 *
	 * @param opensms_message $message The message object containing SMS details.
	 * @return void
	 */
	public function on_message(settings $settings, opensms_message $message): void {
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

		$sip_profile = $message->sip_profile ?? 'internal';

		//send the SIP message to the switch
		$event = "sendevent CUSTOM\n";
		$event .= "Event-Subclass: SMS::SEND_MESSAGE\n";
		$event .= "proto: sip\n";
		$event .= "dest_proto: sip\n";
		$event .= "from: ".$message->from_number."\n";
		$event .= "from_full: sip:".$message->from_number."\n";
		$event .= "to: ".$message->to_number."\n";
		$event .= "subject: sip:".$message->to_number."\n";
		$event .= "type: text/plain\n";
		//$event .= "hint: the hint\n";
		$event .= "replying: true\n";
		$event .= "sip_profile: ".$sip_profile."\n";
		$event .= "_body: ". $message->sms;

		event_socket::command($event);
		
	}
}
