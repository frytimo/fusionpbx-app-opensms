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
	$providers = $auto_loader->get_interface_list('opensms_provider');

	// Call app_defaults for each provider
	foreach ($providers as $provider_class) {
		$provider_class::app_defaults($database);
	}
}
