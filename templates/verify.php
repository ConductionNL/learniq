<?php
// SPDX-License-Identifier: EUPL-1.2
// The public certificate check page (certificate-verification-and-key-rotation).
// The id and the school's phone number reach the script through initial
// state, never a data attribute.

use OCP\Util;

Util::addTranslations(OCA\Learniq\AppInfo\Application::APP_ID);
Util::addScript(OCA\Learniq\AppInfo\Application::APP_ID, 'learniq-verify');
?>
<div id="learniq-verify"></div>
