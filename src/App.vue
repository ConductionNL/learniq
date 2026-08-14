<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Scholiq app shell. Mounts CnAppRoot with the bundled manifest and the
 v2 kind-tagged registry (ADR-036). CnAppRoot reads manifest.dependencies
 and renders a dependency-missing empty state for absent apps automatically
 (per ADR-024) — no app-local OpenRegisterGuard is needed.

 ⚠️ HARD vs SOFT dependencies. In manifest.dependencies a bare STRING is a HARD
 dependency: CnAppRoot resolves it via useAppStatus() and, if it is not both
 installed AND enabled, switches the whole shell to the blocking
 `dependency-missing` phase (REQ-DIA-5) — nothing else renders.
   • openregister IS hard. Every Scholiq entity is an OpenRegister object;
     without it the app has no data layer at all.
   • openconnector is SOFT ({ id, required: false }). Scholiq calls it from
     exactly two places — LtiToolPlacementController (forwards an LTI 1.3
     OIDC launch to `/apps/openconnector/api/lti/deployments/{id}/launch`)
     and PaymentTransactionController (`/apps/openconnector/api/payments/
     initiate`). Both are optional integrations. Declaring it HARD meant a
     school running Scholiq without LTI or online payments got a completely
     unusable app shell, and it blanked the entire e2e suite on any instance
     where openconnector was absent. As a soft dependency its absence now
     surfaces as a dismissible in-shell notice and degrades only those two
     features. appinfo/info.xml still lists <app>openconnector</app> as an
     integration hint; Nextcloud's DependencyAnalyzer does not enforce
     <app> entries, so that declaration never gated anything.

 The #user-settings slot feeds ScholiqNotificationSettings into CnAppRoot's
 hosted NcAppSettingsDialog, which CnAppNav opens when the user clicks the
 manifest menu entry with action: "user-settings". Per-user settings are
 about which notifications the user receives; instance-wide configuration
 (register, AI features, credential signing) lives in the NC Admin panel.
-->
<template>
	<CnAppRoot
		:manifest="manifest"
		:registry="registry"
		:pageTypes="pageTypes"
		appId="scholiq"
		:translate="translateForApp">
		<template #user-settings>
			<ScholiqNotificationSettings />
		</template>
	</CnAppRoot>
</template>

<script>
import { CnAppRoot } from '@conduction/nextcloud-vue'
import { translate as ncT } from '@nextcloud/l10n'
import ScholiqNotificationSettings from './views/ScholiqNotificationSettings.vue'

export default {
	name: 'App',

	components: {
		CnAppRoot,
		ScholiqNotificationSettings,
	},

	props: {
		/**
		 * Bundled manifest — passed from main.js bootstrap. CnAppRoot reads
		 * `manifest.dependencies` for the dependency-check phase and
		 * `manifest.menu` for the default CnAppNav.
		 */
		manifest: {
			type: Object,
			required: true,
		},

		/**
		 * V2 kind-tagged registry (ADR-036) — each entry is
		 * `{ kind: "page", component: ... }`. CnPageRenderer resolves
		 * every `type:"custom"` page's `component` string against the
		 * `kind: "page"` entries here. Replaces the deprecated
		 * `customComponents` prop.
		 */
		registry: {
			type: Object,
			default: () => ({}),
		},

		/**
		 * Page-type registry — `{ index, detail, dashboard, settings, ... }`.
		 */
		pageTypes: {
			type: Object,
			default: null,
		},
	},

	methods: {
		/**
		 * Translate function passed to CnAppRoot. Closes over the Nextcloud
		 * `translate` import so the lib never has to know our app id.
		 *
		 * @param {string} key Translation key.
		 * @return {string} Translated string (or the key on miss).
		 * @spec exclude framework glue — thin wrapper over @nextcloud/l10n translate that binds the app id for CnAppRoot; no business behavior
		 */
		translateForApp(key) {
			return ncT('scholiq', key)
		},
	},
}
</script>
