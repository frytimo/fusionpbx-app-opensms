<?php

/**
 * A callable chain of routers.
 *
 * Usage:
 *   $route = opensms_router_chain::build($router_classes, $adapter_classes);
 *   $adapter_class = $route($settings, $message);
 *   if ($adapter_class) {
 *       $adapter_class::send($settings, $message);
 *   }
 */
class opensms_router_chain {
	/**
	 * @var callable|null
	 */
	private $chain = null;

	/**
	 * @var array
	 */
	private $adapters = [];

	/**
	 * Build the chain from arrays of router and adapter class names.
	 *
	 * @param string[] $router_classes
	 * @param string[] $adapter_classes
	 * @return self
	 */
	public static function build(array $router_classes, array $adapter_classes): self {
		$instance           = new self();
		$instance->adapters = $adapter_classes;
		$routers            = [];

		foreach ($router_classes as $class) {
			$obj = new $class();
			if ($obj instanceof opensms_message_router) {
				$routers[] = $obj;
			}
		}

		// Build chain from end to start
		$adapters = $adapter_classes;
		$next     = null;
		foreach (array_reverse($routers) as $router) {
			$current = $next;
			$next    = function (settings $settings, opensms_message $message) use ($router, $adapters, $current) {
				return $router($settings, $message, $adapters, $current);
			};
		}

		$instance->chain = $next;

		return $instance;
	}

	/**
	 * Invoke the chain to find an adapter class for the message.
	 *
	 * @param settings $settings
	 * @param opensms_message $message
	 * @return string|null The adapter class name, or null if no match.
	 */
	public function __invoke(settings $settings, opensms_message $message): ?string {
		if ($this->chain !== null) {
			return ($this->chain)($settings, $message);
		}

		return null;
	}
}
