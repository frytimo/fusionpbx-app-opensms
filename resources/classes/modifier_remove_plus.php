<?php

/**
 * Modifies the message numbers to remove the leading + in the 'to' and 'from' properties
 *
 * The database does not store numbers with the leading +, so this modifier ensures
 * that the numbers are in the correct format for database lookups and processing.
 */
class modifier_remove_plus implements opensms_message_modifier {
	public function __invoke(settings $settings, opensms_message $message): void {
		self::remove_plus($message->to_number);
		self::remove_plus($message->from_number);
	}

	private static function remove_plus(string &$number) {
		// Remove the '+' from the beginning of the number
		if (str_starts_with($number, '+')) {
			$number = substr($number, 1);
		}
	}

	public function priority(): int {
		return 0; // First priority
	}
}
