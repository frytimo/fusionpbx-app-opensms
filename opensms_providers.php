<?php

// includes files
require_once dirname(__DIR__, 2) . "/resources/require.php";
require_once "resources/check_auth.php";

// check permissions
if (!permission_exists('opensms_provider_import')) {
	echo "access denied";
	exit;
}

// language
$language = new text;
$text     = $language->get();

// database
$database = database::new();

// defaults
$default_source_url    = 'https://raw.githubusercontent.com/fusionpbx/fusionpbx-app-messages/refs/heads/main/resources/providers/settings.php';
$source_url            = $_POST['source_url'] ?? $default_source_url;
$array_payload         = $_POST['array_payload'] ?? '';
$mode                  = $_POST['mode'] ?? 'new-only';
$provider_filter_input = $_POST['provider_filter'] ?? '';
$source_type           = $_POST['source_type'] ?? 'url';
$preview               = null;
$import_result         = null;

// token
$token = new token;

if (!empty($_POST['action'])) {
	if (!$token->validate($_SERVER['PHP_SELF'])) {
		message::add($text['message-invalid_token'], 'negative');
		header('Location: opensms_providers.php');
		exit;
	}

	$provider_filter = [];
	if (!empty($provider_filter_input)) {
		foreach (explode(',', $provider_filter_input) as $name) {
			$name = strtolower(trim($name));
			if ($name !== '') {
				$provider_filter[] = $name;
			}
		}
	}

	try {
		if ($_POST['action'] === 'preview') {
			if ($source_type === 'inline') {
				$preview = array_adapter::list_from_php_content($array_payload);
			} else {
				$preview = array_adapter::list_from_url($source_url);
			}
		}

		if ($_POST['action'] === 'import') {
			$options = [
				'mode'            => $mode,
				'provider_filter' => $provider_filter,
			];

			if ($source_type === 'inline') {
				$import_result = array_adapter::import_from_php_content($database, $array_payload, $options);
			} else {
				$import_result = array_adapter::import_from_url($database, $source_url, $options);
			}
		}
	} catch (Throwable $e) {
		message::add($e->getMessage(), 'negative');
	}
}

$token_hash = $token->create($_SERVER['PHP_SELF']);

// include the header
$document['title'] = $text['title-opensms-providers'] ?? 'OpenSMS Provider Import';
require_once "resources/header.php";

echo "<div class='action_bar' id='action_bar'>\n";
echo "\t<div class='heading'><b>" . escape($text['title-opensms-providers'] ?? 'OpenSMS Provider Import') . "</b></div>\n";
echo "\t<div class='actions'>\n";
echo button::create(['type' => 'button', 'label' => $text['button-back'], 'icon' => $settings->get('theme', 'button_icon_back'), 'id' => 'btn_back', 'link' => 'opensms.php']);
echo "\t</div>\n";
echo "\t<div style='clear: both;'></div>\n";
echo "</div>\n";

echo "<div class='card'>\n";
echo "<div class='heading'><b>" . escape($text['label-opensms-provider-import'] ?? 'OpenSMS Provider Import') . "</b></div>\n";
echo "<p>" . escape($text['description-opensms-provider-import'] ?? 'Import provider arrays for native OpenSMS trigger use.') . "</p>\n";
echo "<form method='post'>\n";
echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
echo "<tr>\n";
echo "\t<td class='vncell'>Source</td>\n";
echo "\t<td class='vtable'>\n";
echo "\t\t<select class='formfld' name='source_type' onchange='this.form.submit()'>\n";
echo "\t\t\t<option value='url'" . ($source_type === 'url' ? ' selected' : '') . ">URL</option>\n";
echo "\t\t\t<option value='inline'" . ($source_type === 'inline' ? ' selected' : '') . ">Inline PHP payload</option>\n";
echo "\t\t</select>\n";
echo "\t</td>\n";
echo "</tr>\n";

if ($source_type === 'url') {
	echo "<tr>\n";
	echo "\t<td class='vncellreq'>Source URL</td>\n";
	echo "\t<td class='vtable'><input class='formfld' type='text' style='width: 100%;' name='source_url' value=\"" . escape($source_url) . "\"></td>\n";
	echo "</tr>\n";
} else {
	echo "<tr>\n";
	echo "\t<td class='vncellreq'>Array Payload</td>\n";
	echo "\t<td class='vtable'><textarea class='formfld' name='array_payload' rows='14' style='width: 100%;'>" . escape($array_payload) . "</textarea><br>\n";
	echo "\t<small>Paste PHP content containing \$array['providers'] entries.</small></td>\n";
	echo "</tr>\n";
}

echo "<tr>\n";
echo "\t<td class='vncell'>Mode</td>\n";
echo "\t<td class='vtable'>\n";
echo "\t\t<select class='formfld' name='mode'>\n";
echo "\t\t\t<option value='new-only'" . ($mode === 'new-only' ? ' selected' : '') . ">New only (skip existing)</option>\n";
echo "\t\t\t<option value='upsert'" . ($mode === 'upsert' ? ' selected' : '') . ">Upsert (update existing)</option>\n";
echo "\t\t</select>\n";
echo "\t</td>\n";
echo "</tr>\n";

echo "<tr>\n";
echo "\t<td class='vncell'>Provider filter</td>\n";
echo "\t<td class='vtable'><input class='formfld' type='text' style='width: 100%;' name='provider_filter' value=\"" . escape($provider_filter_input) . "\"><br>\n";
echo "\t<small>Optional comma-separated provider names. Example: bandwidth.com, twilio.com</small></td>\n";
echo "</tr>\n";
echo "</table>\n";
echo "<br>\n";
echo button::create(['type' => 'submit', 'label' => 'Preview', 'icon' => $settings->get('theme', 'button_icon_search'), 'name' => 'action', 'value' => 'preview']);
echo button::create(['type' => 'submit', 'label' => 'Import', 'icon' => $settings->get('theme', 'button_icon_add'), 'name' => 'action', 'value' => 'import', 'style' => 'margin-left: 8px;']);
echo "<input type='hidden' name='" . $token_hash['name'] . "' value='" . $token_hash['hash'] . "'>\n";
echo "</form>\n";

if (is_array($preview) && !empty($preview['providers'])) {
	echo "<br><br>\n";
	echo "<strong>Preview Providers (" . count($preview['providers']) . ")</strong><br>\n";
	echo "<ul>\n";
	foreach ($preview['providers'] as $provider_name) {
		echo "<li>" . escape($provider_name) . "</li>\n";
	}
	echo "</ul>\n";
}

if (is_array($import_result)) {
	echo "<br><br>\n";
	echo "<strong>Import Result</strong><br>\n";
	echo "Source: " . escape($import_result['normalized_url']) . "<br>\n";
	echo "Total: " . escape((string) $import_result['total']) . " | Imported: " . escape((string) $import_result['imported']) . " | Updated: " . escape((string) $import_result['updated']) . " | Skipped: " . escape((string) $import_result['skipped']) . " | Failed: " . escape((string) $import_result['failed']) . "<br><br>\n";

	echo "<table class='list'>\n";
	echo "<tr class='list-header'><th>Provider</th><th>Status</th><th>Message</th></tr>\n";
	foreach ($import_result['details'] as $detail) {
		echo "<tr class='list-row'>\n";
		echo "<td>" . escape($detail['provider_name']) . "</td>\n";
		echo "<td>" . escape($detail['status']) . "</td>\n";
		echo "<td>" . escape($detail['message']) . "</td>\n";
		echo "</tr>\n";
	}
	echo "</table>\n";
}

echo "</div>\n";

// include the footer
require_once "resources/footer.php";
