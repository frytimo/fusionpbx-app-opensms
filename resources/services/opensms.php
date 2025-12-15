#!/usr/bin/env php
<?php

declare(strict_types=1);

if (version_compare(PHP_VERSION, '7.1.0', '<')) {
	die("This script requires PHP 7.1.0 or higher. You are running " . PHP_VERSION . "\n");
}

//
// Only run from the command line
//
if (PHP_SAPI !== 'cli') {
	die('This script can only be run from the command line.');
}

//
// Get the framework files
//
require_once dirname(__DIR__, 4) . '/resources/require.php';

try {

	//
	// Create a web socket service
	//
	$service = opensms_service::create();

	//
	// Exit with status code given by run return value
	//
	exit($service->run());
} catch (Throwable $ex) {

	////////////////////////////////////////////////////
	// Here we catch all exceptions and log the error //
	////////////////////////////////////////////////////
	//
	// Get the error details
	//
	$message = $ex->getMessage();
	$code = $ex->getCode();
	$file = $ex->getFile();
	$line = $ex->getLine();

	//
	// Show user the details
	//
	echo "FATAL ERROR: '$message' (ERROR CODE: $code) FROM $file (Line: $line)\n";
	echo $ex->getTraceAsString() . "\n";

	//
	// Exit with non-zero status code
	//
	exit($ex->getCode());
}