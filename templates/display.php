<?php
// SPDX-License-Identifier: EUPL-1.2
// The public hall-screen page (timetabling-display-screens). The token reaches
// the script through initial state, never a data attribute.

use OCP\Util;

Util::addTranslations(OCA\Learniq\AppInfo\Application::APP_ID);
Util::addScript(OCA\Learniq\AppInfo\Application::APP_ID, 'learniq-display');
?>
<div id="learniq-display"></div>
