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

	// Debug output - temporarily add this to see what's happening
	if (empty($destinations)) {
		echo "<!-- DEBUG: No destinations found. Send enabled: " . ($send_enabled ? 'true' : 'false') . ". Domain UUID: $domain_uuid. User UUID: $user_uuid. Groups: " . json_encode($group_uuids) . " -->";
		// Also check if there are any destinations with destination_type_text = 1 at all
		$debug_sql = "select count(*) as count from v_destinations where domain_uuid = :domain_uuid and destination_type_text = 1 and destination_enabled = 'true'";
		$debug_result = $database->select($debug_sql, ['domain_uuid' => $domain_uuid], 'column');
		echo "<!-- DEBUG: Total text destinations in domain: $debug_result -->";
	}

	unset($sql, $parameters);
}

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

if (!($settings instanceof settings)) {
	$settings = new settings(['database' => $database, 'domain_uuid' => $domain_uuid, 'user_uuid' => $user_uuid]);
}

// add multi-lingual support
$language = new text;
$text     = $language->get();

$token = (new token())->create($_SERVER['PHP_SELF']);

// show the header
$document['title'] = $text['title-sms'] ?? 'SMS Messages';
require_once dirname(__DIR__, 2) . "/resources/header.php";

// show the content
$open_sms = new opensms_smarty_template();
$open_sms->assign('destinations', $destinations);
$open_sms->assign('send_enabled', $send_enabled);
$open_sms->assign('text', $text);
$open_sms->assign('token', $token);
$open_sms->assign('app_path', basename(__DIR__));

//$open_sms->assign('threads', opensms::get_messages($user_uuid));
$open_sms->display();

// show the footer
require_once dirname(__DIR__, 2) . "/resources/footer.php";
