<?php

class provider_array_importer {
	const PROVIDERS_APP_UUID = '35187839-237e-4271-b8a1-9b9c45dc8833';

	public static function import_from_url(database $database, string $source_url, array $options = []): array {
		$mode   = $options['mode'] ?? 'new-only';
		$filter = $options['provider_filter'] ?? [];

		$result = [
			'source_url'     => $source_url,
			'normalized_url' => '',
			'total'          => 0,
			'imported'       => 0,
			'updated'        => 0,
			'skipped'        => 0,
			'failed'         => 0,
			'details'        => [],
		];

		$normalized_url           = self::normalize_source_url($source_url);
		$result['normalized_url'] = $normalized_url;

		$content   = self::fetch_source($normalized_url);
		$providers = self::parse_providers_from_php($content);

		return self::import_from_providers($database, $providers, $source_url, $normalized_url, $options);
	}

	public static function import_from_php_content(database $database, string $php_content, array $options = []): array {
		$providers = self::parse_providers_from_php($php_content);

		return self::import_from_providers($database, $providers, 'inline', 'inline', $options);
	}

	private static function import_from_providers(database $database, array $providers, string $source_url, string $normalized_url, array $options = []): array {
		$mode   = $options['mode'] ?? 'new-only';
		$filter = $options['provider_filter'] ?? [];

		$providers = array_values(array_filter($providers, function ($provider) {
			return !self::is_template_provider($provider);
		}));

		if (!empty($filter)) {
			$providers = array_values(array_filter($providers, function ($provider) use ($filter) {
				$provider_name = strtolower(trim($provider['provider_name'] ?? ''));

				return in_array($provider_name, $filter, true);
			}));
		}

		$result = [
			'source_url'     => $source_url,
			'normalized_url' => $normalized_url,
			'total'          => count($providers),
			'imported'       => 0,
			'updated'        => 0,
			'skipped'        => 0,
			'failed'         => 0,
			'details'        => [],
		];

		foreach ($providers as $provider) {
			$entry = [
				'provider_name' => $provider['provider_name'] ?? '(unknown)',
				'status'        => 'failed',
				'message'       => '',
			];

			try {
				$normalized_provider = self::normalize_provider_row($provider);
				$existing            = self::find_existing_provider($database, $normalized_provider);

				if (!empty($existing) && $mode === 'new-only') {
					$entry['status']  = 'skipped';
					$entry['message'] = 'Provider already exists.';
					$result['skipped']++;
					$result['details'][] = $entry;
					continue;
				}

				if (!empty($existing) && $mode === 'upsert') {
					$normalized_provider['provider_uuid'] = $existing['provider_uuid'];
					self::clear_provider_children($database, $existing['provider_uuid']);
					self::save_provider($database, $normalized_provider);
					$entry['status']  = 'updated';
					$entry['message'] = 'Provider updated.';
					$result['updated']++;
					$result['details'][] = $entry;
					continue;
				}

				self::save_provider($database, $normalized_provider);
				$entry['status']  = 'imported';
				$entry['message'] = 'Provider imported.';
				$result['imported']++;
			} catch (Throwable $e) {
				$entry['status']  = 'failed';
				$entry['message'] = $e->getMessage();
				$result['failed']++;
			}

			$result['details'][] = $entry;
		}

		return $result;
	}

	public static function list_from_url(string $source_url): array {
		$normalized_url = self::normalize_source_url($source_url);
		$content        = self::fetch_source($normalized_url);
		$providers      = self::parse_providers_from_php($content);

		return self::provider_list_result($providers, $normalized_url);
	}

	public static function list_from_php_content(string $php_content): array {
		$providers = self::parse_providers_from_php($php_content);

		return self::provider_list_result($providers, 'inline');
	}

	private static function provider_list_result(array $providers, string $normalized_url): array {
		$list = [];
		foreach ($providers as $provider) {
			if (self::is_template_provider($provider)) {
				continue;
			}

			$provider_name = trim($provider['provider_name'] ?? '');
			if ($provider_name === '') {
				continue;
			}
			$list[] = $provider_name;
		}

		return [
			'normalized_url' => $normalized_url,
			'providers'      => $list,
		];
	}

