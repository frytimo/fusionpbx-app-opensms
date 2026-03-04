<?php

/**
 * Class opensms_message_websocket_writer
 *
 * This class implements the opensms_message_listener interface to broadcast incoming
 * OpenSMS messages to connected websocket clients for real-time updates.
 *
 * When a message is received from an SMS provider callback, this listener sends
 * the message to the websocket server which then broadcasts it to all connected
 * clients subscribed to the opensms service.
 *
 * @author Tim Fry <tim@fusionpbx.com>
 */
class opensms_message_websocket_writer implements opensms_message_listener {

	/**
	 * Handle an incoming message and broadcast it to websocket clients.
	 *
	 * Connects to the websocket server and sends the message so that all
	 * subscribed clients receive real-time updates of new messages.
	 *
	 * @param settings        $settings Configuration and resources required for websocket connection
	 * @param opensms_message $message  The message instance to broadcast
	 *
	 * @return void
	 */
	public function on_message(settings $settings, opensms_message $message): void {
		// Get websocket connection settings
		$host = $settings->get('websocket', 'host', '127.0.0.1');
		$port = $settings->get('websocket', 'port', 8080);

		try {
			// Create websocket client connection
			$ws_client = new websocket_client("ws://{$host}:{$port}");

			// Set blocking for handshake
			$ws_client->set_blocking(true);

			// Connect to the websocket server
			$ws_client->connect();

			// Consume initial auth challenge if present
			$initial_response = $ws_client->read();
			if (!empty($initial_response)) {
				$initial_data = json_decode($initial_response, true);
				if (!empty($initial_data['status_code']) && (int)$initial_data['status_code'] !== 407) {
					// If it is not an auth challenge, continue anyway
				}
			}

			// Authenticate as a broadcast-only opensms service using a unique name
			// to avoid colliding with the long-running opensms_service in the
			// websocket router's service registry
			$broadcast_service_name = 'opensms_broadcast_' . uniqid();
			[$token_name, $token_hash] = websocket_client::create_service_token(
				$broadcast_service_name,
				opensms_service::class
			);
			$ws_client->authenticate($token_name, $token_hash);
			$auth_response = $ws_client->read();
			if (!empty($auth_response)) {
				$auth_data = json_decode($auth_response, true);
				if (!empty($auth_data['status_code']) && (int)$auth_data['status_code'] !== 200) {
					throw new \RuntimeException('WebSocket authentication failed');
				}
			}

			// Create the websocket message
			$ws_message = new websocket_message();
			$ws_message->service_name(opensms_service::get_service_name());

			// Handle delivery receipts differently from inbound messages
			if ($message->type === 'delivery_receipt') {
				$ws_message->topic('DELIVERY_RECEIPT');
				$payload = [
					'original_message_uuid' => $message->delivery_original_uuid,
					'delivery_status'       => $message->delivery_status,
					'delivery_time'         => $message->time ?: date('Y-m-d H:i:s'),
					'delivery_detail'       => $message->sms,
					'from_number'           => $message->from_number,
					'to_number'             => $message->to_number,
				];
			} else {
				$ws_message->topic('MESSAGE');
				$payload = [
					'message_uuid'     => $message->uuid,
					'direction'        => 'inbound',
					'from_number'      => $message->from_number,
					'to_number'        => $message->to_number,
					'message_text'     => $message->sms,
					'message_type'     => $message->type,
					'message_time'     => $message->time ?: date('Y-m-d H:i:s'),
					'domain_uuid'      => $message->domain_uuid,
					'domain_name'      => $message->domain_name,
					'user_uuid'        => $message->user_uuid,
					'provider_uuid'    => $message->provider_uuid,
					'destination_uuid' => $message->destination_uuid,
					'mms'              => !empty($message->mms) ? $message->mms : null,
				];
			}

			$ws_message->payload($payload);

			// Send the message to the websocket server
			websocket_client::send($ws_client->socket(), $ws_message);

			// Allow the server time to process the message frame before
			// the close frame is sent during disconnect
			usleep(200000);

			// Disconnect from the websocket server
			$ws_client->disconnect();

		} catch (\Exception $e) {
			// Log error but don't throw - we don't want to stop other listeners
			error_log("OpenSMS WebSocket Writer Error: " . $e->getMessage());
		}
	}
}
