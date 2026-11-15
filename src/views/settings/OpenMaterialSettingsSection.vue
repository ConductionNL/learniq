<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 OpenMaterialSettingsSection: switch open learning material from Edurep on or
 off, and choose the collections that stay out of a search and the collections
 whose records may be added as a link without an open licence.

 @spec openspec/specs/open-learning-material/spec.md#requirement-sources-are-connections-an-administrator-switches
-->
<template>
	<NcSettingsSection
		id="section-open-material"
		:name="t('learniq', 'Open learning material')"
		:description="t('learniq', 'Teachers search Edurep from Kennisnet from the lesson editor and the course materials. Switch it off to hide the search everywhere. Material already added keeps opening at the source.')"
		:docUrl="termsUrl">
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<p v-if="loading">
			{{ t('learniq', 'Loading the open learning material settings…') }}
		</p>
		<form v-else class="learniq-open-material-settings" @submit.prevent="save">
			<NcCheckboxRadioSwitch v-model="enabled" type="switch" data-testid="open-material-enabled">
				{{ t('learniq', 'Teachers can search open learning material') }}
			</NcCheckboxRadioSwitch>
			<NcTextField
				v-model="collectionsOff"
				:label="t('learniq', 'Collections that stay off')"
				:helperText="t('learniq', 'Comma-separated. KlasCement (Flanders) is off by default. A teacher can still switch one on for a search.')"
				data-testid="open-material-collections-off" />
			<NcTextField
				v-model="linkCollections"
				:label="t('learniq', 'Collections allowed as a link without an open licence')"
				:helperText="t('learniq', 'Comma-separated. Schooltv is allowed by default. Learniq never copies or plays this material.')"
				data-testid="open-material-link-collections" />
			<div class="learniq-open-material-settings__actions">
				<NcButton type="submit" variant="primary" :disabled="saving">
					{{ t('learniq', 'Save') }}
				</NcButton>
			</div>
		</form>
	</NcSettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcCheckboxRadioSwitch, NcNoteCard, NcSettingsSection, NcTextField } from '@nextcloud/vue'

const SETTINGS_URL = '/apps/learniq/api/settings'
const PANEL_URL = '/apps/learniq/api/open-material/panel'

export default {
	name: 'OpenMaterialSettingsSection',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcSettingsSection,
		NcTextField,
	},

	data() {
		return {
			enabled: true,
			collectionsOff: '',
			linkCollections: '',
			termsUrl: 'https://www.kennisnet.nl/edurep/',
			loading: true,
			saving: false,
			error: '',
		}
	},

	/**
	 * @return {void}
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-sources-are-connections-an-administrator-switches
	 */
	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the switches as they apply now, defaults included.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-sources-are-connections-an-administrator-switches
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(generateUrl(PANEL_URL))
				this.enabled = data?.enabled !== false
				this.collectionsOff = (data?.collectionsOff || []).join(', ')
				this.linkCollections = (data?.linkCollections || []).join(', ')
			} catch {
				this.error = this.t('learniq', 'The open learning material settings could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * @return {Promise<void>}
		 * @spec openspec/specs/open-learning-material/spec.md#scenario-edurep-is-switched-off
		 */
		async save() {
			this.saving = true
			try {
				await axios.put(generateUrl(SETTINGS_URL), {
					open_material_enabled: this.enabled ? '1' : '0',
					open_material_collections_off: this.collectionsOff.trim(),
					open_material_link_collections: this.linkCollections.trim(),
				})
				showSuccess(this.t('learniq', 'Open learning material settings saved'))
			} catch {
				showError(this.t('learniq', 'The open learning material settings could not be saved.'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.learniq-open-material-settings {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	max-width: 600px;
}

.learniq-open-material-settings__actions {
	display: flex;
	justify-content: flex-start;
}
</style>
