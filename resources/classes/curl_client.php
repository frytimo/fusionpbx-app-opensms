<?php

class curl_client {

	/**
	 * Generic request handler.
	 */
	public function request(string $url, string $method = 'GET', array $options = []): array {

		$ch = curl_init();

		$default_opts = [
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
		];

		// Method handling
		if (strtoupper($method) === 'POST') {
			$default_opts[CURLOPT_POST]       = true;
			$default_opts[CURLOPT_POSTFIELDS] = $options['post_fields'] ?? [];
		}

		// Additional headers
		if (!empty($options['headers'])) {
			$default_opts[CURLOPT_HTTPHEADER] = $options['headers'];
		}

		// Merge user options
		foreach ($options as $k => $v) {
			if (is_int($k)) continue;
			$default_opts[$k] = $v;
		}

		curl_setopt_array($ch, $default_opts);

		$response_body = curl_exec($ch);
		$info          = curl_getinfo($ch);
		$error         = curl_error($ch);

		unset($ch);

		return [
			'content' => $response_body, // Raw binary OK
			'info'    => $info,
			'error'   => $error,
		];
	}

	/**
	 * Shortcut for GET request.
	 */
	public function get(string $url, array $headers = []): array {
		return $this->request($url, 'GET', [
			'headers' => $headers
		]);
	}

	/**
	 * Shortcut for POST request.
	 */
	public function post(string $url, array $fields = [], array $headers = []): array {
		return $this->request($url, 'POST', [
			'post_fields' => $fields,
			'headers'     => $headers
		]);
	}
}
