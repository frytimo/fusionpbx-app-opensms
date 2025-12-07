<?php

// Only run this code once for all domains
if ($domains_processed === 1) {

	// Disable the autoloader cache
	$auto_loader = new auto_loader(false);
	$auto_loader->reload_classes();

	if (!isset($database) || !($database instanceof database)) {
		$database = database::new();
	}

	// Get the list of providers
	$adapters = $auto_loader->get_interface_list('opensms_message_adapter');

	// Call app_defaults for each provider
	foreach ($adapters as $adapter_class) {
		$adapter_class::app_defaults($database);
	}
}
