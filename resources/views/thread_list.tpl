{* resources/views/thread_list.tpl *}

{*
	Expected variables:
	- $threads: array of conversation threads
		[
			{
				thread_id: string
				label: string          (number or contact name)
				last_preview: string   (optional)
				unread_count: int      (optional)
			}
		]
*}

<div class="opensms_threads_header">
	<div class="opensms_title">
		<div class="opensms_title_main">OpenSMS</div>
		<div class="opensms_title_sub">Messages</div>
	</div>

	<button
		type="button"
		class="opensms_btn opensms_btn_secondary"
		id="opensms_btn_contacts"
		title="Show contacts">
		Contacts
	</button>
</div>

<div class="opensms_threads_search">
	<input
		type="search"
		class="opensms_input"
		id="opensms_thread_search"
		placeholder="Search number or name"
		autocomplete="off">
</div>

<nav
	class="opensms_thread_list"
	id="opensms_thread_list"
	role="tablist"
	aria-orientation="vertical">

	{if $threads|@count > 0}
		{foreach from=$threads item=thread name=thread_loop}
			<button
				type="button"
				role="tab"
				class="opensms_thread_item {if $smarty.foreach.thread_loop.first}is_active{/if}"
				aria-selected="{if $smarty.foreach.thread_loop.first}true{else}false{/if}"
				data-thread-id="{$thread.thread_id|escape:'html'}">

				<div class="opensms_thread_top">
					<div class="opensms_thread_label">
						{$thread.label|escape:'html'}
					</div>
					<div class="opensms_thread_actions">
						<span role="button" tabindex="0" class="opensms_thread_action" data-action="block" data-thread-id="{$thread.thread_id|escape:'html'}" title="Block / Unblock">
							<span class="fas fa-ban"></span>
						</span>
						<span role="button" tabindex="0" class="opensms_thread_action" data-action="delete" data-thread-id="{$thread.thread_id|escape:'html'}" title="Hide conversation">
							<span class="fas fa-trash"></span>
						</span>
					</div>

					{if $thread.unread_count|default:0 > 0}
						<div class="opensms_badge">
							{$thread.unread_count}
						</div>
					{/if}
				</div>

				{if $thread.last_preview|default:''}
					<div class="opensms_thread_preview">
						{$thread.last_preview|escape:'html'}
					</div>
				{/if}
			</button>
		{/foreach}
	{else}
		<div class="opensms_empty_note">
			No conversations yet.
		</div>
	{/if}

</nav>

<div class="opensms_threads_footer">
	<button
		type="button"
		class="opensms_btn opensms_btn_primary"
		id="opensms_btn_new_thread">
		New message
	</button>
</div>
