import {
	loadTranslations,
	translatePlural as n,
	translate as t,
} from '@nextcloud/l10n'
// SPDX-License-Identifier: EUPL-1.2
import { createApp, h } from 'vue'
import AdminRoot from './views/settings/AdminRoot.vue'
import pinia from './pinia.js'

const app = createApp({
	render: () => h(AdminRoot),
})

app.use(pinia)
// Vue 3 scopes mixins to the app instance, not the global runtime.
app.mixin({ methods: { t, n } })

// ⚠️ Mount is deliberately NOT inside the loadTranslations callback.
//
// It used to be. Some Nextcloud installs only allow the JS/CSS allowlist
// through Apache, so `/custom_apps/learniq/l10n/<locale>.json` 404s — the
// callback then never fires and the whole admin panel renders BLANK with no
// error surfaced anywhere. Translations are a progressive enhancement: mount
// first and let strings fall back to their English source on a miss.
// (src/main.js already booted this way; this entry point did not.)
try {
	const result = loadTranslations('learniq', () => {})
	if (result && typeof result.then === 'function') {
		result.then(
			() => {},
			() => {},
		)
	}
} catch {
	// no-op — English source strings are the fallback.
}

app.mount('#learniq-settings')
