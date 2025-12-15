{* app/opensms/resources/views/layout.tpl *}

<div id="opensms_app"
	data_ws_url="{$ws_url|escape:'html'}"
	data_domain_uuid="{$domain_uuid|escape:'html'}"
	data_user_uuid="{$user_uuid|escape:'html'}"
>

	<link rel="stylesheet" href="{$app_path}/resources/css/opensms.css?v={$asset_version|default:"1"}">

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

	<script src="{$app_path}/resources/js/opensms_ws.js?v={$asset_version|default:"1"}"></script>
	<script src="{$app_path}/resources/js/opensms_ui.js?v={$asset_version|default:"1"}"></script>
</div>
