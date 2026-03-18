<?php

// application details
	/** @var int $x */
	$apps[$x]['name']                 = 'OpenSMS';
	$apps[$x]['uuid']                 = '67cb7df9-f738-4555-8e09-3911f06a863e';
	$apps[$x]['category']             = 'System';
	$apps[$x]['subcategory']          = 'SMS';
	$apps[$x]['version']              = '1.00';
	$apps[$x]['license']              = 'MIT';
	$apps[$x]['url']                  = 'http://github.com/frytimo/fusionpbx-app-opensms';
	$apps[$x]['description']['en-us'] = 'OpenSMS provides an interface to send and receive SMS messages through multiple providers.';

// providers
	$y=0;
	$apps[$x]['db'][$y]['table']['name']                = 'v_providers';
	$apps[$x]['db'][$y]['table']['parent']              = '';
	$z=0;
	$apps[$x]['db'][$y]['fields'][$z]['name']           = 'provider_uuid';
	$apps[$x]['db'][$y]['fields'][$z]['type']['pgsql']  = 'uuid';
	$apps[$x]['db'][$y]['fields'][$z]['type']['sqlite'] = 'text';
	$apps[$x]['db'][$y]['fields'][$z]['type']['mysql']  = 'char(36)';
	$apps[$x]['db'][$y]['fields'][$z]['key']['type']    = 'primary';
	$z++;
	$apps[$x]['db'][$y]['fields'][$z]['name']                      = 'domain_uuid';
	$apps[$x]['db'][$y]['fields'][$z]['type']['pgsql']             = 'uuid';
	$apps[$x]['db'][$y]['fields'][$z]['type']['sqlite']            = 'text';
	$apps[$x]['db'][$y]['fields'][$z]['type']['mysql']             = 'char(36)';
	$apps[$x]['db'][$y]['fields'][$z]['key']['type']               = 'foreign';
	$apps[$x]['db'][$y]['fields'][$z]['key']['reference']['table'] = 'v_domains';
	$apps[$x]['db'][$y]['fields'][$z]['key']['reference']['field'] = 'domain_uuid';
	$z++;
	$apps[$x]['db'][$y]['fields'][$z]['name']                 = 'provider_name';
	$apps[$x]['db'][$y]['fields'][$z]['type']                 = 'text';
	$apps[$x]['db'][$y]['fields'][$z]['search_by']            = '1';
	$apps[$x]['db'][$y]['fields'][$z]['description']['en-us'] = 'Enter the provider name.';
	$z++;
	$apps[$x]['db'][$y]['fields'][$z]['name']                 = 'provider_enabled';
	$apps[$x]['db'][$y]['fields'][$z]['type']                 = 'boolean';
	$apps[$x]['db'][$y]['fields'][$z]['toggle']               = ['true', 'false'];
	$apps[$x]['db'][$y]['fields'][$z]['description']['en-us'] = 'Enter the provider enabled.';
	$z++;
	$apps[$x]['db'][$y]['fields'][$z]['name']                 = 'provider_description';
	$apps[$x]['db'][$y]['fields'][$z]['type']                 = 'text';
	$apps[$x]['db'][$y]['fields'][$z]['search_by']            = '1';
	$apps[$x]['db'][$y]['fields'][$z]['description']['en-us'] = 'Enter the provider description.';
	$z++;
	$apps[$x]['db'][$y]['fields'][$z]['name']                 = "insert_date";
	$apps[$x]['db'][$y]['fields'][$z]['type']['pgsql']        = 'timestamptz';
	$apps[$x]['db'][$y]['fields'][$z]['type']['sqlite']       = 'date';
	$apps[$x]['db'][$y]['fields'][$z]['type']['mysql']        = 'date';
	$apps[$x]['db'][$y]['fields'][$z]['description']['en-us'] = "";
	$z++;
	$apps[$x]['db'][$y]['fields'][$z]['name']                 = "insert_user";
	$apps[$x]['db'][$y]['fields'][$z]['type']['pgsql']        = "uuid";
	$apps[$x]['db'][$y]['fields'][$z]['type']['sqlite']       = "text";
	$apps[$x]['db'][$y]['fields'][$z]['type']['mysql']        = "char(36)";
	$apps[$x]['db'][$y]['fields'][$z]['description']['en-us'] = "";
	$z++;
	$apps[$x]['db'][$y]['fields'][$z]['name']                 = "update_date";
	$apps[$x]['db'][$y]['fields'][$z]['type']['pgsql']        = 'timestamptz';
	$apps[$x]['db'][$y]['fields'][$z]['type']['sqlite']       = 'date';
	$apps[$x]['db'][$y]['fields'][$z]['type']['mysql']        = 'date';
	$apps[$x]['db'][$y]['fields'][$z]['description']['en-us'] = "";
	$z++;
	$apps[$x]['db'][$y]['fields'][$z]['name']                 = "update_user";
	$apps[$x]['db'][$y]['fields'][$z]['type']['pgsql']        = "uuid";
	$apps[$x]['db'][$y]['fields'][$z]['type']['sqlite']       = "text";
	$apps[$x]['db'][$y]['fields'][$z]['type']['mysql']        = "char(36)";
	$apps[$x]['db'][$y]['fields'][$z]['description']['en-us'] = "";
