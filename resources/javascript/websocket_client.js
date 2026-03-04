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

class opensms_ws_client {
	constructor(url, token, config = {}) {
		this.url = url;
		this.token = token;
		this.config = Object.assign({
			reconnect_delay: 2000,
			max_reconnect_delay: 30000,
			ping_interval: 30000,
			auth_timeout: 10000,
			pong_timeout: 10000,
			pong_timeout_max_retries: 3
		}, config);

		this.ws = null;
		this._nextId = 1;
		this._pending = new Map();
		this._eventHandlers = new Map();
		this._reconnectDelay = this.config.reconnect_delay;
		this._reconnectTimer = null;
		this._pingTimer = null;
		this._pongTimer = null;
		this._pongRetries = 0;
		this._authenticated = false;
		this._authTimer = null;
		this._intentionalClose = false;
	}

	/**
	 * Connect to the websocket server
	 */
	connect() {
		if (this.ws && (this.ws.readyState === WebSocket.CONNECTING || this.ws.readyState === WebSocket.OPEN)) {
			console.log('OpenSMS: Already connected or connecting');
			return;
		}

		this._intentionalClose = false;
		this._dispatchStatus('connecting');

		try {
			this.ws = new WebSocket(this.url);

			this.ws.addEventListener('open', this._onOpen.bind(this));
			this.ws.addEventListener('message', this._onMessage.bind(this));
			this.ws.addEventListener('close', this._onClose.bind(this));
			this.ws.addEventListener('error', this._onError.bind(this));
		} catch (err) {
			console.error('OpenSMS: WebSocket connection error:', err);
			this._dispatchStatus('error', err.message);
			this._scheduleReconnect();
		}
	}

	/**
	 * Disconnect from the websocket server
	 */
	disconnect() {
		this._intentionalClose = true;
		this._clearTimers();
		if (this.ws) {
			this.ws.close();
			this.ws = null;
		}
		this._authenticated = false;
		this._dispatchStatus('disconnected');
	}

	/**
	 * Check if connected and authenticated
	 */
	isConnected() {
		return this.ws && this.ws.readyState === WebSocket.OPEN && this._authenticated;
	}

	/**
	 * Handle websocket open event
	 */
	_onOpen() {
		console.log('OpenSMS: WebSocket connected, authenticating...');
		this._reconnectDelay = this.config.reconnect_delay;
		this._dispatchStatus('connecting');

		// Start authentication timeout
		this._authTimer = setTimeout(() => {
			console.error('OpenSMS: Authentication timeout');
			this._dispatchStatus('error', 'Authentication timeout');
			this.ws.close();
		}, this.config.auth_timeout);
	}

	/**
	 * Handle websocket message event
	 */
	_onMessage(ev) {
		let message;
		try {
			message = JSON.parse(ev.data);
			console.log('OpenSMS: Received message:', message);
		} catch (err) {
			console.error('OpenSMS: Error parsing JSON:', err);
			return;
		}

		// Handle authentication request (407 status)
		if (message.status_code === 407) {
			console.log('OpenSMS: Authentication required, sending token');
			this._authenticate();
			return;
		}

		// Handle authenticated response
		if (message.topic === 'authenticated') {
			console.log('OpenSMS: Successfully authenticated');
			clearTimeout(this._authTimer);
			this._authenticated = true;
			this._dispatchStatus('connected');
			this._startPingInterval();
			this._dispatchEvent('authenticated', message);

			// Resolve the pending authentication request if present
			const authRid = message.request_id !== null && message.request_id !== undefined ? String(message.request_id) : null;
			if (authRid && this._pending.has(authRid)) {
				const { resolve, reject } = this._pending.get(authRid);
				this._pending.delete(authRid);
				const status = message.status_string || message.status || 'ok';
				const code = message.status_code || message.code || 200;
				if (status === 'ok' || (code >= 200 && code < 300)) {
					resolve(message);
				} else {
					const err = new Error(message.error || message.message || `Error ${code}`);
					err.code = code;
					reject(err);
				}
			}
			return;
		}

		// Handle pong response
		if (message.topic === 'pong' || message.type === 'pong') {
			this._handlePong();
			const pongRid = message.request_id !== null && message.request_id !== undefined ? String(message.request_id) : null;
			if (pongRid && this._pending.has(pongRid)) {
				const { resolve, reject } = this._pending.get(pongRid);
				this._pending.delete(pongRid);
				const status = message.status_string || message.status || 'ok';
				const code = message.status_code || message.code || 200;
				if (status === 'ok' || (code >= 200 && code < 300)) {
					resolve(message);
				} else {
					const err = new Error(message.error || message.message || `Error ${code}`);
					err.code = code;
					reject(err);
				}
			}
			return;
		}

		// Handle pending request responses
		const rid = message.request_id;
		console.log('OpenSMS: Checking request_id:', rid, 'type:', typeof rid, 'pending:', Array.from(this._pending.keys()));

		// Convert to string for comparison since pending map uses string keys
		const ridStr = rid !== null && rid !== undefined ? String(rid) : null;
		if (ridStr && this._pending.has(ridStr)) {
			const { resolve, reject } = this._pending.get(ridStr);
			this._pending.delete(ridStr);

			// Check status_string (from server) or status, and status_code
			const status = message.status_string || message.status || 'ok';
			const code = message.status_code || message.code || 200;

			console.log('OpenSMS: Response status:', status, 'code:', code);

			if (status === 'ok' || (code >= 200 && code < 300)) {
				resolve(message);
			} else {
				const err = new Error(message.error || message.message || `Error ${code}`);
				err.code = code;
				reject(err);
			}
			return;
		}

		// Handle server-pushed events (SMS messages)
		const serviceName = message.service_name || message.service || 'opensms';
		if (serviceName === 'opensms') {
			const topic = message.topic || 'MESSAGE';
			const payload = message.payload || message;

			// Dispatch to topic-specific handlers
			this._dispatchEvent(topic, payload);

			// Also dispatch to wildcard handlers
			this._dispatchEvent('*', { topic, payload, message });
		}
	}

