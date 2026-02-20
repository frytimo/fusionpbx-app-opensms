{* resources/views/message_composer.tpl *}

{*
	Message composer component for typing and sending messages.
	Expected variables:
	- $text: array of translations
	- $destinations: array of available sending destinations
	- $send_enabled: boolean indicating if user can send messages
*}

{if $send_enabled}
<div class="opensms_composer">
	<form id="opensms_send_form" class="opensms_composer_form">

		{* From destination selector *}
		{if $destinations|@count > 1}
		<div class="opensms_composer_from">
			<label for="opensms_from_destination" class="opensms_composer_label">
				{$text['label-from']|default:'From'}:
			</label>
			<select id="opensms_from_destination" name="from_destination" class="opensms_select">
				{foreach from=$destinations item=dest}
					<option value="{$dest.destination_uuid|escape:'html'}"
						data-number="{$dest.destination_number|escape:'html'}">
						{$dest.destination_number|escape:'html'}
						{if $dest.destination_description}
							- {$dest.destination_description|escape:'html'}
						{/if}
					</option>
				{/foreach}
			</select>
		</div>
		{elseif $destinations|@count == 1}
			<input type="hidden" id="opensms_from_destination" name="from_destination"
				value="{$destinations[0].destination_uuid|escape:'html'}"
				data-number="{$destinations[0].destination_number|escape:'html'}">
		{/if}

		{* Message input area *}
		<div class="opensms_composer_input_row">
			<div class="opensms_composer_input_wrapper">
				<textarea
					id="opensms_message_body"
					name="message_body"
					class="opensms_textarea"
					placeholder="{$text['placeholder-message']|default:'Type a message...'}"
					rows="1"
					maxlength="1600"
					autocomplete="off"></textarea>

				{* Attachment button *}
				<button type="button" class="opensms_btn opensms_btn_icon opensms_attach_btn" id="opensms_btn_attach" title="{$text['button-attach']|default:'Attach file'}">
					<span class="fas fa-paperclip"></span>
				</button>
				<input type="file" id="opensms_file_input" name="attachment" accept="image/*,video/*,audio/*" style="display: none;" multiple>
			</div>

			{* Send button *}
			<button type="submit" class="opensms_btn opensms_btn_primary opensms_send_btn" id="opensms_btn_send" title="{$text['button-send']|default:'Send'}">
				<span class="fas fa-paper-plane"></span>
			</button>
		</div>

		{* Character count *}
		<div class="opensms_composer_meta">
			<span id="opensms_char_count">0</span> / 1600
		</div>

	</form>
</div>
{else}
<div class="opensms_composer opensms_composer_disabled">
	<div class="opensms_composer_notice">
		{$text['notice-send_disabled']|default:'You do not have permission to send messages.'}
	</div>
</div>
{/if}
