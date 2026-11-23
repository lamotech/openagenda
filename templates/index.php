<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\OpenAgenda\AppInfo\Application::APP_ID, OCA\OpenAgenda\AppInfo\Application::APP_ID . '-main');
Util::addStyle(OCA\OpenAgenda\AppInfo\Application::APP_ID, OCA\OpenAgenda\AppInfo\Application::APP_ID . '-main');

?>

<div id="openagenda"></div>
