<?php
// SPDX-License-Identifier: EUPL-1.2

use OCP\Util;

$appId = OCA\Learniq\AppInfo\Application::APP_ID;
Util::addScript($appId, $appId . '-settings');
?>
<div id="learniq-settings"></div>
