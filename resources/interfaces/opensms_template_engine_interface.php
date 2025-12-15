<?php

interface opensms_template_engine_interface {
    public function set_template_directory(string $directory): void;
    public function assign(string $key, $value): void;
	public function display(string $template_file): void;
	public function render(string $template_file): string;
}