	/**
	 * Handle websocket close event
	 */
	_onClose(ev) {
		console.log('OpenSMS: WebSocket closed:', ev.code, ev.reason);
		this._clearTimers();
		this._authenticated = false;

		if (!this._intentionalClose) {
			this._dispatchStatus('disconnected');
			this._scheduleReconnect();
		}
	}

	/**
	 * Handle websocket error event
	 */
	_onError(err) {
		console.error('OpenSMS: WebSocket error:', err);
		this._dispatchStatus('error', 'Connection error');
	}

	/**
	 * Send authentication request
	 */
	_authenticate() {
		this.request('authentication', 'opensms', { token: this.token });
	}

	/**
	 * Schedule reconnection attempt
	 */
	_scheduleReconnect() {
		if (this._reconnectTimer) return;

		console.log(`OpenSMS: Reconnecting in ${this._reconnectDelay}ms...`);
		this._reconnectTimer = setTimeout(() => {
			this._reconnectTimer = null;
			// Exponential backoff
			this._reconnectDelay = Math.min(this._reconnectDelay * 1.5, this.config.max_reconnect_delay);
			this.connect();
		}, this._reconnectDelay);
	}

	/**
	 * Start ping interval to keep connection alive
	 */
	_startPingInterval() {
		this._clearPingTimer();
		this._pingTimer = setInterval(() => {
			this._sendPing();
		}, this.config.ping_interval);
	}

	/**
	 * Send ping to server
	 */
	_sendPing() {
		if (!this.isConnected()) return;

		this.request('opensms', 'ping', {});

		// Start pong timeout
		this._pongTimer = setTimeout(() => {
			this._pongRetries++;
			if (this._pongRetries >= this.config.pong_timeout_max_retries) {
				console.error('OpenSMS: Pong timeout, reconnecting...');
				this._dispatchStatus('warning');
				this.ws.close();
			} else {
				console.warn(`OpenSMS: Pong timeout (retry ${this._pongRetries}/${this.config.pong_timeout_max_retries})`);
			}
		}, this.config.pong_timeout);
	}

	/**
	 * Handle pong response
	 */
	_handlePong() {
		clearTimeout(this._pongTimer);
		this._pongRetries = 0;
	}

	/**
	 * Clear all timers
	 */
	_clearTimers() {
		clearTimeout(this._reconnectTimer);
		clearTimeout(this._authTimer);
		clearTimeout(this._pongTimer);
		this._clearPingTimer();
		this._reconnectTimer = null;
		this._authTimer = null;
		this._pongTimer = null;
	}

	/**
	 * Clear ping timer
	 */
	_clearPingTimer() {
		if (this._pingTimer) {
			clearInterval(this._pingTimer);
			this._pingTimer = null;
		}
	}

	/**
	 * Send a request to the websocket server
	 */
	request(service, topic = null, payload = {}) {
		if (!this.ws || this.ws.readyState !== WebSocket.OPEN) {
			return Promise.reject(new Error('WebSocket not connected'));
		}

		const requestId = String(this._nextId++);
		const envelope = {
			request_id: requestId,
			service: service,
			...(topic !== null ? { topic: topic } : {}),
			token: this.token,
			payload: payload
		};

		const raw = JSON.stringify(envelope);
		this.ws.send(raw);

		return new Promise((resolve, reject) => {
			this._pending.set(requestId, { resolve, reject });

			// Timeout for request
			setTimeout(() => {
				if (this._pending.has(requestId)) {
					this._pending.delete(requestId);
					reject(new Error('Request timeout'));
				}
			}, 30000);
		});
	}

