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

class opensms_client {
	constructor(url, token) {
		this.ws = new WebSocket(url);
		this.ws.addEventListener('message', this._onMessage.bind(this));
		this._nextId = 1;
		this._pending = new Map();
		this._eventHandlers = new Map();
		// The token is submitted on every request
		this.token = token;
	}

	// internal message handler called when event occurs on the socket
	_onMessage(ev) {
		let message;
		let sms_event;
		try {
			message = JSON.parse(ev.data);
			// check for authentication request
			if (message.status_code === 407) {
				console.log('Authentication Required');
				return;
			}
			sms_event = message.payload;
		} catch (err) {
			console.error('Error parsing JSON data:', err);
			return;
		}

		// Pull out the request_id first
		const rid = message.request_id ?? null;

		// If this is the response to a pending request
		if (rid && this._pending.has(rid)) {
			// Destructure with defaults in case they're missing
			const {
				service,
				topic = '',
				status = 'ok',
				code = 200,
				payload = {}
			} = message;

			const {resolve, reject} = this._pending.get(rid);
			this._pending.delete(rid);

			if (status === 'ok' && code >= 200 && code < 300) {
				resolve({service, topic, payload, code, message});
			} else {
				const err = new Error(message || `Error ${code}`);
				err.code = code;
				reject(err);
			}

			return;
		}

		// Otherwise it's a server-pushed event
		this._dispatchEvent(message.service_name, sms_event);
	}

	// Send a request to the websocket server using JSON string
	request(service, topic = null, payload = {}) {
		const request_id = String(this._nextId++);
		const env = {
			request_id: request_id,
			service,
			...(topic !== null ? {topic} : {}),
			token: this.token,
			payload: payload
		};
		const raw = JSON.stringify(env);
		this.ws.send(raw);
		return new Promise((resolve, reject) => {
			this._pending.set(request_id, {resolve, reject});
		});
	}

	subscribe(topic) {
		return this.request('opensms', topic);
	}

	unsubscribe(topic) {
		return this.request('opensms', topic);
	}

	// Send an SMS message via websocket
	sendMessage(to_number, from_number, message_text, domain_name) {
		return this.request('opensms', 'send', {
			to_number: to_number,
			from_number: from_number,
			sms: message_text,
			domain_name: domain_name,
			type: 'sms'
		});
	}

	// Request message history
	getHistory(limit = 50) {
		return this.request('opensms', 'history', {limit: limit});
	}

	// register a callback for server-pushes
	onEvent(topic, handler) {
		console.log('registering event listener for ' + topic);
		if (!this._eventHandlers.has(topic)) {
			this._eventHandlers.set(topic, []);
		}
		this._eventHandlers.get(topic).push(handler);
	}

	/**
	 * Dispatch a server-push event envelope to all registered handlers.
	 * @param {string} service
	 * @param {object} env
	 */
	_dispatchEvent(service, env) {
		let event = (typeof env === 'string')
			? JSON.parse(env)
			: env;

		// dispatch event handlers
		if (service === 'opensms') {
			const topic = event.event_name || 'MESSAGE';

			let handlers = this._eventHandlers.get(topic) || [];
			if (handlers.length === 0) {
				handlers = this._eventHandlers.get('*') || [];
			}
			for (const fn of handlers) {
				try {
					fn(event);
				} catch (err) {
					console.error(`Error in handler for "${topic}":`, err);
				}
			}
		} else {
			const handlers = this._eventHandlers.get(service) || [];
			for (const fn of handlers) {
				try {
					fn(event.data, event);
				} catch (err) {
					console.error(`Error in handler for "${service}":`, err);
				}
			}
		}
	}
}
