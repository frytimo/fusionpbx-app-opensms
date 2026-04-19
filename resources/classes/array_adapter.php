<?php

class array_adapter implements opensms_message_adapter {
	const OPENSMS_PROVIDER_NAME = 'array_import';

	public static function import_from_url(database $database, string $source_url, array $options = []): array {
		return provider_array_importer::import_from_url($database, $source_url, $options);
	}

	public static function list_from_url(string $source_url): array {
		return provider_array_importer::list_from_url($source_url);
	}

	public static function list_from_php_content(string $php_content): array {
		return provider_array_importer::list_from_php_content($php_content);
	}

	public static function import_from_php_content(database $database, string $php_content, array $options = []): array {
		return provider_array_importer::import_from_php_content($database, $php_content, $options);
	}

	public function receive(settings $settings, object $payload): ?opensms_message {
		return null;
	}

	public static function send(settings $settings, opensms_message $message): bool {
		return false;
	}

	public static function has_destination(settings $settings, opensms_message $message): bool {
		return false;
	}

	public function get_to_number(): string {
		return '';
	}

	public function get_from_number(): string {
		return '';
	}

	public function get_time(): ?string {
		return null;
	}

	public function get_sms(): ?string {
		return null;
	}

	public function get_mms(): ?array {
		return null;
	}

	public function get_type(): ?string {
		return null;
	}

	public function get_received_data(): ?string {
		return null;
	}

	public static function has(settings $settings, string $ip_address): bool {
		return false;
	}

	public static function app_defaults(database $database): void {}

	public static function app_config(): ?array {
		return null;
	}

	public static function app_menu(): ?array {
		$menu[0]['title']['en-us'] = "OpenSMS Providers Importer";
		$menu[0]['title']['en-gb'] = "OpenSMS Providers Importer";
		$menu[0]['title']['de-de'] = "";
		$menu[0]['title']['es-cl'] = "";
		$menu[0]['title']['fr-fr'] = "";
		$menu[0]['title']['it-it'] = "";
		$menu[0]['title']['pt-br'] = "";
		$menu[0]['title']['ru-ru'] = "";
		$menu[0]['uuid']           = "90f8d354-f78a-427d-b6a8-41e3704f6b4f";
		$menu[0]['parent_uuid']    = "594d99c5-6128-9c88-ca35-4b33392cec0f";
		$menu[0]['category']       = "internal";
		$menu[0]['icon']           = "";
		$menu[0]['path']           = "/app/opensms/opensms_providers.php";
		$menu[0]['order']          = "";
		$menu[0]['groups'][]       = "superadmin";
		$menu[0]['groups'][]       = "admin";

		return $menu;
	}
}