// destinations
	$y=0;
	$apps[$x]['db'][$y]['table']['name'] = "v_destinations";
	$apps[$x]['db'][$y]['table']['parent'] = "";
	$z=0;
	$apps[$x]['db'][$y]['fields'][$z]['name'] = 'provider_uuid';
	$apps[$x]['db'][$y]['fields'][$z]['type']['pgsql']  = 'uuid';
	$apps[$x]['db'][$y]['fields'][$z]['type']['sqlite'] = 'text';
	$apps[$x]['db'][$y]['fields'][$z]['type']['mysql']  = 'char(36)';
	$apps[$x]['db'][$y]['fields'][$z]['key']['type']    = 'foreign';
	$apps[$x]['db'][$y]['fields'][$z]['key']['reference']['table'] = 'v_providers';
	$apps[$x]['db'][$y]['fields'][$z]['key']['reference']['field'] = 'provider_uuid';

// permissions
	$y = 0;
	$apps[$x]['permissions'][$y]['name'] = "sms_view";
	$apps[$x]['permissions'][$y]['groups'][] = "superadmin";
	$apps[$x]['permissions'][$y]['groups'][] = "admin";
	$apps[$x]['permissions'][$y]['groups'][] = "user";
	$y++;
	$apps[$x]['permissions'][$y]['name'] = "sms_send";
	$apps[$x]['permissions'][$y]['groups'][] = "superadmin";
	$apps[$x]['permissions'][$y]['groups'][] = "admin";
	$apps[$x]['permissions'][$y]['groups'][] = "user";

// configuration from adapters
	$auto_loader = new auto_loader(false);
	$auto_loader->reload_classes();
	$adapters = $auto_loader->get_interface_list('opensms_message_adapter');

// Merge all default settings categories from adapters to treat them as part of the main app
	foreach ($adapters as $adapter_class) {
		// $x is declared in caller (upgrade index.php file) and must not be declared here
		$opensms_config = $adapter_class::app_config();
		// Check the adapter has a valid configuration array
		if ($opensms_config !== null && is_array($opensms_config)) {
			// Iterate over the configuration categories of the main app and the adapter config
			foreach ($opensms_config as $category => $value) {
				if (is_array($value)) {
					// Compare the category names and merge if they exist in the adapter config
					if (isset($apps[$x][$category]) && is_array($apps[$x][$category])) {
						$apps[$x][$category] = array_merge($apps[$x][$category], $opensms_config[$category]);
					} else {
						// If the category doesn't exist in the main app, add it directly from the adapter
						$apps[$x][$category] = $opensms_config[$category];
					}
				}
			}
		}
	}
