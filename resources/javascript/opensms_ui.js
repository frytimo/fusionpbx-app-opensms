// app/opensms/resources/js/opensms_ui.js

(function () {
	"use strict";

	function $(id) {
		return document.getElementById(id);
	}

	function set_ws_status(text, dot_connected) {
		var el = $("opensms_ws_status");
		if (el) el.textContent = text;

		// Dot coloring: keep simple by swapping opacity via inline style
		var dot = document.querySelector(".opensms_dot");
		if (dot) dot.style.opacity = dot_connected ? "1" : "0.4";
	}

	function escape_html(str) {
		return String(str)
			.replaceAll("&", "&amp;")
			.replaceAll("<", "&lt;")
			.replaceAll(">", "&gt;")
			.replaceAll('"', "&quot;")
			.replaceAll("'", "&#039;");
	}

	function append_message_bubble(msg) {
		var messages = $("opensms_messages");
		if (!messages) return;

		var is_outbound = (msg.direction === "outbound");
		var row = document.createElement("div");
		row.className = "opensms_bubble_row " + (is_outbound ? "is_outbound" : "is_inbound");

		var bubble = document.createElement("div");
		bubble.className = "opensms_bubble";

		var body = document.createElement("div");
		body.className = "opensms_bubble_body";
		body.innerHTML = escape_html(msg.body || "").replaceAll("\n", "<br>");

		var meta = document.createElement("div");
		meta.className = "opensms_bubble_meta";
		meta.innerHTML = "<span class='opensms_bubble_time'>" + escape_html(msg.time || "") + "</span>" +
			(is_outbound ? "<span class='opensms_bubble_status'>" + escape_html(msg.status || "") + "</span>" : "");

		bubble.appendChild(body);
		bubble.appendChild(meta);
		row.appendChild(bubble);

		messages.appendChild(row);
		messages.scrollTop = messages.scrollHeight;
	}

	function open_contacts_modal() {
		var backdrop = $("opensms_contacts_backdrop");
		if (backdrop) backdrop.hidden = false;
	}

	function close_contacts_modal() {
		var backdrop = $("opensms_contacts_backdrop");
		if (backdrop) backdrop.hidden = true;
	}

	function init_thread_clicks() {
		var list = $("opensms_thread_list");
		if (!list) return;

		list.addEventListener("click", function (ev) {
			var btn = ev.target.closest(".opensms_thread_item");
			if (!btn) return;

			// Toggle active state
			list.querySelectorAll(".opensms_thread_item").forEach(function (b) {
				b.classList.remove("is_active");
				b.setAttribute("aria-selected", "false");
			});
			btn.classList.add("is_active");
			btn.setAttribute("aria-selected", "true");

			// Update active thread id + label
			var thread_id = btn.getAttribute("data_thread_id") || "";
			$("opensms_active_thread_id").value = thread_id;

			var label = btn.querySelector(".opensms_thread_label");
			$("opensms_chat_with_label").textContent = label ? label.textContent : "Conversation";

			// In a real implementation, you would fetch messages for this thread (AJAX) or request via WS.
			// For now, clear the view as a placeholder.
			var messages = $("opensms_messages");
			if (messages) {
				messages.innerHTML = "<div class='opensms_empty_chat'>Loading thread...</div>";
			}
		});
	}

	function init_send_form(ws) {
		var form = $("opensms_send_form");
		var textarea = $("opensms_message_body");

		if (!form || !textarea) return;

		textarea.addEventListener("keydown", function (ev) {
			if (ev.key === "Enter" && !ev.shiftKey) {
				ev.preventDefault();
				form.requestSubmit();
			}
		});

		form.addEventListener("submit", function (ev) {
			ev.preventDefault();

			var body = (textarea.value || "").trim();
			if (!body) return;

			var thread_id = ($("opensms_active_thread_id").value || "").trim();

			// Optimistic UI
			append_message_bubble({
				direction: "outbound",
				body: body,
				time: new Date().toLocaleString(),
				status: "sending"
			});

			textarea.value = "";

			// Send via WebSocket (align payload to your server protocol)
			ws.send({
				type: "send-message",
				thread_id: thread_id,
				body: body
			});
		});
	}

	function init_contacts_buttons() {
		var btn_open = $("opensms_btn_contacts");
		var btn_close = $("opensms_btn_contacts_close");
		var backdrop = $("opensms_contacts_backdrop");

		if (btn_open) btn_open.addEventListener("click", open_contacts_modal);
		if (btn_close) btn_close.addEventListener("click", close_contacts_modal);

		if (backdrop) {
			backdrop.addEventListener("click", function (ev) {
				if (ev.target === backdrop) close_contacts_modal();
			});
		}
	}

	function main() {
		var root = document.getElementById("opensms_app");
		if (!root) return;

		var ws_url = root.getAttribute("data_ws_url") || "";

		var ws = new window.opensms_ws_client({
			ws_url: ws_url,
			on_status: function (state, msg) {
				if (state === "connected") set_ws_status("Connected", true);
				else if (state === "connecting") set_ws_status("Connecting...", false);
				else if (state === "disconnected") set_ws_status("Disconnected", false);
				else if (state === "error") set_ws_status(msg || "Error", false);
			},
			on_event: function (evt) {
				// Map inbound events to bubbles (adjust to your actual server payload)
				if (!evt || !evt.type) return;

				if (evt.type === "message-received") {
					append_message_bubble({
						direction: "inbound",
						body: evt.body || "",
						time: evt.time || ""
					});
				}

				if (evt.type === "message-status") {
					// Later: update status for a specific outbound message id
				}
			}
		});

		ws.connect();

		init_thread_clicks();
		init_send_form(ws);
		init_contacts_buttons();

		var btn_refresh = $("opensms_btn_refresh");
		if (btn_refresh) {
			btn_refresh.addEventListener("click", function () {
				ws.send({ type: "refresh" });
			});
		}
	}

	document.addEventListener("DOMContentLoaded", main);
})();
