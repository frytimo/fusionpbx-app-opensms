{* resources/views/contacts_modal.tpl *}

{*
	Contacts modal for selecting a recipient from contacts.
	Expected variables:
	- $text: array of translations
*}

<div class="opensms_modal_backdrop" id="opensms_contacts_backdrop" hidden>
	<div class="opensms_modal">
		<div class="opensms_modal_header">
			<div class="opensms_modal_title">
				{$text['label-contacts']|default:'Contacts'}
			</div>
			<button type="button" class="opensms_btn opensms_btn_icon" id="opensms_btn_contacts_close" title="{$text['button-close']|default:'Close'}">
				<span class="fas fa-times"></span>
			</button>
		</div>
		<div class="opensms_modal_body">
			<div class="opensms_contacts_search">
				<input
					type="search"
					class="opensms_input"
					id="opensms_contacts_search_input"
					placeholder="{$text['placeholder-search_contacts']|default:'Search contacts...'}"
					autocomplete="off">
			</div>
			<div class="opensms_contacts_list" id="opensms_contacts_list">
				<div class="opensms_empty_note">
					{$text['notice-no_contacts']|default:'No contacts available. Type a phone number to start a new conversation.'}
				</div>
			</div>

			<div class="opensms_hidden_section">
				<div class="opensms_hidden_header">
					<button type="button" class="opensms_btn opensms_btn_secondary opensms_btn_small" id="opensms_btn_show_hidden" title="Show hidden conversations">
						<span class="fas fa-eye-slash"></span> Hidden conversations
					</button>
				</div>
				<div class="opensms_hidden_list" id="opensms_hidden_list" hidden>
					<div class="opensms_empty_note" id="opensms_hidden_empty">
						No hidden conversations.
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
