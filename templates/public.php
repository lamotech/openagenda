<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\OpenAgenda\AppInfo\Application::APP_ID, OCA\OpenAgenda\AppInfo\Application::APP_ID . '-public');
Util::addStyle(OCA\OpenAgenda\AppInfo\Application::APP_ID, OCA\OpenAgenda\AppInfo\Application::APP_ID . '-public');

?>

<div id="openagenda-public"></div>
