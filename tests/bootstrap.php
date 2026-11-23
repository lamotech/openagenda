<?php

declare(strict_types=1);

// Use the server test bootstrap when the server source tree with tests is available,
// otherwise boot Nextcloud directly (release installations ship without /tests).
if (file_exists(__DIR__ . '/../../../tests/bootstrap.php')) {
	require_once __DIR__ . '/../../../tests/bootstrap.php';
} else {
	require_once __DIR__ . '/../../../lib/base.php';
}
require_once __DIR__ . '/../vendor/autoload.php';

\OC_App::loadApp(OCA\OpenAgenda\AppInfo\Application::APP_ID);
if (class_exists('OC_Hook')) {
	OC_Hook::clear();
}
