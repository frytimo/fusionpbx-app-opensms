{* resources/views/chat_view.tpl *}

{*
	Chat view component for displaying messages in a conversation.
	Expected variables:
	- $text: array of translations
	- $destinations: array of available sending destinations
	- $send_enabled: boolean indicating if user can send messages
*}

<div class="opensms_chat_header">
	<div class="opensms_chat_info">
		<div class="opensms_chat_with_label" id="opensms_chat_with_label">
			{$text['label-select_conversation']|default:'Select a conversation'}
		</div>
		<div class="opensms_chat_with_sub">
			<span class="opensms_dot" id="opensms_chat_dot"></span>
			<span id="opensms_ws_status">{$text['label-connecting']|default:'Connecting...'}</span>
		</div>
	</div>
	<div class="opensms_chat_actions">
		{if $send_enabled}
			<button type="button" class="opensms_btn opensms_btn_icon" id="opensms_btn_call" title="{$text['button-call']|default:'Call'}">
				<span class="fas fa-phone"></span>
			</button>
		{/if}
		<button type="button" class="opensms_btn opensms_btn_icon" id="opensms_btn_block" title="Block / Unblock">
			<span class="fas fa-ban"></span>
		</button>
		<button type="button" class="opensms_btn opensms_btn_icon" id="opensms_btn_delete" title="Hide conversation">
			<span class="fas fa-trash"></span>
		</button>
		<button type="button" class="opensms_btn opensms_btn_icon" id="opensms_btn_info" title="{$text['button-info']|default:'Info'}">
			<span class="fas fa-info-circle"></span>
		</button>
	</div>
</div>

<div class="opensms_messages" id="opensms_messages">
	<div class="opensms_empty_chat">
		{$text['label-select_conversation']|default:'Select a conversation or start a new one.'}
	</div>
</div>
