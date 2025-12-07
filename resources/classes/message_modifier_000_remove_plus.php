<?php

/**
 * Modifies the message numbers to remove the leading + in the 'to' and 'from' properties
 */
class message_modifier_000_remove_plus implements opensms_message_modifier {
	public function modify(settings $settings, opensms_message $message): void {
		self::remove_plus($message->to_number);
		self::remove_plus($message->from_number);
	}

	private static function remove_plus(string &$number) {
		// Remove the '+' from the beginning of the number
		if (str_starts_with($number, '+')) {
			$number = substr($number, 1);
		}
	}
}
