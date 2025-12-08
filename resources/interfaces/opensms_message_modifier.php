<?php

interface opensms_message_modifier {
	/**
	 * Modify an opensms_message according to the provided settings.
	 *
	 * This hook is intended to perform in-memory transformations of the
	 * message prior to sending or further processing. Implementations should mutate
	 * the supplied $message object (for example: normalize/truncate text, apply
	 * templates, adjust recipients/sender, set headers or metadata, add tags,
	 * enforce per-account limits, or perform encoding changes) based on the values
	 * found in $settings.
	 *
	 * Implementations MUST:
	 * - Modify $message in-place; do not return a new message object.
	 * - Treat $settings as read-only configuration/context.
	 * - Validate and sanitize any changes made to $message.
	 * - Keep changes idempotent where practical (calling this method repeatedly
	 *   should not keep adding duplicate modifications).
	 * - Avoid long-running or blocking operations (this should be a quick, memory-only transformation).
	 *
	 * Constraints and side effects:
	 * - Do not perform persistence (database writes) from this method; persistence
	 *   is the responsibility of the writer.
	 * - Throw an exception only for unrecoverable validation or configuration errors;
	 *   callers may rely on exceptions to halt message processing.
	 *
	 * @param settings $settings Configuration and contextual information.
	 * @param opensms_message $message The message instance to be modified in-place.
	 *
	 * @return void
	 * @throws \InvalidArgumentException If required settings are missing or the
	 *                                   message is in an invalid state that cannot
	 *                                   be corrected by this modifier.
	 */
	public function __invoke(settings $settings, opensms_message $message): void;

	/**
	 * Priority for ordering (lower = runs earlier).
	 * Default should be 100.
	 *
	 * @return int
	 */
	public function priority(): int;
}
