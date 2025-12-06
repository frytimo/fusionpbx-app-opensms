<?php

// Requires PHP 7.1 or higher
	version_compare(PHP_VERSION, '7.1.0', '<') and exit("PHP 7.1.0 or higher is required.");

// Include the required files
	require_once dirname(__DIR__, 2) . '/resources/require.php';

// Set globals
	/** @var settings $settings */
	global $settings;
	
// Requires FusionPBX 5.4.0 or higher
	$fusion_version = software::version();
	version_compare($fusion_version, '5.4.0', '<') and exit("FusionPBX 5.4.0 or higher is required.");

// Include functions
	require_once __DIR__ . '/resources/functions/opensms_functions.php';

// Create a new auto loader with cache disabled
	$auto_loader = new auto_loader(true);

// Get the list of providers, modifiers, and listeners
	$providers = $auto_loader->get_interface_list('opensms_provider');
	$modifiers = $auto_loader->get_interface_list('opensms_modifier');
	$listeners = $auto_loader->get_interface_list('opensms_listener');

// Call the providers to get messages
	$messages = opensms::messages($providers, $settings);
	foreach ($messages as $message) {
		opensms::modify($modifiers, $settings, $message);
		opensms::notify($listeners, $settings, $message);
	}

// Successful processing
	exit(0);
