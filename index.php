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

// Create a new auto loader with cache disabled
	$auto_loader = new auto_loader(true);
	$auto_loader->reload_classes();

// Get the list of consumers, adapters, modifiers, and listeners
	$consumers = $auto_loader->get_interface_list('opensms_message_consumer');
	$adapters  = $auto_loader->get_interface_list('opensms_message_adapter');
	$modifiers = $auto_loader->get_interface_list('opensms_message_modifier');
	$listeners = $auto_loader->get_interface_list('opensms_message_listener');

// Allow consumers to supply raw input (first non-empty payload wins).
	$consumer_chain = opensms::build_consumer_chain($consumers);
	$raw_payload_string = $consumer_chain($settings);

// Wrap the raw payload in the payload object for adapters
	$consumer_payload = new opensms_consumer_payload($raw_payload_string);

// Build the modifier chain
	$modify = opensms::build_modifier_chain($modifiers);
	$notify = opensms::build_listener_chain($listeners);

// Call the adapters to get messages
	$messages = opensms::messages($adapters, $settings, $consumer_payload);
	foreach ($messages as $message) {
		// Add additional information to the message
		$modify($settings, $message);
		// Notify all listeners with the message
		$notify($settings, $message);
	}

// Successful processing
	exit(0);
