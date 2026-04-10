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
	let messageContextMenu = null;
	let contextMenuTarget = null;
	let contextMenuTargetClass = '';

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
	 * Extract MMS parts from a message object.
	 * Handles both real-time websocket messages (msg.mms) and
	 * history messages (msg.message_json containing the full object).
	 */
	function getMmsParts(msg) {
		// Direct MMS array from real-time websocket messages
		if (msg.mms && Array.isArray(msg.mms) && msg.mms.length > 0) {
			return msg.mms;
		}
		// MMS embedded in message_json from database history
		if (msg.message_json) {
			try {
				const parsed = typeof msg.message_json === 'string' ? JSON.parse(msg.message_json) : msg.message_json;
				if (parsed && parsed.mms && Array.isArray(parsed.mms) && parsed.mms.length > 0) {
					return parsed.mms;
				}
			} catch (e) { /* ignore parse errors */ }
		}
		return null;
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

	function findThreadButtonByNormalized(threadNumber) {
		const list = $("opensms_thread_list");
		if (!list) return null;

		const target = normalizeThreadId(threadNumber);
		if (!target) return null;

		const items = list.querySelectorAll('.opensms_thread_item');
		for (let i = 0; i < items.length; i++) {
			const itemThreadId = items[i].getAttribute('data-thread-id') || '';
			if (normalizeThreadId(itemThreadId) === target) {
				return items[i];
			}
		}

		return null;
	}

	function findHiddenThreadByNormalized(threadNumber) {
		const target = normalizeThreadId(threadNumber);
		if (!target) return '';

		for (const threadId of hiddenThreads) {
			if (normalizeThreadId(threadId) === target) {
				return threadId;
			}
		}

		return '';
	}

	function parseMessageJson(msg) {
		if (!msg || !msg.message_json) {
			return null;
		}
		try {
			return typeof msg.message_json === 'string' ? JSON.parse(msg.message_json) : msg.message_json;
		} catch (e) {
			return null;
		}
	}

	function getDeliveryStatus(msg) {
		if (!msg || msg.direction !== 'outbound') {
			return '';
		}
		if (msg.status) {
			return String(msg.status);
		}
		const parsed = parseMessageJson(msg);
		if (parsed && parsed.delivery_status) {
			const parsedStatus = String(parsed.delivery_status);
			// Local send persistence stores "sent". Provider callbacks also used
			// "sent" historically, but include delivery_time/detail metadata.
			if (parsedStatus === 'sent' && (parsed.delivery_time || parsed.delivery_detail)) {
				return 'provider_success';
			}
			return parsedStatus;
		}
		return '';
	}

	function getSelectedDestination() {
		const fromSelect = $("opensms_from_destination");
		if (!fromSelect) {
			return { uuid: '', number: '' };
		}

		if (fromSelect.options && fromSelect.options.length > 0) {
			const selectedOption = fromSelect.options[fromSelect.selectedIndex];
			return {
				uuid: fromSelect.value || '',
				number: selectedOption.getAttribute('data-number') || selectedOption.text || ''
			};
		}

		return {
			uuid: fromSelect.value || '',
			number: fromSelect.getAttribute('data-number') || ''
		};
	}

	function setSelectedDestinationByNumber(number) {
		const fromSelect = $("opensms_from_destination");
		if (!fromSelect || !number) {
			return;
		}

		const target = normalizeThreadId(number);
		if (!target || !fromSelect.options || fromSelect.options.length === 0) {
			return;
		}

		for (let i = 0; i < fromSelect.options.length; i++) {
			const opt = fromSelect.options[i];
			const optNumber = opt.getAttribute('data-number') || opt.text || '';
			if (normalizeThreadId(optNumber) === target) {
				fromSelect.selectedIndex = i;
				break;
			}
		}
	}

	function getBubblePayload(bubbleRow) {
		if (!bubbleRow) {
			return null;
		}
		const mmsRaw = bubbleRow.getAttribute('data-message-mms') || '[]';
		let mms = [];
		try {
			mms = JSON.parse(mmsRaw);
		} catch (e) {
			mms = [];
		}
		return {
			messageText: bubbleRow.getAttribute('data-message-text') || '',
			toNumber: bubbleRow.getAttribute('data-message-to') || activeThread || '',
			fromNumber: bubbleRow.getAttribute('data-message-from') || '',
			mms: Array.isArray(mms) ? mms : []
		};
	}

	function retryFailedBubble(bubbleRow) {
		if (!wsClient || !wsClient.isConnected()) {
			alert('Not connected to server. Please wait and try again.');
			return;
		}

		const payload = getBubblePayload(bubbleRow);
		if (!payload || !payload.toNumber || (!payload.messageText && payload.mms.length === 0)) {
			alert('Unable to retry this message.');
			return;
		}

		if (payload.fromNumber) {
			setSelectedDestinationByNumber(payload.fromNumber);
		}

		const selected = getSelectedDestination();
		if (!selected.number) {
			alert('No sending destination available.');
			return;
		}

		updateBubbleStatus(bubbleRow, 'sending');
		bubbleRow.classList.add('is_retrying');

		wsClient.sendMessage(
			selected.uuid,
			selected.number,
			payload.toNumber,
			payload.messageText,
			typeof opensms_domain_uuid !== 'undefined' ? opensms_domain_uuid : '',
			typeof opensms_user_uuid !== 'undefined' ? opensms_user_uuid : '',
			payload.mms.length > 0 ? payload.mms : null
		).then(function (response) {
			bubbleRow.classList.remove('is_retrying');
			if (response.payload && response.payload.message_uuid) {
				bubbleRow.setAttribute('data-message-uuid', response.payload.message_uuid);
			}
			updateBubbleStatus(bubbleRow, 'provider_success');
		}).catch(function (err) {
			bubbleRow.classList.remove('is_retrying');
			console.error('OpenSMS: Retry failed:', err);
			updateBubbleStatus(bubbleRow, 'failed');
		});
	}

	function getImageExtensionFromSrc(src) {
		if (!src) {
			return 'jpg';
		}
		if (src.startsWith('data:image/')) {
			const match = src.match(/^data:image\/([a-zA-Z0-9+.-]+);/);
			if (match && match[1]) {
				return match[1].toLowerCase() === 'jpeg' ? 'jpg' : match[1].toLowerCase();
			}
		}
		const cleaned = src.split('?')[0].split('#')[0];
		const parts = cleaned.split('.');
		const ext = (parts.pop() || '').toLowerCase();
		if (ext && ext.length <= 5) {
			return ext;
		}
		return 'jpg';
	}

	function downloadPictureFromBubble(bubbleRow) {
		if (!bubbleRow) {
			return;
		}
		const image = bubbleRow.querySelector('img.opensms_bubble_media');
		if (!image || !image.src) {
			return;
		}

		const src = image.currentSrc || image.src;
		const msgUuid = bubbleRow.getAttribute('data-message-uuid') || String(Date.now());
		const ext = getImageExtensionFromSrc(src);
		const link = document.createElement('a');
		link.href = src;
		link.download = 'opensms-image-' + msgUuid + '.' + ext;
		link.target = '_blank';
		link.rel = 'noopener';
		document.body.appendChild(link);
		link.click();
		link.remove();
	}

	function fileSafePart(value) {
		return String(value || '')
			.trim()
			.replace(/[^a-zA-Z0-9+_-]+/g, '_')
			.replace(/^_+|_+$/g, '') || 'thread';
	}

	function exportTimestamp() {
		return new Date().toISOString().replace(/[.:]/g, '-');
	}

	function downloadTextFile(filename, content) {
		const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
		const url = URL.createObjectURL(blob);
		const link = document.createElement('a');
		link.href = url;
		link.download = filename;
		document.body.appendChild(link);
		link.click();
		link.remove();
		setTimeout(function () {
			URL.revokeObjectURL(url);
		}, 1000);
	}

	function exportSingleBubbleMessage(bubbleRow) {
		if (!bubbleRow) {
			alert('No message selected to export.');
			return;
		}

		const isOutbound = bubbleRow.classList.contains('is_outbound');
		const when = (bubbleRow.querySelector('.opensms_bubble_time') || {}).textContent || '';
		const from = bubbleRow.getAttribute('data-message-from') || '';
		const to = bubbleRow.getAttribute('data-message-to') || '';
		const body = bubbleRow.getAttribute('data-message-text') || '';
		const uuidValue = bubbleRow.getAttribute('data-message-uuid') || String(Date.now());
		const statusEl = bubbleRow.querySelector('.opensms_bubble_status');
		const status = statusEl ? (statusEl.getAttribute('data-status') || '') : '';

		let mms = [];
		try {
			mms = JSON.parse(bubbleRow.getAttribute('data-message-mms') || '[]');
		} catch (e) {
			mms = [];
		}

		const lines = [];
		lines.push('OpenSMS Message Export');
		lines.push('Message UUID: ' + uuidValue);
		lines.push('Exported: ' + new Date().toISOString());
		lines.push('');
		lines.push('Time: ' + when);
		lines.push('Direction: ' + (isOutbound ? 'OUTBOUND' : 'INBOUND'));
		lines.push('From: ' + from);
		lines.push('To: ' + to);
		if (status) {
			lines.push('Status: ' + status);
		}
		if (mms && mms.length > 0) {
			lines.push('Media attachments: ' + mms.length);
		}
		lines.push('');
		lines.push('Message:');
		lines.push(body || '(no text body)');

		downloadTextFile('opensms-message-' + fileSafePart(uuidValue) + '-' + exportTimestamp() + '.txt', lines.join('\n'));
	}

	function resendLastOutboundMessage() {
		const container = $('opensms_messages');
		if (!container) {
			alert('Messages panel is not available.');
			return;
		}

		const outboundRows = container.querySelectorAll('.opensms_bubble_row.is_outbound');
		if (!outboundRows || outboundRows.length === 0) {
			alert('No outbound message found to resend.');
			return;
		}

		const lastOutbound = outboundRows[outboundRows.length - 1];
		retryFailedBubble(lastOutbound);
	}

	function clearContextMenuTarget() {
		if (contextMenuTarget && contextMenuTargetClass) {
			contextMenuTarget.classList.remove(contextMenuTargetClass);
		}
		contextMenuTarget = null;
		contextMenuTargetClass = '';
	}

	function setContextMenuTarget(target, className) {
		clearContextMenuTarget();
		if (!target || !className) {
			return;
		}
		target.classList.add(className);
		contextMenuTarget = target;
		contextMenuTargetClass = className;
	}

	function collectVisibleMessagesForExport() {
		const container = $('opensms_messages');
		if (!container) {
			return [];
		}

		const rows = container.querySelectorAll('.opensms_bubble_row');
		const messages = [];
		rows.forEach(function (row) {
			const isOutbound = row.classList.contains('is_outbound');
			const statusEl = row.querySelector('.opensms_bubble_status');
			messages.push({
				direction: isOutbound ? 'outbound' : 'inbound',
				message_time: (row.querySelector('.opensms_bubble_time') || {}).textContent || '',
				message_from: row.getAttribute('data-message-from') || '',
				message_to: row.getAttribute('data-message-to') || '',
				message_text: row.getAttribute('data-message-text') || '',
				status: statusEl ? (statusEl.getAttribute('data-status') || '') : '',
				mms: (function () {
					try {
						return JSON.parse(row.getAttribute('data-message-mms') || '[]');
					} catch (e) {
						return [];
					}
				})()
			});
		});

		return messages;
	}

	async function fetchThreadMessagesForExport(threadId) {
		if (wsClient && wsClient.isConnected()) {
			const response = await wsClient.getThreadHistory(threadId, 500);
			return response && response.payload && Array.isArray(response.payload.messages)
				? response.payload.messages
				: [];
		}

		if (normalizeThreadId(activeThread) === normalizeThreadId(threadId)) {
			return collectVisibleMessagesForExport();
		}

		throw new Error('Not connected to server; only the active conversation can be exported offline.');
	}

	function buildThreadExportText(threadId, messages) {
		const lines = [];
		lines.push('OpenSMS Conversation Export');
		lines.push('Thread: ' + threadId);
		lines.push('Exported: ' + new Date().toISOString());
		lines.push('');

		if (!messages || messages.length === 0) {
			lines.push('(No messages found)');
			return lines.join('\n');
		}

		messages.forEach(function (msg) {
			const when = msg.message_time || msg.time || msg.message_date || '';
			const direction = String(msg.direction || msg.message_direction || '').toUpperCase() || 'UNKNOWN';
			const from = msg.message_from || msg.from_number || '';
			const to = msg.message_to || msg.to_number || '';
			const body = msg.message_text || msg.body || '';
			const status = getDeliveryStatus(msg) || (msg.status ? String(msg.status) : '');
			const mms = getMmsParts(msg);
			const statusPart = status ? ' [' + status + ']' : '';
			const mediaPart = (mms && mms.length > 0) ? ' [media:' + mms.length + ']' : '';

			lines.push('[' + when + '] ' + direction + ' ' + from + ' -> ' + to + statusPart + mediaPart);
			if (body) {
				lines.push(body);
			}
			lines.push('');
		});

		return lines.join('\n');
	}

	function getExportThreadIds() {
		const uniqueByNormalized = new Map();
		const list = $('opensms_thread_list');

		if (list) {
			list.querySelectorAll('.opensms_thread_item').forEach(function (item) {
				const threadId = item.getAttribute('data-thread-id') || '';
				const key = normalizeThreadId(threadId) || threadId;
				if (threadId && !uniqueByNormalized.has(key)) {
					uniqueByNormalized.set(key, threadId);
				}
			});
		}

		hiddenThreads.forEach(function (threadId) {
			const key = normalizeThreadId(threadId) || threadId;
			if (threadId && !uniqueByNormalized.has(key)) {
				uniqueByNormalized.set(key, threadId);
			}
		});

		if (activeThread) {
			const key = normalizeThreadId(activeThread) || activeThread;
			if (!uniqueByNormalized.has(key)) {
				uniqueByNormalized.set(key, activeThread);
			}
		}

		return Array.from(uniqueByNormalized.values());
	}

	async function exportSingleThread(threadId) {
		if (!threadId) {
			alert('No conversation selected to export.');
			return;
		}

		try {
			const messages = await fetchThreadMessagesForExport(threadId);
			const text = buildThreadExportText(threadId, messages);
			const filename = 'opensms-thread-' + fileSafePart(threadId) + '-' + exportTimestamp() + '.txt';
			downloadTextFile(filename, text);
		} catch (err) {
			console.error('OpenSMS: Failed to export thread:', err);
			alert('Unable to export conversation: ' + (err && err.message ? err.message : 'Unknown error'));
		}
	}

	async function exportAllThreads() {
		const threadIds = getExportThreadIds();
		if (threadIds.length === 0) {
			alert('No conversations available to export.');
			return;
		}

		const sections = [];
		const failed = [];
		for (let i = 0; i < threadIds.length; i++) {
			const threadId = threadIds[i];
			try {
				const messages = await fetchThreadMessagesForExport(threadId);
				sections.push(buildThreadExportText(threadId, messages));
			} catch (err) {
				failed.push(threadId);
			}
		}

		if (sections.length === 0) {
			alert('Unable to export messages. Ensure websocket is connected.');
			return;
		}

		const summary = [
			'OpenSMS Full Export',
			'Exported: ' + new Date().toISOString(),
			'Conversations exported: ' + sections.length,
			failed.length > 0 ? ('Conversations skipped: ' + failed.length + ' (' + failed.join(', ') + ')') : 'Conversations skipped: 0',
			''
		].join('\n');

		downloadTextFile('opensms-all-conversations-' + exportTimestamp() + '.txt', summary + sections.join('\n\n====================\n\n'));

		if (failed.length > 0) {
			alert('Export completed with partial results. Skipped ' + failed.length + ' conversation(s).');
		}
	}

	function hideMessageContextMenu() {
		if (messageContextMenu) {
			messageContextMenu.remove();
			messageContextMenu = null;
		}
		clearContextMenuTarget();
	}

	function showMessageContextMenu(x, y, items) {
		hideMessageContextMenu();
		if (!items || items.length === 0) {
			return;
		}

		const menu = document.createElement('div');
		menu.className = 'opensms_context_menu';
		menu.setAttribute('role', 'menu');

		items.forEach(function (item) {
			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'opensms_context_menu_item';
			button.textContent = item.label;
			button.addEventListener('click', function () {
				hideMessageContextMenu();
				try {
					const result = item.action();
					if (result && typeof result.catch === 'function') {
						result.catch(function (err) {
							console.error('OpenSMS: Context menu action failed:', err);
						});
					}
				} catch (err) {
					console.error('OpenSMS: Context menu action failed:', err);
				}
			});
			menu.appendChild(button);
		});

		document.body.appendChild(menu);
		messageContextMenu = menu;

		const menuRect = menu.getBoundingClientRect();
		const maxLeft = Math.max(8, window.innerWidth - menuRect.width - 8);
		const maxTop = Math.max(8, window.innerHeight - menuRect.height - 8);
		menu.style.left = Math.min(Math.max(8, x), maxLeft) + 'px';
		menu.style.top = Math.min(Math.max(8, y), maxTop) + 'px';
	}

	function resolveBubbleRowFromContextEvent(ev, messagesContainer) {
		if (!ev || !messagesContainer) {
			return null;
		}

		if (typeof ev.composedPath === 'function') {
			const path = ev.composedPath();
			for (let i = 0; i < path.length; i++) {
				const node = path[i];
				if (node && node.classList && node.classList.contains('opensms_bubble_row')) {
					return node;
				}
				if (node === messagesContainer) {
					break;
				}
			}
		}

		const pointTarget = document.elementFromPoint(ev.clientX, ev.clientY);
		if (pointTarget && messagesContainer.contains(pointTarget)) {
			const pointRow = pointTarget.closest('.opensms_bubble_row');
			if (pointRow) {
				return pointRow;
			}
		}

		if (ev.target && typeof ev.target.closest === 'function') {
			return ev.target.closest('.opensms_bubble_row');
		}

		return null;
	}

	function initMessageContextMenu() {
		const messagesContainer = $('opensms_messages');
		if (!messagesContainer) {
			return;
		}

		messagesContainer.addEventListener('contextmenu', function (ev) {
			const bubbleRow = resolveBubbleRowFromContextEvent(ev, messagesContainer);
			let items;

			if (bubbleRow) {
				setContextMenuTarget(bubbleRow, 'opensms_context_target_bubble');
				items = [
					{
						label: 'Send Again',
						action: function () { return retryFailedBubble(bubbleRow); }
					},
					{
						label: 'Save Message',
						action: function () { return exportSingleBubbleMessage(bubbleRow); }
					}
				];
			} else {
				setContextMenuTarget(messagesContainer, 'opensms_context_target_main');
				items = [
					{
						label: 'Send Last Message Again',
						action: function () { return resendLastOutboundMessage(); }
					},
					{
						label: 'Save All Messages',
						action: function () { return exportAllThreads(); }
					}
				];
			}

			ev.preventDefault();
			showMessageContextMenu(ev.clientX, ev.clientY, items);
		});

		const threadList = $('opensms_thread_list');
		if (threadList) {
			threadList.addEventListener('contextmenu', function (ev) {
				const threadItem = ev.target.closest('.opensms_thread_item');
				const threadId = threadItem ? (threadItem.getAttribute('data-thread-id') || '') : '';
				setContextMenuTarget(threadItem || threadList, 'opensms_context_target_list');
				const items = [];
				if (threadId) {
					items.push({
						label: 'Save Messages',
						action: function () { return exportSingleThread(threadId); }
					});
				}
				items.push({
					label: 'Save All Messages',
					action: function () { return exportAllThreads(); }
				});

				ev.preventDefault();
				showMessageContextMenu(ev.clientX, ev.clientY, items);
			});
		}

		const contactsBackdrop = $('opensms_contacts_backdrop');
		if (contactsBackdrop) {
			contactsBackdrop.addEventListener('contextmenu', function (ev) {
				if (!ev.target.closest('.opensms_modal_body')) {
					return;
				}

				const hiddenItem = ev.target.closest('.opensms_hidden_item');
				setContextMenuTarget(hiddenItem || ev.target.closest('.opensms_modal_body'), 'opensms_context_target_list');
				const hiddenThreadId = hiddenItem ? (hiddenItem.getAttribute('data-hidden-thread') || '') : '';
				const items = [];

				if (hiddenThreadId) {
					items.push({
						label: 'Save Messages',
						action: function () { return exportSingleThread(hiddenThreadId); }
					});
				}

				items.push({
					label: 'Save All Messages',
					action: function () { return exportAllThreads(); }
				});

				ev.preventDefault();
				showMessageContextMenu(ev.clientX, ev.clientY, items);
			});
		}

		document.addEventListener('click', function (ev) {
			if (!messageContextMenu) {
				return;
			}
			if (!messageContextMenu.contains(ev.target)) {
				hideMessageContextMenu();
			}
		});

		document.addEventListener('keydown', function (ev) {
			if (ev.key === 'Escape') {
				hideMessageContextMenu();
			}
		});

		window.addEventListener('resize', hideMessageContextMenu);
		window.addEventListener('scroll', hideMessageContextMenu, true);
	}

	function applyHiddenThreads() {
		const list = $("opensms_thread_list");
		if (!list) return;
		list.querySelectorAll('.opensms_thread_item').forEach(function (item) {
			const itemThreadId = item.getAttribute('data-thread-id') || '';
			if (findHiddenThreadByNormalized(itemThreadId)) {
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

		// Render MMS media if present
		const mmsParts = getMmsParts(msg);
		if (mmsParts && mmsParts.length > 0) {
			mmsParts.forEach(function (part) {
				const ct = (part.content_type || '').toLowerCase();
				if (ct.startsWith('image/')) {
					const img = document.createElement('img');
					img.className = 'opensms_bubble_media';
					img.alt = part.filename || 'Image';
					if (part.data) {
						img.src = 'data:' + ct + ';base64,' + part.data;
					} else if (part.url) {
						img.src = part.url;
					}
					body.appendChild(img);
				} else if (ct.startsWith('video/')) {
					const video = document.createElement('video');
					video.className = 'opensms_bubble_media';
					video.controls = true;
					if (part.data) {
						video.src = 'data:' + ct + ';base64,' + part.data;
					} else if (part.url) {
						video.src = part.url;
					}
					body.appendChild(video);
				} else if (ct.startsWith('audio/')) {
					const audio = document.createElement('audio');
					audio.className = 'opensms_bubble_media_audio';
					audio.controls = true;
					if (part.data) {
						audio.src = 'data:' + ct + ';base64,' + part.data;
					} else if (part.url) {
						audio.src = part.url;
					}
					body.appendChild(audio);
				}
			});
		}

		// Render text content
		const textContent = msg.message_text || msg.body || '';
		row.setAttribute('data-message-text', textContent || '');
		row.setAttribute('data-message-from', msg.message_from || msg.from_number || '');
		row.setAttribute('data-message-to', msg.message_to || msg.to_number || activeThread || '');
		row.setAttribute('data-message-mms', JSON.stringify(mmsParts || []));
		if (textContent) {
			const textDiv = document.createElement('div');
			textDiv.innerHTML = escapeHtml(textContent).replace(/\n/g, "<br>");
			body.appendChild(textDiv);
		}

		const meta = document.createElement("div");
		meta.className = "opensms_bubble_meta";
		meta.innerHTML = "<span class='opensms_bubble_time'>" + escapeHtml(formatTime(msg.message_time || msg.time)) + "</span>";

		const deliveryStatus = getDeliveryStatus(msg);
		if (isOutbound && deliveryStatus) {
			meta.innerHTML += "<span class='opensms_bubble_status' data-status='" + escapeHtml(deliveryStatus) + "'>" + formatDeliveryStatus(deliveryStatus) + "</span>";
		}

		if (isOutbound && deliveryStatus === 'failed') {
			const retryButton = document.createElement('button');
			retryButton.type = 'button';
			retryButton.className = 'opensms_retry_btn';
			retryButton.textContent = 'Retry';
			retryButton.addEventListener('click', function () {
				retryFailedBubble(row);
			});
			meta.appendChild(retryButton);
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

		if (findHiddenThreadByNormalized(threadNumber)) {
			return;
		}

		// Check if thread already exists
		if (findThreadButtonByNormalized(threadNumber)) {
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
		if (blockedNumbers.has(normalizedThread) || findHiddenThreadByNormalized(threadNumber)) {
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
				status: data.status,
				mms: data.mms || null
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
				updateBubbleStatus(msgEl, 'provider_success');
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
		let status = data.delivery_status || '';
		if (status === 'sent') {
			status = 'provider_success';
		}

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
			case 'queued':
				return '<i class="fas fa-clock"></i> Queued';
			case 'sent':
				return '<i class="fas fa-check"></i> Sent';
			case 'provider_success':
				return '<i class="fas fa-check-double"></i> Sent';
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
				}).catch(function (err) {
					console.error('OpenSMS: Failed to hide thread:', err);
					alert('Unable to hide conversation: ' + (err && err.message ? err.message : 'Unknown error'));
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
				}).catch(function (err) {
					console.error('OpenSMS: Failed to update block state:', err);
					alert('Unable to update block state: ' + (err && err.message ? err.message : 'Unknown error'));
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

	// Pending file attachments for the next send
	let pendingAttachments = [];

	/**
	 * Read a File object as a base64-encoded attachment object.
	 * Returns a Promise that resolves to {content_type, data, filename, size}.
	 */
	function readFileAsAttachment(file) {
		return new Promise(function (resolve, reject) {
			const reader = new FileReader();
			reader.onload = function () {
				// result is "data:<type>;base64,<data>"
				const base64 = reader.result.split(',')[1] || '';
				resolve({
					content_type: file.type || 'application/octet-stream',
					data: base64,
					filename: file.name,
					size: file.size
				});
			};
			reader.onerror = function () { reject(reader.error); };
			reader.readAsDataURL(file);
		});
	}

	/**
	 * Render attachment preview thumbnails below the textarea.
	 */
	function renderAttachmentPreview() {
		let preview = $("opensms_attach_preview");
		if (!preview) {
			// Create preview container after the input wrapper
			const wrapper = document.querySelector('.opensms_composer_input_wrapper');
			if (!wrapper) return;
			preview = document.createElement('div');
			preview.id = 'opensms_attach_preview';
			preview.className = 'opensms_attach_preview';
			wrapper.parentNode.insertBefore(preview, wrapper.nextSibling);
		}

		preview.innerHTML = '';
		if (pendingAttachments.length === 0) {
			preview.style.display = 'none';
			return;
		}
		preview.style.display = 'flex';

		pendingAttachments.forEach(function (att, idx) {
			const thumb = document.createElement('div');
			thumb.className = 'opensms_attach_thumb';

			if (att.content_type.startsWith('image/')) {
				const img = document.createElement('img');
				img.src = 'data:' + att.content_type + ';base64,' + att.data;
				img.alt = att.filename;
				thumb.appendChild(img);
			} else {
				const label = document.createElement('span');
				label.className = 'opensms_attach_filename';
				label.textContent = att.filename;
				thumb.appendChild(label);
			}

			// Remove button
			const removeBtn = document.createElement('button');
			removeBtn.type = 'button';
			removeBtn.className = 'opensms_attach_remove';
			removeBtn.innerHTML = '&times;';
			removeBtn.title = 'Remove';
			removeBtn.addEventListener('click', function () {
				pendingAttachments.splice(idx, 1);
				renderAttachmentPreview();
			});
			thumb.appendChild(removeBtn);

			preview.appendChild(thumb);
		});
	}

	/**
	 * Initialize send form
	 */
	function initSendForm() {
		const form = $("opensms_send_form");
		const textarea = $("opensms_message_body");
		const fromSelect = $("opensms_from_destination");
		const attachBtn = $("opensms_btn_attach");
		const fileInput = $("opensms_file_input");

		if (!form || !textarea) return;

		// Wire up attach button to trigger hidden file input
		if (attachBtn && fileInput) {
			attachBtn.addEventListener('click', function () {
				fileInput.click();
			});

			fileInput.addEventListener('change', function () {
				if (!fileInput.files || fileInput.files.length === 0) return;
				const promises = [];
				for (let i = 0; i < fileInput.files.length; i++) {
					promises.push(readFileAsAttachment(fileInput.files[i]));
				}
				Promise.all(promises).then(function (attachments) {
					pendingAttachments = pendingAttachments.concat(attachments);
					renderAttachmentPreview();
				}).catch(function (err) {
					console.error('OpenSMS: Failed to read attachments:', err);
				});
				// Reset the file input so re-selecting the same file triggers change
				fileInput.value = '';
			});
		}

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
			const hasAttachments = pendingAttachments.length > 0;
			if (!body && !hasAttachments) return;

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

			// Capture and clear attachments before sending
			const mmsData = hasAttachments ? pendingAttachments.slice() : null;

			// Create temporary UUID for tracking
			const tempUuid = 'temp-' + Date.now();

			// Optimistic UI - show message immediately (including media)
			appendMessageBubble({
				message_uuid: tempUuid,
				direction: "outbound",
				message_text: body,
				message_from: fromNumber,
				message_to: threadId,
				mms: mmsData,
				message_time: new Date().toISOString(),
				status: "sending"
			});

			// Clear textarea and attachments
			textarea.value = "";
			pendingAttachments = [];
			renderAttachmentPreview();

			// Send via WebSocket
			if (wsClient && wsClient.isConnected()) {
				wsClient.sendMessage(
					fromDestinationUuid,
					fromNumber,
					threadId,  // to_number is the thread ID
					body,
					typeof opensms_domain_uuid !== 'undefined' ? opensms_domain_uuid : '',
					typeof opensms_user_uuid !== 'undefined' ? opensms_user_uuid : '',
					mmsData
				).then(function (response) {
					// Update temp message with real UUID so delivery receipts can find it
					const tempEl = document.querySelector(`[data-message-uuid="${tempUuid}"]`);
					if (tempEl && response.payload && response.payload.message_uuid) {
						tempEl.setAttribute('data-message-uuid', response.payload.message_uuid);
						updateBubbleStatus(tempEl, 'provider_success');
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
	 * Start/select a thread from a raw dialed value.
	 */
	function startNewThreadFromInput(rawNumber) {
		const normalized = (rawNumber || '').trim();
		if (!normalized) return;

		let cleaned = normalized.replace(/[^\d+]/g, '');

		// Convert international dialing prefix 00... to +...
		if (cleaned.startsWith('00')) {
			cleaned = '+' + cleaned.slice(2);
		}

		if (cleaned.startsWith('+')) {
			// Keep international country code when user provides it.
			cleaned = '+' + cleaned.slice(1).replace(/\D/g, '');
		} else {
			const digits = cleaned.replace(/\D/g, '');
			// Normalize North American numbers to +1...
			if (digits.length === 10) {
				cleaned = '+1' + digits;
			} else if (digits.length === 11 && digits.startsWith('1')) {
				cleaned = '+' + digits;
			} else {
				cleaned = digits;
			}
		}

		if (!cleaned) return;

		const hiddenMatch = findHiddenThreadByNormalized(cleaned);
		if (hiddenMatch) {
			hiddenThreads.delete(hiddenMatch);
			if (wsClient && wsClient.isConnected()) {
				wsClient.unhideThread(hiddenMatch, opensms_user_uuid, opensms_domain_uuid).catch(function (err) {
					console.error('OpenSMS: Failed to unhide thread while starting new conversation:', err);
				});
			}
		}

		let selectedButton = findThreadButtonByNormalized(cleaned);
		if (!selectedButton) {
			addNewThread(cleaned, true);
			selectedButton = findThreadButtonByNormalized(cleaned);
		}

		const selectedThreadId = selectedButton
			? (selectedButton.getAttribute('data-thread-id') || cleaned)
			: cleaned;

		activeThread = selectedThreadId;
		$("opensms_active_thread_id").value = selectedThreadId;

		// Update header to show fromNumber <--> threadNumber
		updateChatHeader(selectedThreadId);

		// Clear messages
		showEmptyState('Start the conversation by sending a message.');

		// Deselect other threads
		const list = $("opensms_thread_list");
		if (list) {
			list.querySelectorAll(".opensms_thread_item").forEach(function (b) {
				if (b.getAttribute('data-thread-id') !== selectedThreadId) {
					b.classList.remove("is_active");
					b.setAttribute("aria-selected", "false");
				} else {
					b.classList.add("is_active");
					b.setAttribute("aria-selected", "true");
					if (typeof b.scrollIntoView === 'function') {
						b.scrollIntoView({ block: 'nearest' });
					}
				}
			});
		}
	}

	/**
	 * Open dialpad modal for starting a new thread.
	 */
	function openDialpadModal() {
		const backdrop = document.createElement('div');
		backdrop.className = 'opensms_modal_backdrop opensms_dialpad_backdrop';

		backdrop.innerHTML =
			'<div class="opensms_modal opensms_dialpad_modal" role="dialog" aria-modal="true" aria-label="New message dialpad">' +
				'<div class="opensms_modal_header">' +
					'<div class="opensms_modal_title">New Message</div>' +
					'<button type="button" class="opensms_btn opensms_btn_icon opensms_dialpad_close" aria-label="Close">' +
						'<span class="fas fa-times"></span>' +
					'</button>' +
				'</div>' +
				'<div class="opensms_modal_body opensms_dialpad_body">' +
					'<label class="opensms_composer_label opensms_dialpad_label" for="opensms_dialpad_input">Enter phone number</label>' +
					'<input id="opensms_dialpad_input" class="opensms_input opensms_dialpad_input" type="text" inputmode="tel" autocomplete="off" placeholder="+1..., +44..., etc." />' +
					'<div class="opensms_dialpad_grid" role="group" aria-label="Dialpad"></div>' +
					'<div class="opensms_dialpad_actions">' +
						'<button type="button" class="opensms_btn opensms_btn_secondary opensms_dialpad_cancel">Cancel</button>' +
						'<button type="button" class="opensms_btn opensms_btn_primary opensms_dialpad_start" disabled>Start</button>' +
					'</div>' +
				'</div>' +
			'</div>';

		document.body.appendChild(backdrop);

		const input = backdrop.querySelector('#opensms_dialpad_input');
		const grid = backdrop.querySelector('.opensms_dialpad_grid');
		const btnClose = backdrop.querySelector('.opensms_dialpad_close');
		const btnCancel = backdrop.querySelector('.opensms_dialpad_cancel');
		const btnStart = backdrop.querySelector('.opensms_dialpad_start');

		const keyLayout = ['1','2','3','4','5','6','7','8','9','*','0','#'];
		keyLayout.forEach(function (key) {
			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'opensms_dialpad_key';
			button.setAttribute('data-key', key);
			button.textContent = key;
			button.addEventListener('click', function () {
				input.value += key;
				input.dispatchEvent(new Event('input'));
				pulseDialpadKey(key);
				input.focus();
			});
			grid.appendChild(button);
		});

		const plusButton = document.createElement('button');
		plusButton.type = 'button';
		plusButton.className = 'opensms_dialpad_key opensms_dialpad_key_secondary';
		plusButton.setAttribute('data-key', '+');
		plusButton.textContent = '+';
		plusButton.addEventListener('click', function () {
			if (!input.value.includes('+')) {
				input.value = '+' + input.value;
				input.dispatchEvent(new Event('input'));
			}
			pulseDialpadKey('+');
			input.focus();
		});
		grid.appendChild(plusButton);

		const backspaceButton = document.createElement('button');
		backspaceButton.type = 'button';
		backspaceButton.className = 'opensms_dialpad_key opensms_dialpad_key_secondary';
		backspaceButton.setAttribute('data-action', 'backspace');
		backspaceButton.innerHTML = '<span class="fas fa-delete-left"></span>';
		backspaceButton.addEventListener('click', function () {
			input.value = input.value.slice(0, -1);
			input.dispatchEvent(new Event('input'));
			pulseDialpadAction('backspace');
			input.focus();
		});
		grid.appendChild(backspaceButton);

		function pulseDialpadKey(key) {
			const selector = '.opensms_dialpad_key[data-key="' + CSS.escape(key) + '"]';
			const el = backdrop.querySelector(selector);
			if (!el) return;
			el.classList.add('is_pressed');
			setTimeout(function () {
				el.classList.remove('is_pressed');
			}, 120);
		}

		function pulseDialpadAction(action) {
			const selector = '.opensms_dialpad_key[data-action="' + CSS.escape(action) + '"]';
			const el = backdrop.querySelector(selector);
			if (!el) return;
			el.classList.add('is_pressed');
			setTimeout(function () {
				el.classList.remove('is_pressed');
			}, 120);
		}

		function updateStartState() {
			const current = (input.value || '').replace(/\s+/g, '');
			btnStart.disabled = current.length === 0 || current === '+' || current === '+1';
		}

		function closeModal() {
			document.removeEventListener('keydown', onKeyDown);
			backdrop.remove();
		}

		function submitDial() {
			const value = input.value || '';
			if (!value.trim()) return;
			closeModal();
			startNewThreadFromInput(value);
		}

		function onKeyDown(ev) {
			if (ev.key === 'Escape') {
				ev.preventDefault();
				closeModal();
				return;
			}
			if (ev.key === 'Enter') {
				ev.preventDefault();
				submitDial();
				return;
			}

			if (/^[0-9*#]$/.test(ev.key)) {
				pulseDialpadKey(ev.key);
			}
			if (ev.key === '+') {
				pulseDialpadKey('+');
			}
			if (ev.key === 'Backspace') {
				pulseDialpadAction('backspace');
			}
		}

		input.addEventListener('input', updateStartState);
		btnStart.addEventListener('click', submitDial);
		btnCancel.addEventListener('click', closeModal);
		btnClose.addEventListener('click', closeModal);
		backdrop.addEventListener('click', function (ev) {
			if (ev.target === backdrop) {
				closeModal();
			}
		});

		document.addEventListener('keydown', onKeyDown);
		input.value = '+1';
		input.focus();
		input.setSelectionRange(input.value.length, input.value.length);
		updateStartState();
	}

	/**
	 * Initialize new thread button
	 */
	function initNewThreadButton() {
		const btn = $("opensms_btn_new_thread");
		if (!btn) return;

		btn.addEventListener("click", function () {
			openDialpadModal();
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
				}).catch(function (err) {
					console.error('OpenSMS: Failed to update block state:', err);
					alert('Unable to update block state: ' + (err && err.message ? err.message : 'Unknown error'));
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
				}).catch(function (err) {
					console.error('OpenSMS: Failed to hide thread:', err);
					alert('Unable to hide conversation: ' + (err && err.message ? err.message : 'Unknown error'));
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
			// If we have an active thread, reload its messages only if
			// the chat area is empty (first load or was showing empty state).
			// On reconnect we don't want to clear already visible messages.
			if (activeThread) {
				const mc = $("opensms_messages");
				const hasMessages = mc && mc.querySelector('.opensms_bubble_row');
				if (!hasMessages) {
					loadThreadMessages(activeThread);
				}
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
		initMessageContextMenu();

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