	/**
	 * Send an SMS or MMS message via websocket
	 * @param {string} fromDestinationUuid
	 * @param {string} fromNumber
	 * @param {string} toNumber
	 * @param {string} messageText
	 * @param {string} domainUuid
	 * @param {string} userUuid
	 * @param {Array|null} mmsAttachments - Optional array of {content_type, data, filename}
	 */
	sendMessage(fromDestinationUuid, fromNumber, toNumber, messageText, domainUuid, userUuid, mmsAttachments = null) {
		const payload = {
			from_destination_uuid: fromDestinationUuid,
			from_number: fromNumber,
			to_number: toNumber,
			message_text: messageText,
			message_type: (mmsAttachments && mmsAttachments.length > 0) ? 'mms' : 'sms',
			domain_uuid: domainUuid,
			user_uuid: userUuid
		};
		if (mmsAttachments && mmsAttachments.length > 0) {
			payload.mms = mmsAttachments;
		}
		return this.request('opensms', 'send', payload);
	}

	/**
	 * Request message history for a thread
	 */
	getThreadHistory(threadNumber, limit = 50) {
		return this.request('opensms', 'history', {
			thread_number: threadNumber,
			limit: limit,
			user_uuid: typeof opensms_user_uuid !== 'undefined' ? opensms_user_uuid : '',
			domain_uuid: typeof opensms_domain_uuid !== 'undefined' ? opensms_domain_uuid : ''
		});
	}

	/**
	 * Mark messages as read
	 */
	markAsRead(messageUuids) {
		return this.request('opensms', 'mark_read', {
			message_uuids: Array.isArray(messageUuids) ? messageUuids : [messageUuids]
		});
	}

	/**
	 * Block a number for this user
	 */
	blockNumber(number, userUuid, domainUuid) {
		return this.request('opensms', 'block_number', {
			number: number,
			user_uuid: userUuid,
			domain_uuid: domainUuid
		});
	}

	/**
	 * Unblock a number for this user
	 */
	unblockNumber(number, userUuid, domainUuid) {
		return this.request('opensms', 'unblock_number', {
			number: number,
			user_uuid: userUuid,
			domain_uuid: domainUuid
		});
	}

	/**
	 * List blocked numbers for this user
	 */
	listBlocked(userUuid, domainUuid) {
		return this.request('opensms', 'list_blocked', {
			user_uuid: userUuid,
			domain_uuid: domainUuid
		});
	}

	/**
	 * Hide a thread for this user
	 */
	hideThread(threadNumber, userUuid, domainUuid) {
		return this.request('opensms', 'hide_thread', {
			thread_number: threadNumber,
			user_uuid: userUuid,
			domain_uuid: domainUuid
		});
	}

	/**
	 * Unhide a thread for this user
	 */
	unhideThread(threadNumber, userUuid, domainUuid) {
		return this.request('opensms', 'unhide_thread', {
			thread_number: threadNumber,
			user_uuid: userUuid,
			domain_uuid: domainUuid
		});
	}

	/**
	 * List hidden threads for this user
	 */
	listHidden(userUuid, domainUuid) {
		return this.request('opensms', 'list_hidden', {
			user_uuid: userUuid,
			domain_uuid: domainUuid
		});
	}

	/**
	 * Register a callback for events
	 */
	on(topic, handler) {
		if (!this._eventHandlers.has(topic)) {
			this._eventHandlers.set(topic, []);
		}
		this._eventHandlers.get(topic).push(handler);
	}

	/**
	 * Remove event handler
	 */
	off(topic, handler) {
		if (!this._eventHandlers.has(topic)) return;
		const handlers = this._eventHandlers.get(topic);
		const idx = handlers.indexOf(handler);
		if (idx !== -1) {
			handlers.splice(idx, 1);
		}
	}

	/**
	 * Dispatch event to registered handlers
	 */
	_dispatchEvent(topic, data) {
		const handlers = this._eventHandlers.get(topic) || [];
		for (const fn of handlers) {
			try {
				fn(data);
			} catch (err) {
				console.error(`OpenSMS: Error in event handler for "${topic}":`, err);
			}
		}
	}

	/**
	 * Dispatch status change event
	 */
	_dispatchStatus(status, message = null) {
		this._dispatchEvent('status', { status, message });
	}
}

// Export for use in other modules
if (typeof window !== 'undefined') {
	window.opensms_ws_client = opensms_ws_client;
}

