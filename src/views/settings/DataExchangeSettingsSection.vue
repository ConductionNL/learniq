<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 DataExchangeSettingsSection — admin-settings entry point for data exchange.

 Since data-exchange-to-integriq (decision D7) integriq carries learniq's
 exchange jobs; learniq keeps the gate and a read-only status panel. This
 section, rendered on the Nextcloud Admin Settings page (AdminRoot.vue), links
 into that panel and the gate's own pages. The Admin Settings mount has no
 in-app vue-router, so links use a full navigation to the app's hash routes
 (mirrors LearniqSettings.vue's "Manage AI features" affordance).

 @spec openspec/changes/relocate-dataexchange-remove-assistant/specs/data-exchange/spec.md#requirement-data-exchange-management-is-reached-from-the-admin-settings-page
-->
<template>
	<!-- The id is the anchor lib/Settings/connections.json links to (adopt-connection-registry). -->
	<NcSettingsSection
		id="section-data-exchange"
		:name="t('learniq', 'Data exchange')"
		:description="
			t(
				'learniq',
				'Integriq sends learniq\'s reports to DUO, OSO transfer files and SWV hand-offs once learniq\'s checks allow them. Follow the jobs and the parent reviews here.',
			)
		">
		<div class="learniq-dataexchange-settings__actions">
			<NcButton variant="secondary" @click="open('/data-exchange/jobs')">
				<template #icon>
					<SwapHorizontal :size="20" />
				</template>
				{{ t('learniq', 'Exchange jobs') }}
			</NcButton>
			<NcButton
				variant="secondary"
				@click="open('/data-exchange/parent-reviews')">
				<template #icon>
					<FileAccountOutline :size="20" />
				</template>
				{{ t('learniq', 'Parent reviews') }}
			</NcButton>
		</div>
	</NcSettingsSection>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcSettingsSection } from '@nextcloud/vue'
import FileAccountOutline from 'vue-material-design-icons/FileAccountOutline.vue'
import SwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue'

export default {
	name: 'DataExchangeSettingsSection',

	components: {
		NcButton,
		NcSettingsSection,
		SwapHorizontal,
		FileAccountOutline,
	},

	methods: {
		/**
		 * Open a data-exchange SPA page. The Admin Settings mount has no in-app
		 * router, so navigate the browser to the app's history-mode route path
		 * (mirrors LearniqSettings.vue's "Manage AI features" affordance).
		 *
		 * @param {string} routePath The app route path, e.g. `/data-exchange/jobs`.
		 * @return {void}
		 * @spec openspec/changes/relocate-dataexchange-remove-assistant/specs/data-exchange/spec.md#requirement-data-exchange-management-is-reached-from-the-admin-settings-page
		 */
		open(routePath) {
			window.location.href = generateUrl('/apps/learniq') + routePath
		},
	},
}
</script>

<style scoped>
.learniq-dataexchange-settings__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
</style>
