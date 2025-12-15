<?php

class opensms_smarty_template implements opensms_template_engine_interface {
	protected $template_directory;
	protected $variables = [];
	protected $engine;

	public function __construct(?string $directory = null) {
		// Set the template directory
		if ($directory === null) {
			$directory = dirname(__DIR__, 1) . '/views';
		}
		$this->set_template_directory($directory);

		// When the autoloader is not able to find the Smarty class, include it manually
		if (!class_exists('Smarty')) {
			require_once dirname(__DIR__, 4) . '/resources/templates/engine/smarty/Smarty.class.php';
		}
		$this->engine = new Smarty();

		// Configure Smarty directories
		$this->engine->setTemplateDir($this->template_directory);

		// Use /dev/shm for temp files if available, otherwise use system temp directory
		if (file_exists('/dev/shm')) {
			$temp = '/dev/shm';
		} else {
			$temp = sys_get_temp_dir();
		}
		$this->engine->setCompileDir($temp);
		$this->engine->setCacheDir($temp);
	}

	public function set_template_directory(string $directory): void {
		// Remove all trailing slashes
		while (substr($directory, -1) === '/') {
			$directory = substr($directory, 0, -1);
		}
		// Set the template directory
		$this->template_directory = $directory;
	}

	public function assign(string $key, $value): void {
		$this->variables[$key] = $value;
	}

	public function display(?string $template_file = null): void {
		$this->engine->display($this->template_directory . '/' . ($template_file ?? 'layout.tpl'), $this->variables);
	}

	public function render(?string $template_file = null): string {
		return $this->engine->fetch($this->template_directory . '/' . ($template_file ?? 'layout.tpl'), $this->variables);
	}
}
