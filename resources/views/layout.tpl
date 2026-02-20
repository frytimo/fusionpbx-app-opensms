{* app/opensms/resources/views/layout.tpl *}

<link rel="stylesheet" href="{$app_path}/resources/css/opensms.css?v={$css_hash|default:"1"}">

<style>
:root {
	--opensms-gap: 16px;
	--opensms-panel: #ffffff;
	--opensms-border: #d9dee5;
	--opensms-radius: 16px;
	--opensms-text: #111827;
	--opensms-muted: #6b7280;
	--opensms-inbound: #f3f4f6;
	--opensms-outbound: #dbeafe;
}
</style>

<script type="text/javascript">
// Websocket configuration from server settings
const opensms_ws_config = {$ws_settings|@json_encode nofilter};

// Status indicator colors from theme settings
const opensms_status_colors = {$status_colors|@json_encode nofilter};

// Status indicator icons from settings
const opensms_status_icons = {$status_icons|@json_encode nofilter};

// Status tooltips from translations
const opensms_status_tooltips = {$status_tooltips|@json_encode nofilter};

// Status indicator mode: 'color' or 'icon'
const opensms_status_indicator_mode = '{$status_indicator_mode|escape:"javascript"}';

// Authentication token
const opensms_token = {
	name: '{$token.name|escape:"javascript"}',
	hash: '{$token.hash|escape:"javascript"}'
};

// Domain and user information
const opensms_domain_name = '{$domain_name|escape:"javascript"}';
const opensms_domain_uuid = '{$domain_uuid|escape:"javascript"}';
const opensms_user_uuid = '{$user_uuid|escape:"javascript"}';

// Websocket URL
const opensms_ws_url = '{$ws_url|escape:"javascript"}';

// Available destinations for sending
const opensms_destinations = {$destinations|@json_encode nofilter};

// Send enabled flag
const opensms_send_enabled = {if $send_enabled}true{else}false{/if};

// Translations
const opensms_text = {$text|@json_encode nofilter};
</script>

<div id="opensms_app"
	data-ws-url="{$ws_url|escape:'html'}"
	data-domain-uuid="{$domain_uuid|escape:'html'}"
	data-user-uuid="{$user_uuid|escape:'html'}"
>

	<div class="action_bar" id="action_bar">
		<div class="heading">
			<b>{$text['title-sms']|default:'SMS Messages'}</b>
			{if $status_indicator_mode === 'icon'}
				<span id="opensms_connection_status" class="{$status_icons.connecting}" style="color: {$status_colors.connecting}; margin-left: 10px;" title="{$status_tooltips.connecting}"></span>
			{else}
				<span id="opensms_connection_status" class="count" style="margin-left: 10px; background-color: {$status_colors.connecting}; padding: 2px 8px; border-radius: 4px; font-size: 11px;">
					{$status_tooltips.connecting}
				</span>
			{/if}
		</div>
		<div class="actions">
			<button type="button" class="btn btn-default" id="opensms_btn_refresh" title="{$text['button-refresh']|default:'Refresh'}">
				<span class="fas fa-sync"></span>
				<span class="button-label">{$text['button-refresh']|default:'Refresh'}</span>
			</button>
		</div>
		<div style="clear: both;"></div>
	</div>

	<p class="no-wrap">{$text['description-sms']|default:'Real-time SMS message monitoring via websockets'}</p>
	<br>

	<div class="opensms_shell">

		<aside class="opensms_threads">
			{include file="thread_list.tpl"}
		</aside>

		<main class="opensms_chat">
			{include file="chat_view.tpl"}
			{include file="message_composer.tpl"}
		</main>

	</div>

	{include file="contacts_modal.tpl"}

	<input type="hidden" id="opensms_active_thread_id" value="">

</div>

<script src="{$app_path}/resources/javascript/websocket_client.js?v={$ws_client_hash|default:"1"}"></script>
<script src="{$app_path}/resources/javascript/opensms_ui.js?v={$ui_js_hash|default:"1"}"></script>

