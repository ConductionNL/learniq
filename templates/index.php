<?php
// SPDX-License-Identifier: EUPL-1.2

use OCP\Util;

$appId = OCA\Scholiq\AppInfo\Application::APP_ID;
Util::addScript($appId, $appId . '-main');
?>
<?php
// Mount host for the Vue 3 app.
//
// This used to be `<div id="content">`, a DUPLICATE of the `#content` wrapper
// Nextcloud's own layout.user.php already emits. Vue 2's `$mount('#content')`
// REPLACED the element it matched, so the duplication was invisible; Vue 3's
// `mount()` renders INSIDE the match, which would nest the whole app in core's
// wrapper. A dedicated, uniquely-named host removes the ambiguity entirely.
?>
<div id="scholiq-app"></div>
