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
	 *
	 * @return self
	 */
	public static function build(array $router_classes): self {
		$instances = [];

		// Validate modifier classes
		foreach ($router_classes as $class) {
			$instance = new $class();
			if (!($instance instanceof opensms_message_router)) {
				throw new InvalidArgumentException("Router class $class does not implement opensms_message_router");
			}
			$instances[] = $instance;
		}

		$chain = new class($instances) implements opensms_message_router {
			private $router_classes;

			public function __construct(array $router_classes) {
				$this->router_classes = $router_classes;
			}

			public function __invoke(settings $settings, opensms_message $message): void {
				foreach ($this->router_classes as $router_class) {
					$router_class($settings, $message);
				}
			}

			public function priority(): int {
				return -1;  // build chain has to run before any other router
			}
		};

		return $chain;
	}

	/**
	 * Invoke the chain to find an adapter class for the message.
	 *
	 * @param settings        $settings
	 * @param opensms_message $message
	 *
	 * @return opensms_message_adapter|null The adapter class name, or null if no match.
	 */
	public function __invoke(settings $settings, opensms_message $message): ?opensms_message_adapter {
		if ($this->chain !== null) {
			return ($this->chain)($settings, $message);
		}

		return null;
	}
}
