<?php

// application details
    /** @var int $x */
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
	$auto_loader->reload_classes();

//configuration from sub apps
	$adapters = $auto_loader->get_interface_list('opensms_message_adapter');
	foreach ($adapters as $adapter_class) {
        // $x is declared in caller and must not be declared here
        /** @var int $x */
        $opensms_config = $adapter_class::app_config();
        if ($opensms_config !== null) {
            $apps[$x] = $opensms_config;
            $x++;
        }
	}
