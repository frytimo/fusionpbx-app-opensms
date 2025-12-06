<?php

// application details
	$apps[$x]['name'] = 'OpenSMS';
	$apps[$x]['uuid'] = '67cb7df9-f738-4555-8e09-3911f06a863e';
	$apps[$x]['category'] = 'System';
	$apps[$x]['subcategory'] = 'SMS';
	$apps[$x]['version'] = '1.00';
	$apps[$x]['license'] = 'MIT';
	$apps[$x]['url'] = 'http://github.com/frytimo/fusionpbx-app-opensms';
	$apps[$x]['description']['en-us'] = 'OpenSMS provides an interface to send and receive SMS messages through multiple providers.';

// Disable the autoloader cache
	$auto_loader = new auto_loader(false);

// Ensure database instance is available
	if (!isset($database) || !($database instanceof database)) {
		$database = database::new();
	}

//configuration from sub apps
	$opensms_providers = $auto_loader->get_interface_list('opensms_provider');
	foreach ($opensms_providers as $provider_class => $file_path) {
		$apps[$x] = $provider_class::app_config();
	}