	private static function is_template_provider(array $provider): bool {
		$provider_name = strtolower(trim($provider['provider_name'] ?? ''));

		return $provider_name === 'add a provider';
	}

	private static function normalize_source_url(string $source_url): string {
		$source_url = trim($source_url);
		if ($source_url === '') {
			throw new InvalidArgumentException('Source URL is required.');
		}

		$parts = parse_url($source_url);
		if (empty($parts['scheme']) || empty($parts['host'])) {
			throw new InvalidArgumentException('Source URL must be absolute.');
		}

		$host = strtolower($parts['host']);
		$path = $parts['path'] ?? '';

		if ($host === 'github.com' && preg_match('#^/fusionpbx/fusionpbx-app-messages/?$#', $path)) {
			return 'https://raw.githubusercontent.com/fusionpbx/fusionpbx-app-messages/main/app/messages/resources/providers/settings.php';
		}

		if ($host === 'github.com' && preg_match('#^/([^/]+)/([^/]+)/blob/([^/]+)/(.+)$#', $path, $m)) {
			return 'https://raw.githubusercontent.com/' . $m[1] . '/' . $m[2] . '/' . $m[3] . '/' . $m[4];
		}

		return $source_url;
	}

	private static function fetch_source(string $url): string {
		$parts = parse_url($url);
		$host  = strtolower($parts['host'] ?? '');

		$allowed_hosts = [
			'raw.githubusercontent.com',
			'github.com',
		];

		if (!in_array($host, $allowed_hosts, true)) {
			throw new RuntimeException('Host is not allowlisted for provider import.');
		}

		$context = stream_context_create([
			'http' => [
				'method'          => 'GET',
				'timeout'         => 20,
				'follow_location' => 1,
				'user_agent'      => 'FusionPBX Provider Importer',
			],
		]);

		$content    = @file_get_contents($url, false, $context);
		$last_error = '';

		if ($content === false && function_exists('curl_init')) {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
			curl_setopt($ch, CURLOPT_TIMEOUT, 20);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_USERAGENT, 'FusionPBX Provider Importer');
			$response   = curl_exec($ch);
			$http_code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$curl_error = curl_error($ch);
			curl_close($ch);

			if ($response !== false && $http_code >= 200 && $http_code < 300) {
				$content = $response;
			} else {
				$last_error = $curl_error !== '' ? $curl_error : 'HTTP ' . $http_code;
			}
		}

		if ($content === false && str_contains($url, 'fusionpbx/fusionpbx-app-messages')) {
			$fallback_urls = [
				'https://raw.githubusercontent.com/fusionpbx/fusionpbx-app-messages/main/app/messages/resources/providers/settings.php',
				'https://raw.githubusercontent.com/fusionpbx/fusionpbx-app-messages/master/app/messages/resources/providers/settings.php',
				'https://raw.githubusercontent.com/fusionpbx/fusionpbx-app-messages/main/app/providers/resources/providers/settings.php',
				'https://raw.githubusercontent.com/fusionpbx/fusionpbx-app-messages/master/app/providers/resources/providers/settings.php',
			];

			foreach ($fallback_urls as $fallback_url) {
				if ($fallback_url === $url) {
					continue;
				}

				$fallback_content = @file_get_contents($fallback_url, false, $context);
				if ($fallback_content !== false) {
					$content = $fallback_content;
					break;
				}
			}
		}

		if ($content === false) {
			$detail = $last_error !== '' ? ' ' . $last_error : '';
			throw new RuntimeException('Unable to fetch provider source URL.' . $detail);
		}

		if (strlen($content) > 5 * 1024 * 1024) {
			throw new RuntimeException('Provider source file is too large.');
		}

