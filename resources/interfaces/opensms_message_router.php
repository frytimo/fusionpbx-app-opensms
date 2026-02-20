<?php

/**
 * Interface for message routers.
 *
 * Routers determine which adapter should handle an outbound message.
 * They receive the list of discovered adapters and select the appropriate one.
 */
interface opensms_message_router {
	/**
	 * Attempt to resolve the adapter for the given outbound message.
	 *
	 * @param settings $settings Application settings.
	 * @param opensms_message $message The outbound message to route.
	 * @param array $adapters List of discovered adapter class names.
	 * @param callable|null $next The next router in the chain.
	 * @return opensms_message_adapter|null The adapter class name to use, or null to try next router.
	 */
	public function __invoke(settings $settings, opensms_message $message, ?callable $next): ?opensms_message_adapter;
}
