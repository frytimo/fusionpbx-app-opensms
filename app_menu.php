<?php

$y = 0;
$apps[$x]['menu'][$y]['title']['en-us'] = "SMS Messages";
$apps[$x]['menu'][$y]['title']['en-gb'] = "SMS Messages";
$apps[$x]['menu'][$y]['title']['ar-eg'] = "رسائل SMS";
$apps[$x]['menu'][$y]['title']['de-de'] = "SMS-Nachrichten";
$apps[$x]['menu'][$y]['title']['es-cl'] = "Mensajes SMS";
$apps[$x]['menu'][$y]['title']['es-mx'] = "Mensajes SMS";
$apps[$x]['menu'][$y]['title']['fr-ca'] = "Messages SMS";
$apps[$x]['menu'][$y]['title']['fr-fr'] = "Messages SMS";
$apps[$x]['menu'][$y]['title']['he-il'] = "הודעות SMS";
$apps[$x]['menu'][$y]['title']['it-it'] = "Messaggi SMS";
$apps[$x]['menu'][$y]['title']['nl-nl'] = "SMS-berichten";
$apps[$x]['menu'][$y]['title']['pl-pl'] = "Wiadomości SMS";
$apps[$x]['menu'][$y]['title']['pt-br'] = "Mensagens SMS";
$apps[$x]['menu'][$y]['title']['pt-pt'] = "Mensagens SMS";
$apps[$x]['menu'][$y]['title']['ru-ru'] = "SMS сообщения";
$apps[$x]['menu'][$y]['title']['sv-se'] = "SMS-meddelanden";
$apps[$x]['menu'][$y]['title']['uk-ua'] = "SMS повідомлення";
$apps[$x]['menu'][$y]['uuid'] = "b5f5e1a4-3c2d-4e8f-9a7b-1d6c8e2f4a9b";
$apps[$x]['menu'][$y]['parent_uuid'] = "fd29e39c-c936-f5fc-8e2b-611681b266b5";
$apps[$x]['menu'][$y]['category'] = "internal";
$apps[$x]['menu'][$y]['icon'] = "";
$apps[$x]['menu'][$y]['path'] = "/app/opensms/opensms.php";
$apps[$x]['menu'][$y]['order'] = "";
$apps[$x]['menu'][$y]['groups'][] = "superadmin";
$apps[$x]['menu'][$y]['groups'][] = "admin";
$apps[$x]['menu'][$y]['groups'][] = "user";

// configuration from sub apps
	$auto_loader = new auto_loader(false);
	$auto_loader->reload_classes();

	$adapters = $auto_loader->get_interface_list('opensms_message_adapter');
	foreach ($adapters as $adapter_class) {
		$_menu = [];
		// $x is declared in caller and must not be declared here
		/** @var int $x */
		$opensms_menu = $adapter_class::app_menu();
		if ($opensms_menu !== null) {
			$apps[$x]['menu'] = array_merge($apps[$x]['menu'], $opensms_menu);
		}
	}
