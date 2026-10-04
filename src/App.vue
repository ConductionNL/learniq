<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Learniq app shell. Mounts CnAppRoot with the bundled manifest and the
 v2 kind-tagged registry (ADR-036). CnAppRoot reads manifest.dependencies
 and renders a dependency-missing empty state for absent apps automatically
 (per ADR-024) — no app-local OpenRegisterGuard is needed.

 ⚠️ HARD vs SOFT dependencies. In manifest.dependencies a bare STRING is a HARD
 dependency: CnAppRoot resolves it via useAppStatus() and, if it is not both
 installed AND enabled, switches the whole shell to the blocking
 `dependency-missing` phase (REQ-DIA-5) — nothing else renders.
   • openregister IS hard. Every Learniq entity is an OpenRegister object;
     without it the app has no data layer at all.
   • openconnector is SOFT ({ id, required: false }). Learniq calls it for
     optional integrations such as LtiToolPlacementController (forwards an
     LTI 1.3 OIDC launch to `/apps/openconnector/api/lti/deployments/{id}/launch`).
     Online payments moved to shillinq (D19) and no longer call it. Declaring
     it HARD meant a school running Learniq without LTI got a completely
     unusable app shell, and it blanked the entire e2e suite on any instance
     where openconnector was absent. As a soft dependency its absence now
     surfaces as a dismissible in-shell notice and degrades only those
     features. appinfo/info.xml still lists <app>openconnector</app> as an
     integration hint; Nextcloud's DependencyAnalyzer does not enforce
     <app> entries, so that declaration never gated anything.

 The #user-settings slot feeds LearniqNotificationSettings into CnAppRoot's
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
		:customComponents="headerActionHandlers"
		:formatters="formatters"
		appId="learniq"
		:translate="translateForApp"
		:initialOrganisationUuid="callerTenant">
		<template #user-settings>
			<LearniqNotificationSettings />
		</template>
		<!-- The tenant context carries learniq's tenant id, not an organisation
		     name, so the library's tenant badge would print a raw id. Keep it
		     hidden, as it was before the context was fed. The slot needs a real
		     element: Vue 3 renders a slot's fallback (the badge) when the slot
		     content is empty, so `<template #tenant-badge />` alone would not
		     suppress it. -->
		<template #tenant-badge>
			<span hidden />
		</template>
	</CnAppRoot>
</template>

<script>
import { CnAppRoot } from '@conduction/nextcloud-vue'
import { getCanonicalLocale, translate as ncT } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import LearniqNotificationSettings from './views/LearniqNotificationSettings.vue'
import { createConnectionHandlers } from './utils/connectionRegistry.js'
import { createFormatters } from './utils/timeBlocks.js'

export default {
	name: 'App',

	components: {
		CnAppRoot,
		LearniqNotificationSettings,
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

		/**
		 * The caller's tenant id (CallerTenantResolver, via the `callerTenant`
		 * initial state), or null. Fed to CnAppRoot as the tenant context so
		 * nextcloud-vue's create dialog fills a hidden `tenant_id` with it.
		 */
		callerTenant: {
			type: String,
			default: null,
		},
	},

	data() {
		return {
			/**
			 * Header-action handlers resolved by name. CnIndexPage looks a
			 * `headerActions[].handler` name up in `customComponents` only, not
			 * in `registry`, so the Integrations page's Add integration handler
			 * has to travel through that prop.
			 */
			headerActionHandlers: createConnectionHandlers({
				generateUrl,
				assign: (url) => window.location.assign(url),
			}),

			/**
			 * List column formatters learniq adds to the library's built-ins,
			 * by the id a manifest column names in `formatter`.
			 */
			formatters: createFormatters(getCanonicalLocale),
		}
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
			return ncT('learniq', key)
		},
	},
}
</script>
