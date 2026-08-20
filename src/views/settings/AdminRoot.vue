<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<div class="learniq-admin">
		<CnVersionInfoCard
			appName="Learniq"
			:appVersion="appVersion"
			:isUpToDate="true"
			:showUpdateButton="true"
			:title="t('learniq', 'Version Information')"
			:description="
				t('learniq', 'Information about the current Learniq installation')
			">
			<template #footer>
				<div class="cn-support-info">
					<h4>{{ t('learniq', 'Support') }}</h4>
					<p>
						{{ t('learniq', 'For support, contact us at') }}
						<a href="mailto:support@conduction.nl"
							>support@conduction.nl</a
						>
					</p>
				</div>
			</template>
		</CnVersionInfoCard>

		<LearniqSettings v-if="storesReady" />
		<DataExchangeSettingsSection />
		<ActionAuthMatrix />
	</div>
</template>

<script>
import { CnVersionInfoCard } from '@conduction/nextcloud-vue'
import { loadState } from '@nextcloud/initial-state'
import ActionAuthMatrix from '../../components/admin/ActionAuthMatrix.vue'
import LearniqSettings from '../LearniqSettings.vue'
import DataExchangeSettingsSection from './DataExchangeSettingsSection.vue'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'AdminRoot',
	components: {
		CnVersionInfoCard,
		LearniqSettings,
		DataExchangeSettingsSection,
		ActionAuthMatrix,
	},

	data() {
		return {
			storesReady: false,
			appVersion: loadState('learniq', 'version', 'Unknown'),
		}
	},

	/**
	 * Initialise the Pinia stores at boot before rendering settings.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/retrofit-2026-05-25-app-shell-settings/tasks.md#task-4
	 */
	async created() {
		await initializeStores()
		this.storesReady = true
	},
}
</script>

<style scoped>
.learniq-admin {
	max-width: 900px;
}
</style>
