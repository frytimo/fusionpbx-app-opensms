<?php

/*
 * FusionPBX
 * Version: MPL 1.1
 *
 * The contents of this file are subject to the Mozilla Public License Version
 * 1.1 (the "License"); you may not use this file except in compliance with
 * the License. You may obtain a copy of the License at
 * http://www.mozilla.org/MPL/
 *
 * Software distributed under the License is distributed on an "AS IS" basis,
 * WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
 * for the specific language governing rights and limitations under the
 * License.
 *
 * The Original Code is FusionPBX
 *
 * The Initial Developer of the Original Code is
 * Mark J Crane <markjcrane@fusionpbx.com>
 * Portions created by the Initial Developer are Copyright (C) 2008-2025
 * the Initial Developer. All Rights Reserved.
 *
 * Contributor(s):
 * Mark J Crane <markjcrane@fusionpbx.com>
 * Tim Fry <tim@fusionpbx.com>
 */

// includes files
require_once dirname(__DIR__, 2) . "/resources/require.php";
require_once "resources/check_auth.php";

// check permissions
if (!permission_exists('sms_view')) {
	echo "access denied";
	exit;
}

// additional permissions
$send_enabled = permission_exists('sms_send');

// set default
$debug = false;

global $domain_uuid, $user_uuid, $settings, $database, $config;

if (empty($domain_uuid)) {
	$domain_uuid = $_SESSION['domain_uuid'] ?? '';
}

if (empty($user_uuid)) {
	$user_uuid = $_SESSION['user_uuid'] ?? '';
}

if (!($config instanceof config)) {
	$config = config::load();
}

if (!($database instanceof database)) {
	$database = database::new(['config' => $config]);
}

if (!($settings instanceof settings)) {
	$settings = new settings(['database' => $database, 'domain_uuid' => $domain_uuid, 'user_uuid' => $user_uuid]);
}

// get available texting destinations for this user
$destinations = [];
if ($send_enabled) {
	// build a list of groups the user is a member of
	$group_uuids = [];
	foreach($_SESSION['user']['groups'] ?? [] as $group) {
		if (is_uuid($group['group_uuid'] ?? $group)) {
			$group_uuids[] = $group['group_uuid'] ?? $group;
		}
	}
	$group_uuids_in = !empty($group_uuids) ? "'" . implode("','", $group_uuids) . "'" : "''";

	$sql = "select destination_uuid, destination_number, destination_description from v_destinations ";
	$sql .= "where domain_uuid = :domain_uuid ";
	$sql .= "and (user_uuid = :user_uuid OR group_uuid IN (".$group_uuids_in.")) ";
	$sql .= "and destination_type_text = 1 ";
	$sql .= "and destination_enabled = 'true' ";
	$sql .= "order by destination_number asc ";
	$parameters = [
		'domain_uuid' => $domain_uuid,
		'user_uuid' => $user_uuid
	];
	$destinations = $database->select($sql, $parameters, 'all');
	unset($sql, $parameters);
}

// add multi-lingual support
$language = new text;
$text     = $language->get();

// create token for websocket authentication
$token = (new token())->create($_SERVER['PHP_SELF']);

// pass the token to the subscriber class so that when this subscriber makes a websocket
// connection, the subscriber object can validate the information.
subscriber::save_token($token, ['opensms']);

// get websocket settings from default settings
$ws_settings = [
	'reconnect_delay'          => (int)$settings->get('opensms', 'reconnect_delay', 2000),
	'ping_interval'            => (int)$settings->get('opensms', 'ping_interval', 30000),
	'auth_timeout'             => (int)$settings->get('opensms', 'auth_timeout', 10000),
	'pong_timeout'             => (int)$settings->get('opensms', 'pong_timeout', 10000),
	'refresh_interval'         => (int)$settings->get('opensms', 'refresh_interval', 0),
	'max_reconnect_delay'      => (int)$settings->get('opensms', 'max_reconnect_delay', 30000),
	'pong_timeout_max_retries' => (int)$settings->get('opensms', 'pong_timeout_max_retries', 3),
];

// get theme colors for status indicator
$status_colors = [
	'connected'    => $settings->get('theme', 'opensms_status_connected', '#28a745'),
	'warning'      => $settings->get('theme', 'opensms_status_warning', '#ffc107'),
	'disconnected' => $settings->get('theme', 'opensms_status_disconnected', '#dc3545'),
	'connecting'   => $settings->get('theme', 'opensms_status_connecting', '#6c757d'),
];

