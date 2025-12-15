<?php

/**
 * Routes outbound messages by matching provider_uuid to adapter constants.
 *
 * Iterates through discovered adapters and checks if the adapter's
 * OPENSMS_PROVIDER_UUID matches the message's provider_uuid.
 */
class router_by_provider implements opensms_message_router {
	public function __invoke(settings $settings, opensms_message $message, array $adapters, ?callable $next): ?string {

		// Get the extension

		// Skip if message has no provider_uuid
		if (empty($message->provider_uuid)) {
			if ($next !== null) {
				return $next($settings, $message);
			}

			return null;
		}

		// Check each discovered adapter
		foreach ($adapters as $adapter_class) {
			// Check if adapter defines OPENSMS_PROVIDER_UUID constant
			if (defined("$adapter_class::OPENSMS_PROVIDER_UUID")) {
				$adapter_provider_uuid = constant("$adapter_class::OPENSMS_PROVIDER_UUID");
				if ($message->provider_uuid === $adapter_provider_uuid) {
					return $adapter_class;
				}
			}
		}

		// Delegate to next router
		if ($next !== null) {
			return $next($settings, $message);
		}

		return null;
	}
}
