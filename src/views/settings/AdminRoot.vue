<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<CnAdminSettingsShell
		appId="learniq"
		appName="Learniq"
		look="board"
		:title="t('learniq', 'Learniq administration')"
		:tabs="tabs"
		:breadcrumbs="breadcrumbs"
		:breadcrumbNote="t('learniq', 'visible to instance administrators')"
		:appVersion="appVersion"
		:isUpToDate="true"
		:versionTitle="t('learniq', 'Version Information')"
		:versionDescription="
			t('learniq', 'Information about the current Learniq installation')
		"
		docUrl="https://learniq.conduction.nl"
		@tabChange="activeTab = $event">
		<MenuStructureSection />
		<LearniqSettings v-if="storesReady" />
		<ActionAuthMatrix />

		<template #tab-example-data>
			<ExampleDataSettingsSection />
		</template>
		<template #tab-data-exchange>
			<DataExchangeSettingsSection />
		</template>
		<template #tab-timetable-exchange>
			<TimetableExchangeSettingsSection />
		</template>
		<template #tab-lti>
			<LtiSettingsSection />
		</template>
		<template #tab-open-material>
			<OpenMaterialSettingsSection />
		</template>
		<template #tab-store>
			<StoreRegistrySettingsSection />
		</template>
		<template #tab-exam-board>
			<ExamBoardSettingsSection />
		</template>
		<template #tab-ai-translations>
			<AiTranslationReviewSection />
		</template>
	</CnAdminSettingsShell>
</template>

<script>
import { CnAdminSettingsShell } from '@conduction/nextcloud-vue'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import ActionAuthMatrix from '../../components/admin/ActionAuthMatrix.vue'
import LearniqSettings from '../LearniqSettings.vue'
import AiTranslationReviewSection from './AiTranslationReviewSection.vue'
import DataExchangeSettingsSection from './DataExchangeSettingsSection.vue'
import ExamBoardSettingsSection from './ExamBoardSettingsSection.vue'
import ExampleDataSettingsSection from './ExampleDataSettingsSection.vue'
import LtiSettingsSection from './LtiSettingsSection.vue'
import MenuStructureSection from './MenuStructureSection.vue'
import OpenMaterialSettingsSection from './OpenMaterialSettingsSection.vue'
import StoreRegistrySettingsSection from './StoreRegistrySettingsSection.vue'
import TimetableExchangeSettingsSection from './TimetableExchangeSettingsSection.vue'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'AdminRoot',
	components: {
		CnAdminSettingsShell,
		LearniqSettings,
		DataExchangeSettingsSection,
		ExamBoardSettingsSection,
		ExampleDataSettingsSection,
		StoreRegistrySettingsSection,
		TimetableExchangeSettingsSection,
		LtiSettingsSection,
		OpenMaterialSettingsSection,
		MenuStructureSection,
		AiTranslationReviewSection,
		ActionAuthMatrix,
	},

	data() {
		return {
			storesReady: false,
			appVersion: loadState('learniq', 'version', 'Unknown'),
			activeTab:
				new URLSearchParams(window.location.search).get('tab') || 'menu',
		}
	},

	computed: {
		/**
		 * Board LqInstellingen's tab strip. Menu holds the version card, the
		 * menu structure, the register and the action rights; every other tab
		 * holds one settings section.
		 *
		 * @return {Array<{id: string, label: string}>}
		 * @spec exclude board tab strip, display only
		 */
		tabs() {
			return [
				{ id: 'menu', label: this.t('learniq', 'Menu') },
				{ id: 'example-data', label: this.t('learniq', 'Example data') },
				{ id: 'data-exchange', label: this.t('learniq', 'Data exchange') },
				{ id: 'timetable-exchange', label: this.t('learniq', 'Timetable exchange') },
				{ id: 'lti', label: this.t('learniq', 'LTI tools') },
				{ id: 'open-material', label: this.t('learniq', 'Open learning material') },
				{ id: 'store', label: this.t('learniq', 'Store') },
				{ id: 'exam-board', label: this.t('learniq', 'Exam board') },
				{ id: 'ai-translations', label: this.t('learniq', 'AI translations') },
			]
		},

		/**
		 * Board LqInstellingen's path under the title: "Beheerinstellingen /
		 * Learniq / <open tab>". The first crumb goes back to the server's
		 * administration settings.
		 *
		 * @return {Array<{label: string, href?: string}>}
		 * @spec exclude board header path, display only
		 */
		breadcrumbs() {
			const open = this.tabs.find((tab) => tab.id === this.activeTab) || this.tabs[0]
			return [
				{
					label: this.t('learniq', 'Administration settings'),
					href: generateUrl('/settings/admin'),
				},
				{ label: 'Learniq' },
				{ label: open.label },
			]
		},
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