		return $content;
	}

	private static function parse_providers_from_php(string $content): array {
		$tmp_file = tempnam(sys_get_temp_dir(), 'provider_import_');
		if ($tmp_file === false) {
			throw new RuntimeException('Unable to create temporary file for parsing.');
		}

		file_put_contents($tmp_file, $content);

		$array = [];
		try {
			include $tmp_file;
		} catch (Throwable $e) {
			@unlink($tmp_file);
			throw new RuntimeException('Failed to parse provider PHP source: ' . $e->getMessage());
		}

		@unlink($tmp_file);

		if (empty($array['providers']) || !is_array($array['providers'])) {
			throw new RuntimeException('Source does not define a valid providers array.');
		}

		return array_values($array['providers']);
	}

	private static function normalize_provider_row(array $provider): array {
		$provider_name = trim($provider['provider_name'] ?? '');
		if ($provider_name === '') {
			throw new InvalidArgumentException('Provider is missing provider_name.');
		}

		$provider_uuid = $provider['provider_uuid'] ?? '';
		if (!is_uuid($provider_uuid)) {
			$provider_uuid = uuid();
		}

		$normalized = [
			'provider_uuid'        => $provider_uuid,
			'provider_name'        => $provider_name,
			'provider_enabled'     => $provider['provider_enabled'] ?? 'true',
			'provider_description' => $provider['provider_description'] ?? '',
			'provider_settings'    => [],
			'provider_addresses'   => [],
		];

		$settings = $provider['provider_settings'] ?? [];
		if (is_array($settings)) {
			$y = 0;
			foreach ($settings as $row) {
				$normalized['provider_settings'][$y] = [
					'provider_uuid'                => $provider_uuid,
					'application_uuid'             => $row['application_uuid'] ?? '',
					'provider_setting_uuid'        => (is_uuid($row['provider_setting_uuid'] ?? null) ? $row['provider_setting_uuid'] : uuid()),
					'provider_setting_category'    => $row['provider_setting_category'] ?? '',
					'provider_setting_subcategory' => $row['provider_setting_subcategory'] ?? '',
					'provider_setting_type'        => $row['provider_setting_type'] ?? 'text',
					'provider_setting_name'        => $row['provider_setting_name'] ?? '',
					'provider_setting_value'       => $row['provider_setting_value'] ?? '',
					'provider_setting_order'       => $row['provider_setting_order'] ?? '',
					'provider_setting_enabled'     => $row['provider_setting_enabled'] ?? 'true',
					'provider_setting_description' => $row['provider_setting_description'] ?? '',
				];
				$y++;
			}
		}

		$addresses = $provider['provider_addresses'] ?? [];
		if (is_array($addresses)) {
			$y = 0;
			foreach ($addresses as $row) {
				$normalized['provider_addresses'][$y] = [
					'provider_uuid'                => $provider_uuid,
					'provider_address_uuid'        => (is_uuid($row['provider_address_uuid'] ?? null) ? $row['provider_address_uuid'] : uuid()),
					'provider_address_cidr'        => $row['provider_address_cidr'] ?? '',
					'provider_address_enabled'     => $row['provider_address_enabled'] ?? 'true',
					'provider_address_description' => $row['provider_address_description'] ?? '',
				];
				$y++;
			}
		}

		return $normalized;
	}

	private static function find_existing_provider(database $database, array $provider): ?array {
		$sql        = "select provider_uuid, provider_name from v_providers ";
		$sql       .= "where provider_uuid = :provider_uuid ";
		$sql       .= "or lower(provider_name) = :provider_name ";
		$parameters = [
			'provider_uuid' => $provider['provider_uuid'],
			'provider_name' => strtolower($provider['provider_name']),
		];
		$row        = $database->select($sql, $parameters, 'row');

		return is_array($row) && !empty($row['provider_uuid']) ? $row : null;
	}

	private static function clear_provider_children(database $database, string $provider_uuid): void {
		$sql = "delete from v_provider_settings where provider_uuid = :provider_uuid";
		$database->execute($sql, ['provider_uuid' => $provider_uuid]);

		$sql = "delete from v_provider_addresses where provider_uuid = :provider_uuid";
		$database->execute($sql, ['provider_uuid' => $provider_uuid]);
	}

	private static function save_provider(database $database, array $provider): void {
		$payload            = ['providers' => [$provider]];
		$database->app_name = 'providers';
		$database->app_uuid = self::PROVIDERS_APP_UUID;
		$database->save($payload);
	}
}
