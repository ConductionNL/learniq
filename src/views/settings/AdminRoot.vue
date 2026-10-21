<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<CnAdminSettingsShell
		appId="learniq"
		appName="Learniq"
		:tabs="tabs">
		<template v-for="tab in tabs" :key="tab.id" #[`tab-${tab.id}`]>
			<template v-for="section in sectionsOf(tab.id)" :key="section.id">
				<CnSettingsFieldsSection
					v-if="section.type === 'fields'"
					:id="section.id"
					appId="learniq"
					v-bind="translatedProps(section.props)" />
				<CnSettingsSection
					v-else-if="section.type === 'shipped' || section.type === 'record-list'"
					:id="section.id"
					:name="t('learniq', section.name)"
					:description="t('learniq', section.description || '')">
					<CnShippedConfigurationSection
						v-if="section.type === 'shipped'"
						v-bind="section.props" />
					<template v-else>
						<CnRecordListWidget :content="section.content" />
						<NcButton
							v-if="section.content.headerLink"
							variant="secondary"
							:href="linkTo(section.content.headerLink.href)">
							{{ t('learniq', section.content.headerLink.label) }}
						</NcButton>
					</template>
				</CnSettingsSection>
				<CnActionAuthMatrix
					v-else-if="section.type === 'action-matrix'"
					:id="section.id"
					v-bind="section.props" />
				<AiTranslationReviewSection
					v-else-if="section.type === 'ai-translation-review'"
					:id="section.id" />
			</template>
		</template>
	</CnAdminSettingsShell>
</template>

<script>
import {
	CnActionAuthMatrix,
	CnAdminSettingsShell,
	CnRecordListWidget,
	CnSettingsFieldsSection,
	CnSettingsSection,
	CnShippedConfigurationSection,
} from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import AiTranslationReviewSection from './AiTranslationReviewSection.vue'
import { ADMIN_SECTIONS, ADMIN_TABS } from '../../adminSettings.js'

/**
 * The learniq admin page: CnAdminSettingsShell with the tabs and sections
 * declared in src/adminSettings.js. The shell reads the version and the real
 * up-to-date state from the initial state AppHost's admin settings provide.
 *
 * @spec openspec/changes/archive/retrofit-2026-05-25-app-shell-settings/tasks.md#task-4
 */
export default {
	name: 'AdminRoot',

	components: {
		AiTranslationReviewSection,
		CnActionAuthMatrix,
		CnAdminSettingsShell,
		CnRecordListWidget,
		CnSettingsFieldsSection,
		CnSettingsSection,
		CnShippedConfigurationSection,
		NcButton,
	},

	computed: {
		/** @return {Array<{id: string, label: string}>} The tabs, translated. */
		tabs() {
			return ADMIN_TABS.map((tab) => ({ ...tab, label: this.t('learniq', tab.label) }))
		},
	},

	methods: {
		/**
		 * The sections of one tab.
		 *
		 * @param {string} tabId The tab.
		 * @return {Array<object>}
		 */
		sectionsOf(tabId) {
			return ADMIN_SECTIONS[tabId] || []
		},

		/**
		 * A section's props with its title and line translated; the fields
		 * are translated by the section itself.
		 *
		 * @param {object} props The declared props.
		 * @return {object}
		 */
		translatedProps(props) {
			return {
				...props,
				name: this.t('learniq', props.name),
				description: props.description ? this.t('learniq', props.description) : '',
			}
		},

		/**
		 * An app path as a link.
		 *
		 * @param {string} path The app path.
		 * @return {string}
		 */
		linkTo(path) {
			return generateUrl(path)
		},
	},
}
</script>
