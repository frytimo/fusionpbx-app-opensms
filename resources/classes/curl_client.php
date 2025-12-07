<?php

class curl_client {

	/**
	 * Generic request handler.
	 */
	public function request(string $url, string $method = 'GET', array $options = []): array {
		$ch = curl_init();

		$default_opts = [
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
		];

		// Method handling
		if (strtoupper($method) === 'POST') {
			$default_opts[CURLOPT_POST] = true;
			$default_opts[CURLOPT_POSTFIELDS] = $options['post_fields'] ?? [];
		}

		// HTTP Basic Authentication
		if (!empty($options['username']) && !empty($options['password'])) {
			$default_opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
			$default_opts[CURLOPT_USERPWD] = $options['username'] . ':' . $options['password'];
		} elseif (!empty($options['username'])) {
			// Username only (password can be empty string)
			$default_opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
			$default_opts[CURLOPT_USERPWD] = $options['username'] . ':';
		}

		// Additional headers
		if (!empty($options['headers'])) {
			$default_opts[CURLOPT_HTTPHEADER] = $options['headers'];
		}

		// Merge user options (only integer keys, which are valid CURLOPT_* constants)
		foreach ($options as $k => $v) {
			if (!is_int($k))
				continue;
			$default_opts[$k] = $v;
		}

		curl_setopt_array($ch, $default_opts);

		$response_body = curl_exec($ch);
		$info = curl_getinfo($ch);
		$error = curl_error($ch);

		unset($ch);

		return [
			'content' => $response_body,  // Raw binary OK
			'info' => $info,
			'error' => $error,
		];
	}

	/**
	 * Shortcut for GET request.
	 */
	public function get(string $url, array $headers = [], ?string $username = null, ?string $password = null): array {
		$options = ['headers' => $headers];

		if ($username !== null) {
			$options['username'] = $username;
			$options['password'] = $password ?? '';
		}

		return $this->request($url, 'GET', $options);
	}

	/**
	 * Shortcut for POST request.
	 */
	public function post(string $url, array $fields = [], array $headers = [], ?string $username = null, ?string $password = null): array {
		$options = [
			'post_fields' => $fields,
			'headers' => $headers
		];

		if ($username !== null) {
			$options['username'] = $username;
			$options['password'] = $password ?? '';
		}

		return $this->request($url, 'POST', $options);
	}
}