// get status indicator mode and icons
$status_indicator_mode = $settings->get('theme', 'opensms_status_indicator_mode', 'color');
$status_icons = [
	'connected'    => $settings->get('theme', 'opensms_status_icon_connected', 'fa-solid fa-plug-circle-check'),
	'warning'      => $settings->get('theme', 'opensms_status_icon_warning', 'fa-solid fa-plug-circle-exclamation'),
	'disconnected' => $settings->get('theme', 'opensms_status_icon_disconnected', 'fa-solid fa-plug-circle-xmark'),
	'connecting'   => $settings->get('theme', 'opensms_status_icon_connecting', 'fa-solid fa-plug fa-fade'),
];

// get status tooltips from translations
$status_tooltips = [
	'connected'    => $text['status-connected'] ?? 'Connected',
	'warning'      => $text['status-warning'] ?? 'Warning',
	'disconnected' => $text['status-disconnected'] ?? 'Disconnected',
	'connecting'   => $text['status-connecting'] ?? 'Connecting',
];

// get websocket URL (use wss:// for secure connections, proxied through nginx)
$ws_protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'wss' : 'ws';
$ws_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$ws_path = $settings->get('websocket', 'path', '/websockets/');
$ws_url = $ws_protocol . '://' . $ws_host . $ws_path;

// show the header
$document['title'] = $text['title-sms'] ?? 'SMS Messages';
require_once dirname(__DIR__, 2) . "/resources/header.php";

// generate version hash for cache busting
$ws_client_file = __DIR__ . '/resources/javascript/websocket_client.js';
$ws_client_hash = file_exists($ws_client_file) ? md5_file($ws_client_file) : '1';

$ui_js_file = __DIR__ . '/resources/javascript/opensms_ui.js';
$ui_js_hash = file_exists($ui_js_file) ? md5_file($ui_js_file) : '1';

$css_file = __DIR__ . '/resources/css/opensms.css';
$css_hash = file_exists($css_file) ? md5_file($css_file) : '1';

// show the content
$open_sms = new opensms_smarty_template();
$open_sms->assign('destinations', $destinations);
$open_sms->assign('send_enabled', $send_enabled);
$open_sms->assign('text', $text);
$open_sms->assign('token', $token);
$open_sms->assign('app_path', PROJECT_PATH . '/app/opensms');
$open_sms->assign('domain_uuid', $domain_uuid);
$open_sms->assign('domain_name', $_SESSION['domain_name'] ?? '');
$open_sms->assign('user_uuid', $user_uuid);
$open_sms->assign('ws_url', $ws_url);
$open_sms->assign('ws_settings', $ws_settings);
$open_sms->assign('status_colors', $status_colors);
$open_sms->assign('status_icons', $status_icons);
$open_sms->assign('status_tooltips', $status_tooltips);
$open_sms->assign('status_indicator_mode', $status_indicator_mode);
$open_sms->assign('asset_version', $ws_client_hash);
$open_sms->assign('ws_client_hash', $ws_client_hash);
$open_sms->assign('ui_js_hash', $ui_js_hash);
$open_sms->assign('css_hash', $css_hash);

// get initial message threads for the user
$threads = [];
$sql = "SELECT DISTINCT ";
$sql .= "CASE WHEN message_direction = 'inbound' THEN message_from ELSE message_to END as thread_number, ";
$sql .= "MAX(message_date) as last_date, ";
$sql .= "COUNT(CASE WHEN message_read = false AND message_direction = 'inbound' THEN 1 END) as unread_count ";
$sql .= "FROM v_messages ";
$sql .= "WHERE domain_uuid = :domain_uuid ";
$sql .= "AND (user_uuid = :user_uuid OR message_from IN (SELECT destination_number FROM v_destinations WHERE domain_uuid = :domain_uuid AND destination_type_text = 1)) ";
$sql .= "AND (CASE WHEN message_direction = 'inbound' THEN message_from ELSE message_to END) NOT IN (SELECT user_setting_name FROM v_user_settings WHERE domain_uuid = :domain_uuid AND user_uuid = :user_uuid AND user_setting_category = 'opensms' AND user_setting_subcategory = 'hidden_thread' AND user_setting_enabled = 'true') ";
$sql .= "GROUP BY thread_number ";
$sql .= "ORDER BY last_date DESC ";
$sql .= "LIMIT 50";
$parameters = [
	'domain_uuid' => $domain_uuid,
	'user_uuid' => $user_uuid,
];
$thread_results = $database->select($sql, $parameters, 'all');
if (!empty($thread_results)) {
	foreach ($thread_results as $row) {
		$threads[] = [
			'thread_id' => $row['thread_number'],
			'label' => format_phone($row['thread_number']),
			'last_preview' => '',
			'unread_count' => (int)$row['unread_count']
		];
	}
}
unset($sql, $parameters);
$open_sms->assign('threads', $threads);

$open_sms->display();

// show the footer
require_once dirname(__DIR__, 2) . "/resources/footer.php";
