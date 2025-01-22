<?php

// First, try to load Composer's autoloader if it exists
$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
	require_once $composerAutoload;
}

// Only register our custom autoloader if the class doesn't already exist
// This prevents double-loading and gives precedence to Composer's autoloader
if (!class_exists('EmailIt\\EmailItClient')) {
	function emailit_autoload($class_name) {
		// Only handle classes in our namespace
		$prefix = 'EmailIt\\';
		
		// Check if the class uses our prefix
		if (strncmp($prefix, $class_name, strlen($prefix)) !== 0) {
			return;
		}
		
		// Get the relative class name
		$relative_class = substr($class_name, strlen($prefix));
		
		// Replace namespace separators with directory separators
		// and append '.php'
		$file = __DIR__ . '/src/EmailIt/' . str_replace('\\', '/', $relative_class) . '.php';
		
		// If the file exists, require it
		if (file_exists($file)) {
			require_once $file;
		}
	}

	spl_autoload_register('emailit_autoload');
}