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

(function () {
	"use strict";

	// Shorthand for getElementById
	function $(id) {
		return document.getElementById(id);
	}

	// Audio notification
	let notificationAudio = null;

	// WebSocket client instance
	let wsClient = null;

	// Current active thread
	let activeThread = null;
	const blockedNumbers = new Set();
	const hiddenThreads = new Set();

	/**
	 * Get the currently selected "from" destination number
	 */
	function getActiveFromNumber() {
		const fromSelect = $("opensms_from_destination");
		if (!fromSelect) return '';
		if (fromSelect.options && fromSelect.options.length > 0) {
			return fromSelect.options[fromSelect.selectedIndex].getAttribute('data-number') || fromSelect.options[fromSelect.selectedIndex].text || '';
		}
		return fromSelect.getAttribute('data-number') || '';
	}

	/**
	 * Update the chat header to show fromNumber <--> threadNumber
	 */
	function updateChatHeader(threadId) {
		const chatLabel = $("opensms_chat_with_label");
		if (!chatLabel) return;
		const fromNumber = getActiveFromNumber();
		if (fromNumber && threadId) {
			chatLabel.textContent = fromNumber + ' \u27F7 ' + threadId;
		} else if (threadId) {
			chatLabel.textContent = threadId;
		}
	}

	/**
	 * Update the connection status indicator
	 */
	function updateConnectionStatus(status, message = null) {
		const statusEl = $("opensms_connection_status");
		if (!statusEl) return;

		const mode = typeof opensms_status_indicator_mode !== 'undefined' ? opensms_status_indicator_mode : 'color';
		const colors = typeof opensms_status_colors !== 'undefined' ? opensms_status_colors : {};
		const icons = typeof opensms_status_icons !== 'undefined' ? opensms_status_icons : {};
		const tooltips = typeof opensms_status_tooltips !== 'undefined' ? opensms_status_tooltips : {};

		if (mode === 'icon') {
			// Clear existing icon classes and set new ones
			statusEl.className = icons[status] || icons.connecting || 'fa-solid fa-plug';
			statusEl.style.color = colors[status] || colors.connecting || '#6c757d';
			statusEl.title = tooltips[status] || message || status;
		} else {
			// Color/text mode
			statusEl.style.backgroundColor = colors[status] || colors.connecting || '#6c757d';
			statusEl.textContent = tooltips[status] || message || status;
			statusEl.title = tooltips[status] || message || status;
		}
	}

	/**
	 * Escape HTML to prevent XSS
	 */
	function escapeHtml(str) {
		if (!str) return '';
		return String(str)
			.replace(/&/g, "&amp;")
			.replace(/</g, "&lt;")
			.replace(/>/g, "&gt;")
			.replace(/"/g, "&quot;")
			.replace(/'/g, "&#039;");
	}

	/**
	 * Normalize a phone number for comparison
	 */
	function normalizeThreadId(value) {
		if (!value) return '';
		const digits = String(value).replace(/[^\d]/g, '');
		if (digits.length === 11 && digits.startsWith('1')) {
			return digits.slice(1);
		}
		return digits;
	}

	function applyHiddenThreads() {
		const list = $("opensms_thread_list");
		if (!list) return;
		list.querySelectorAll('.opensms_thread_item').forEach(function (item) {
			const itemThreadId = item.getAttribute('data-thread-id') || '';
			if (hiddenThreads.has(itemThreadId)) {
				item.remove();
			}
		});
	}

	function updateThreadBlockState(threadNumber, isBlocked) {
		const list = $("opensms_thread_list");
		if (!list) return;
		const item = list.querySelector(`[data-thread-id="${threadNumber}"]`);
		if (!item) return;
		item.classList.toggle('is_blocked', isBlocked);
	}

	/**
	 * Format timestamp for display
	 */
	function formatTime(timestamp) {
		if (!timestamp) return '';
		const date = new Date(timestamp);
		if (isNaN(date.getTime())) return timestamp;

		const now = new Date();
		const isToday = date.toDateString() === now.toDateString();

		if (isToday) {
			return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
		}
		return date.toLocaleDateString([], { month: 'short', day: 'numeric' }) +
			' ' + date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
	}

	/**
	 * Append a message bubble to the chat view
	 */
	function appendMessageBubble(msg) {
		const messagesContainer = $("opensms_messages");
		if (!messagesContainer) return;

		// Remove empty state message if present
		const emptyNote = messagesContainer.querySelector('.opensms_empty_chat');
		if (emptyNote) {
			emptyNote.remove();
		}

		const isOutbound = msg.direction === "outbound";
		const row = document.createElement("div");
		row.className = "opensms_bubble_row " + (isOutbound ? "is_outbound" : "is_inbound");
		row.setAttribute('data-message-uuid', msg.message_uuid || '');

		const bubble = document.createElement("div");
		bubble.className = "opensms_bubble";

		const body = document.createElement("div");
		body.className = "opensms_bubble_body";
		body.innerHTML = escapeHtml(msg.message_text || msg.body || '').replace(/\n/g, "<br>");

		const meta = document.createElement("div");
		meta.className = "opensms_bubble_meta";
		meta.innerHTML = "<span class='opensms_bubble_time'>" + escapeHtml(formatTime(msg.message_time || msg.time)) + "</span>";

		if (isOutbound && msg.status) {
			meta.innerHTML += "<span class='opensms_bubble_status' data-status='" + escapeHtml(msg.status) + "'>" + formatDeliveryStatus(msg.status) + "</span>";
		}

		// Show delivery status from history (stored in message_json)
		if (isOutbound && !msg.status && msg.message_json) {
			try {
				const djson = typeof msg.message_json === 'string' ? JSON.parse(msg.message_json) : msg.message_json;
				if (djson && djson.delivery_status) {
					meta.innerHTML += "<span class='opensms_bubble_status' data-status='" + escapeHtml(djson.delivery_status) + "'>" + formatDeliveryStatus(djson.delivery_status) + "</span>";
				}
			} catch (e) { /* ignore parse errors */ }
		}

		bubble.appendChild(body);
		bubble.appendChild(meta);
		row.appendChild(bubble);

		messagesContainer.appendChild(row);
		messagesContainer.scrollTop = messagesContainer.scrollHeight;
	}

	/**
	 * Clear all messages from the chat view
	 */
	function clearMessages() {
		const messagesContainer = $("opensms_messages");
		if (!messagesContainer) return;
		messagesContainer.innerHTML = '';
	}

	/**
	 * Show loading state in messages
	 */
	function showLoadingState() {
		const messagesContainer = $("opensms_messages");
		if (!messagesContainer) return;
		messagesContainer.innerHTML = '<div class="opensms_empty_chat">Loading messages...</div>';
	}

	/**
	 * Show empty state in messages
	 */
	function showEmptyState(text = 'No messages yet. Start a conversation!') {
		const messagesContainer = $("opensms_messages");
		if (!messagesContainer) return;
		messagesContainer.innerHTML = '<div class="opensms_empty_chat">' + escapeHtml(text) + '</div>';
	}

	/**
	 * Update thread unread count badge
	 */
	function updateThreadBadge(threadId, count) {
		const threadList = $("opensms_thread_list");
		if (!threadList) return;

		const threadItem = threadList.querySelector(`[data-thread-id="${threadId}"]`);
		if (!threadItem) return;

		let badge = threadItem.querySelector('.opensms_badge');
		if (count > 0) {
			if (!badge) {
				badge = document.createElement('div');
				badge.className = 'opensms_badge';
				const topDiv = threadItem.querySelector('.opensms_thread_top');
				if (topDiv) {
					topDiv.appendChild(badge);
				}
			}
			badge.textContent = count;
		} else if (badge) {
			badge.remove();
		}
	}

	/**
	 * Add new thread to the thread list
	 */
	function addNewThread(threadNumber, isActive = false) {
		const threadList = $("opensms_thread_list");
		if (!threadList) return;

		if (hiddenThreads.has(threadNumber)) {
			return;
		}

		// Check if thread already exists
		if (threadList.querySelector(`[data-thread-id="${threadNumber}"]`)) {
			return;
		}

		// Remove empty note if present
		const emptyNote = threadList.querySelector('.opensms_empty_note');
		if (emptyNote) {
			emptyNote.remove();
		}

		const btn = document.createElement('button');
		btn.type = 'button';
		btn.role = 'tab';
		btn.className = 'opensms_thread_item' + (isActive ? ' is_active' : '');
		btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
		btn.setAttribute('data-thread-id', threadNumber);

		btn.innerHTML = `
			<div class="opensms_thread_top">
				<div class="opensms_thread_label">${escapeHtml(threadNumber)}</div>
			</div>
		`;

		// Insert at the top of the list
		threadList.insertBefore(btn, threadList.firstChild);

		const normalized = normalizeThreadId(threadNumber);
		if (blockedNumbers.has(normalized)) {
			btn.classList.add('is_blocked');
		}
	}

	/**
	 * Play notification sound
	 */
	function playNotificationSound() {
		if (!notificationAudio) {
			notificationAudio = new Audio();
			// Use a default notification sound or configure from settings
		}
		try {
			notificationAudio.play();
		} catch (e) {
			// Autoplay may be blocked
			console.log('Could not play notification sound:', e);
		}
	}

	/**
	 * Handle incoming message from websocket
	 */
	function handleIncomingMessage(data) {
		console.log('OpenSMS UI: Incoming message:', data);

		// Determine the thread number based on direction
		const rawThreadNumber = data.direction === 'inbound' ? data.from_number : data.to_number;
		const normalizedIncoming = normalizeThreadId(rawThreadNumber);
		let threadNumber = rawThreadNumber || '';

		// Try to match an existing thread by normalized number
		const threadList = $("opensms_thread_list");
		if (threadList && normalizedIncoming) {
			const items = threadList.querySelectorAll('.opensms_thread_item');
			items.forEach(function (item) {
				const itemThreadId = item.getAttribute('data-thread-id') || '';
				if (normalizeThreadId(itemThreadId) === normalizedIncoming) {
					threadNumber = itemThreadId;
				}
			});
		}

		const normalizedThread = normalizeThreadId(threadNumber);
		if (blockedNumbers.has(normalizedThread) || hiddenThreads.has(threadNumber)) {
			return;
		}

		// Add thread if it doesn't exist
		if (threadNumber) {
			addNewThread(threadNumber, false);
		} else if (normalizedIncoming) {
			addNewThread(normalizedIncoming, false);
			threadNumber = normalizedIncoming;
		}

		// If this thread is currently active, display the message
		if (normalizeThreadId(activeThread) === normalizeThreadId(threadNumber)) {
			appendMessageBubble({
				message_uuid: data.message_uuid,
				direction: data.direction || 'inbound',
				message_text: data.message_text || data.sms,
				message_time: data.message_time || new Date().toISOString(),
				status: data.status
			});

			// Mark as read if inbound
			if (data.direction === 'inbound' && wsClient && wsClient.isConnected()) {
				wsClient.markAsRead(data.message_uuid);
			}
		} else {
			// Update unread badge for other threads
			if (data.direction === 'inbound') {
				const threadItem = document.querySelector(`[data-thread-id="${threadNumber}"]`);
				if (threadItem) {
					const badge = threadItem.querySelector('.opensms_badge');
					const currentCount = badge ? parseInt(badge.textContent) || 0 : 0;
					updateThreadBadge(threadNumber, currentCount + 1);
				}
			}
		}

		// Play notification sound for inbound messages
		if (data.direction === 'inbound') {
			playNotificationSound();
		}
	}

	/**
	 * Handle send response from websocket
	 */
	function handleSendResponse(data) {
		console.log('OpenSMS UI: Send response:', data);

		if (data.success) {
			// Update the message status to 'sent'
			const msgEl = document.querySelector(`[data-message-uuid="${data.message_uuid}"]`);
			if (msgEl) {
				updateBubbleStatus(msgEl, 'sent');
			}
		} else {
			// Show error
			console.error('OpenSMS: Failed to send message:', data.error);
			alert('Failed to send message: ' + (data.error || 'Unknown error'));
		}
	}

	/**
	 * Handle delivery receipt from carrier (Bandwidth callback)
	 */
	function handleDeliveryReceipt(data) {
		console.log('OpenSMS UI: Delivery receipt:', data);

		const originalUuid = data.original_message_uuid || '';
		const status = data.delivery_status || '';

		if (!originalUuid || !status) return;

		// Find the outbound message bubble by UUID
		const msgEl = document.querySelector('[data-message-uuid="' + originalUuid + '"]');
		if (msgEl) {
			updateBubbleStatus(msgEl, status);
		}
	}

	/**
	 * Update the delivery status indicator on a message bubble
	 */
	function updateBubbleStatus(bubbleRow, status) {
		const meta = bubbleRow.querySelector('.opensms_bubble_meta');
		if (!meta) return;

		let statusEl = meta.querySelector('.opensms_bubble_status');
		if (!statusEl) {
			statusEl = document.createElement('span');
			statusEl.className = 'opensms_bubble_status';
			meta.appendChild(statusEl);
		}

		statusEl.setAttribute('data-status', status);
		statusEl.innerHTML = formatDeliveryStatus(status);
	}

	/**
	 * Format a delivery status string with an icon for display
	 */
	function formatDeliveryStatus(status) {
		switch (status) {
			case 'sending':
				return '<i class="fas fa-clock"></i> Sending';
			case 'sent':
				return '<i class="fas fa-check"></i> Sent';
			case 'delivered':
				return '<i class="fas fa-check-double"></i> Delivered';
			case 'failed':
				return '<i class="fas fa-exclamation-triangle"></i> Failed';
			default:
				return escapeHtml(status);
		}
	}

	/**
	 * Initialize thread click handlers
	 */
	function initThreadClicks() {
		const list = $("opensms_thread_list");
		if (!list) return;

		list.addEventListener("click", function (ev) {
			const actionBtn = ev.target.closest('.opensms_thread_action');
			if (actionBtn) {
				ev.preventDefault();
				ev.stopPropagation();
				handleThreadAction(actionBtn);
				return;
			}

			const btn = ev.target.closest(".opensms_thread_item");
			if (!btn) return;

			// Toggle active state
			list.querySelectorAll(".opensms_thread_item").forEach(function (b) {
				b.classList.remove("is_active");
				b.setAttribute("aria-selected", "false");
			});
			btn.classList.add("is_active");
			btn.setAttribute("aria-selected", "true");

			// Update active thread
			const threadId = btn.getAttribute("data-thread-id") || "";
			activeThread = threadId;
			$("opensms_active_thread_id").value = threadId;

			// Update header label to show fromNumber <--> threadNumber
			updateChatHeader(threadId);

			// Clear unread badge
			updateThreadBadge(threadId, 0);

			// Load messages for this thread
			loadThreadMessages(threadId);
		});
	}

	function handleThreadAction(button) {
		const action = button.getAttribute('data-action') || '';
		const threadId = button.getAttribute('data-thread-id') || '';
		if (!threadId) return;

		if (action === 'delete') {
			if (!confirm('Hide this conversation for your user?')) return;
			if (wsClient && wsClient.isConnected()) {
				wsClient.hideThread(threadId, opensms_user_uuid, opensms_domain_uuid).then(function () {
					hiddenThreads.add(threadId);
					const item = button.closest('.opensms_thread_item');
					if (item) {
						item.remove();
					}
					if (activeThread === threadId) {
						activeThread = null;
						$("opensms_active_thread_id").value = '';
						showEmptyState('Select a conversation or start a new one.');
					}
				});
			}
			return;
		}

		if (action === 'block') {
			const normalized = normalizeThreadId(threadId);
			const currentlyBlocked = blockedNumbers.has(normalized);
			if (wsClient && wsClient.isConnected()) {
				const request = currentlyBlocked
					? wsClient.unblockNumber(threadId, opensms_user_uuid, opensms_domain_uuid)
					: wsClient.blockNumber(threadId, opensms_user_uuid, opensms_domain_uuid);
				request.then(function () {
					if (currentlyBlocked) {
						blockedNumbers.delete(normalized);
					} else {
						blockedNumbers.add(normalized);
					}
					updateThreadBlockState(threadId, !currentlyBlocked);
				});
			}
		}
	}

	/**
	 * Load messages for a thread
	 */
	function loadThreadMessages(threadNumber) {
		showLoadingState();

		if (!wsClient || !wsClient.isConnected()) {
			showEmptyState('Not connected. Please wait...');
			return;
		}

		wsClient.getThreadHistory(threadNumber, 50)
			.then(function (response) {
				clearMessages();
				const messages = response.payload && response.payload.messages ? response.payload.messages : [];
				if (messages.length === 0) {
					showEmptyState('No messages yet. Start a conversation!');
				} else {
					messages.forEach(function (msg) {
						appendMessageBubble(msg);
					});
				}
			})
			.catch(function (err) {
				console.error('OpenSMS: Failed to load thread history:', err);
				showEmptyState('Failed to load messages.');
			});
	}

	/**
	 * Initialize send form
	 */
	function initSendForm() {
		const form = $("opensms_send_form");
		const textarea = $("opensms_message_body");
		const fromSelect = $("opensms_from_destination");

		if (!form || !textarea) return;

		// Update header when "from" destination changes
		if (fromSelect && fromSelect.tagName === 'SELECT') {
			fromSelect.addEventListener("change", function () {
				if (activeThread) {
					updateChatHeader(activeThread);
				}
			});
		}

		// Handle Enter key to send
		textarea.addEventListener("keydown", function (ev) {
			if (ev.key === "Enter" && !ev.shiftKey) {
				ev.preventDefault();
				form.dispatchEvent(new Event('submit', { cancelable: true }));
			}
		});

		// Handle form submit
		form.addEventListener("submit", function (ev) {
			ev.preventDefault();

			const body = (textarea.value || "").trim();
			if (!body) return;

			const threadId = ($("opensms_active_thread_id").value || "").trim();
			if (!threadId) {
				alert('Please select a conversation or start a new one.');
				return;
			}

			// Get selected destination
			let fromDestinationUuid = '';
			let fromNumber = '';
			if (fromSelect) {
				// Check if it's a select element with options
				if (fromSelect.options && fromSelect.options.length > 0) {
					fromDestinationUuid = fromSelect.value;
					fromNumber = fromSelect.options[fromSelect.selectedIndex].getAttribute('data-number') || fromSelect.options[fromSelect.selectedIndex].text;
				}
				// Otherwise it's a hidden input field (single destination)
				else if (fromSelect.value) {
					fromDestinationUuid = fromSelect.value;
					fromNumber = fromSelect.getAttribute('data-number') || '';
				}
			}
			
			// Fallback to first available destination
			if (!fromNumber && typeof opensms_destinations !== 'undefined' && opensms_destinations.length > 0) {
				fromDestinationUuid = opensms_destinations[0].destination_uuid;
				fromNumber = opensms_destinations[0].destination_number;
			}

			if (!fromNumber) {
				alert('No sending destination available.');
				return;
			}

			// Create temporary UUID for tracking
			const tempUuid = 'temp-' + Date.now();

			// Optimistic UI - show message immediately
			appendMessageBubble({
				message_uuid: tempUuid,
				direction: "outbound",
				message_text: body,
				message_time: new Date().toISOString(),
				status: "sending"
			});

			// Clear textarea
			textarea.value = "";

			// Send via WebSocket
			if (wsClient && wsClient.isConnected()) {
				wsClient.sendMessage(
					fromDestinationUuid,
					fromNumber,
					threadId,  // to_number is the thread ID
					body,
					typeof opensms_domain_uuid !== 'undefined' ? opensms_domain_uuid : '',
					typeof opensms_user_uuid !== 'undefined' ? opensms_user_uuid : ''
				).then(function (response) {
					// Update temp message with real UUID so delivery receipts can find it
					const tempEl = document.querySelector(`[data-message-uuid="${tempUuid}"]`);
					if (tempEl && response.payload && response.payload.message_uuid) {
						tempEl.setAttribute('data-message-uuid', response.payload.message_uuid);
						updateBubbleStatus(tempEl, 'sent');
					}
				}).catch(function (err) {
					console.error('OpenSMS: Send failed:', err);
					const tempEl = document.querySelector(`[data-message-uuid="${tempUuid}"]`);
					if (tempEl) {
						updateBubbleStatus(tempEl, 'failed');
					}
				});
			} else {
				alert('Not connected to server. Please wait and try again.');
			}
		});
	}

	/**
	 * Initialize contacts modal
	 */
	function initContactsModal() {
		const btnOpen = $("opensms_btn_contacts");
		const btnClose = $("opensms_btn_contacts_close");
		const backdrop = $("opensms_contacts_backdrop");
		const btnShowHidden = $("opensms_btn_show_hidden");
		const hiddenList = $("opensms_hidden_list");

		if (btnOpen) {
			btnOpen.addEventListener("click", function () {
				if (backdrop) backdrop.hidden = false;
			});
		}

		if (btnClose) {
			btnClose.addEventListener("click", function () {
				if (backdrop) backdrop.hidden = true;
			});
		}

		if (backdrop) {
			backdrop.addEventListener("click", function (ev) {
				if (ev.target === backdrop) {
					backdrop.hidden = true;
				}
			});
		}

		if (btnShowHidden) {
			btnShowHidden.addEventListener("click", function () {
				if (!hiddenList) return;
				const isVisible = !hiddenList.hidden;
				hiddenList.hidden = isVisible;
				if (!isVisible) {
					refreshHiddenList();
				}
			});
		}
	}

	/**
	 * Refresh the hidden conversations list in the contacts modal
	 */
	function refreshHiddenList() {
		const hiddenList = $("opensms_hidden_list");
		const emptyNote = $("opensms_hidden_empty");
		if (!hiddenList) return;

		// Clear existing items (keep the empty note)
		hiddenList.querySelectorAll('.opensms_hidden_item').forEach(function (el) {
			el.remove();
		});

		if (hiddenThreads.size === 0) {
			if (emptyNote) emptyNote.hidden = false;
			return;
		}

		if (emptyNote) emptyNote.hidden = true;

		hiddenThreads.forEach(function (threadId) {
			const item = document.createElement('div');
			item.className = 'opensms_hidden_item';
			item.setAttribute('data-hidden-thread', threadId);

			item.innerHTML =
				'<span class="opensms_hidden_item_number">' + escapeHtml(threadId) + '</span>' +
				'<div class="opensms_hidden_item_actions">' +
					'<button type="button" class="opensms_btn opensms_btn_secondary opensms_btn_small" data-action="unhide" data-thread-id="' + escapeHtml(threadId) + '" title="Restore conversation">' +
						'<span class="fas fa-eye"></span> Restore' +
					'</button>' +
				'</div>';

			item.querySelector('[data-action="unhide"]').addEventListener('click', function () {
				if (!wsClient || !wsClient.isConnected()) return;
				const tid = this.getAttribute('data-thread-id');
				wsClient.unhideThread(tid, opensms_user_uuid, opensms_domain_uuid).then(function () {
					hiddenThreads.delete(tid);
					item.remove();
					// Re-add the thread to the sidebar
					addNewThread(tid, false);
					// Update empty state
					if (hiddenThreads.size === 0) {
						var emptyEl = $("opensms_hidden_empty");
						if (emptyEl) emptyEl.hidden = false;
					}
				});
			});

			hiddenList.appendChild(item);
		});
	}

	/**
	 * Initialize new thread button
	 */
	function initNewThreadButton() {
		const btn = $("opensms_btn_new_thread");
		if (!btn) return;

		btn.addEventListener("click", function () {
			const newNumber = prompt('Enter phone number to message:');
			if (!newNumber || !newNumber.trim()) return;

			const cleaned = newNumber.replace(/[^\d+]/g, '');
			if (!cleaned) return;

			// Add and select the new thread
			addNewThread(cleaned, true);
			activeThread = cleaned;
			$("opensms_active_thread_id").value = cleaned;

			// Update header to show fromNumber <--> threadNumber
			updateChatHeader(cleaned);

			// Clear messages
			showEmptyState('Start the conversation by sending a message.');

			// Deselect other threads
			const list = $("opensms_thread_list");
			if (list) {
				list.querySelectorAll(".opensms_thread_item").forEach(function (b) {
					if (b.getAttribute('data-thread-id') !== cleaned) {
						b.classList.remove("is_active");
						b.setAttribute("aria-selected", "false");
					}
				});
			}
		});
	}

	/**
	 * Initialize chat header actions
	 */
	function initHeaderActions() {
		const btnBlock = $("opensms_btn_block");
		const btnDelete = $("opensms_btn_delete");
		if (!btnBlock && !btnDelete) return;

		if (btnBlock) {
			btnBlock.addEventListener("click", function () {
				if (!activeThread) return;
				const normalized = normalizeThreadId(activeThread);
				const currentlyBlocked = blockedNumbers.has(normalized);
				const request = currentlyBlocked
					? wsClient.unblockNumber(activeThread, opensms_user_uuid, opensms_domain_uuid)
					: wsClient.blockNumber(activeThread, opensms_user_uuid, opensms_domain_uuid);
				request.then(function () {
					if (currentlyBlocked) {
						blockedNumbers.delete(normalized);
					} else {
						blockedNumbers.add(normalized);
					}
					updateThreadBlockState(activeThread, !currentlyBlocked);
				});
			});
		}

		if (btnDelete) {
			btnDelete.addEventListener("click", function () {
				if (!activeThread) return;
				if (!confirm('Hide this conversation for your user?')) return;
				wsClient.hideThread(activeThread, opensms_user_uuid, opensms_domain_uuid).then(function () {
					hiddenThreads.add(activeThread);
					applyHiddenThreads();
					activeThread = null;
					$("opensms_active_thread_id").value = '';
					showEmptyState('Select a conversation or start a new one.');
				});
			});
		}
	}

	/**
	 * Initialize refresh button
	 */
	function initRefreshButton() {
		const btn = $("opensms_btn_refresh");
		if (!btn) return;

		btn.addEventListener("click", function () {
			if (activeThread) {
				loadThreadMessages(activeThread);
			}
		});
	}

	/**
	 * Initialize WebSocket connection
	 */
	function initWebSocket() {
		// Get configuration from global variables set by PHP
		const wsUrl = typeof opensms_ws_url !== 'undefined' ? opensms_ws_url : '';
		const token = typeof opensms_token !== 'undefined' ? opensms_token : {};
		const config = typeof opensms_ws_config !== 'undefined' ? opensms_ws_config : {};

		if (!wsUrl) {
			console.error('OpenSMS: WebSocket URL not configured');
			updateConnectionStatus('error', 'Configuration error');
			return;
		}

		// Create WebSocket client
		wsClient = new opensms_ws_client(wsUrl, token, config);

		// Handle connection status changes
		wsClient.on('status', function (data) {
			updateConnectionStatus(data.status, data.message);
		});

		// Handle authentication
		wsClient.on('authenticated', function (data) {
			console.log('OpenSMS: Authenticated with server');
			// Update chat status to Connected
			const chatStatus = $("opensms_ws_status");
			if (chatStatus) {
				chatStatus.textContent = opensms_text['label-connected'] || 'Connected';
			}
			// Load blocked numbers and hidden threads
			const userUuid = typeof opensms_user_uuid !== 'undefined' ? opensms_user_uuid : '';
			const domainUuid = typeof opensms_domain_uuid !== 'undefined' ? opensms_domain_uuid : '';
			if (userUuid && domainUuid) {
				wsClient.listBlocked(userUuid, domainUuid).then(function (response) {
					const numbers = response.payload && response.payload.numbers ? response.payload.numbers : [];
					numbers.forEach(function (num) {
						blockedNumbers.add(normalizeThreadId(num));
					});
					// Apply blocked state to existing threads
					const list = $("opensms_thread_list");
					if (list) {
						list.querySelectorAll('.opensms_thread_item').forEach(function (item) {
							const itemThreadId = item.getAttribute('data-thread-id') || '';
							if (blockedNumbers.has(normalizeThreadId(itemThreadId))) {
								item.classList.add('is_blocked');
							}
						});
					}
				});

				wsClient.listHidden(userUuid, domainUuid).then(function (response) {
					const threads = response.payload && response.payload.threads ? response.payload.threads : [];
					threads.forEach(function (thread) {
						hiddenThreads.add(thread);
					});
					applyHiddenThreads();
				});
			}
			// If we have an active thread, reload its messages
			if (activeThread) {
				loadThreadMessages(activeThread);
			}
		});

		// Handle incoming messages
		wsClient.on('MESSAGE', handleIncomingMessage);

		// Handle delivery receipts from carrier
		wsClient.on('DELIVERY_RECEIPT', handleDeliveryReceipt);

		// Handle send responses
		wsClient.on('send_response', handleSendResponse);

		// Handle all events for debugging
		wsClient.on('*', function (data) {
			console.log('OpenSMS: Event received:', data);
		});

		// Connect
		wsClient.connect();
	}

	/**
	 * Main initialization function
	 */
	function main() {
		const root = $("opensms_app");
		if (!root) return;

		// Initialize UI components
		initThreadClicks();
		initSendForm();
		initContactsModal();
		initNewThreadButton();
		initRefreshButton();
		initHeaderActions();

		// Initialize WebSocket connection
		initWebSocket();

		// Select first thread if available
		const firstThread = document.querySelector('.opensms_thread_item');
		if (firstThread) {
			firstThread.click();
		} else {
			showEmptyState('Select a conversation or start a new one.');
		}
	}

	// Initialize when DOM is ready
	if (document.readyState === 'loading') {
		document.addEventListener("DOMContentLoaded", main);
	} else {
		main();
	}
})();

