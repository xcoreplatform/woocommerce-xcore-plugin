<?php

defined('ABSPATH') || exit;

#[AllowDynamicProperties]
class Xcore_Compatibility
{
	private static $supportedPlugins = [
		'/class-xcore-compatibility-acf.php',
	];

	public function __construct()
	{
		$this->init();
	}

	public function init()
	{
		foreach (self::$supportedPlugins as $class) {
			include_once __DIR__ . $class;
			$class = $this->getClassName($class);
			$this->$class = new $class();
		}
	}

	private function getClassName($file)
	{
		$filteredName = str_replace(['class-', '-'], ['', '_'], $file);
		return basename($filteredName, '.php');
	}
}