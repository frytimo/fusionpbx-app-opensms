<?php

/**
 * Class message_modifier_020_profile
 *
 * This class implements the opensms_message_modifier interface to assign
 * the SIP profile to an opensms_message based on configuration settings.
 */
class message_modifier_020_profile implements opensms_message_modifier {
	/**
	 * Assign the SIP profile to the opensms_message based on settings.
	 *
	 * This method retrieves the SIP profile configuration from the provided
	 * settings object and assigns it to the given opensms_message instance.
	 * It mutates the $message object and does not return a value.
	 *
	 * @param settings        $settings Settings object containing configuration and context.
	 * @param opensms_message $message  The message object to update with the SIP profile.
	 * @return void
	 */
	public function modify(settings $settings, opensms_message $message): void {
		$extension = $message->extensions[0] ?? null;
		$domain_name = $message->domain_name ?? null;

		$event_socket = event_socket::create();

		// Retrieve the SIP profile from settings
		$command = "sofia_contact $extension@$domain_name";
		$response = $event_socket->command("api $command");
		if ($response != 'error/user_not_registered') {
			$sip_profile = explode("/", $response)[1];
			// Assign the SIP profile to the message
			$message->sip_profile = $sip_profile;
		}
	}
}
