<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 LtiSettingsSection: name the integriq event subscription the LTI grade pull
 reads, on the admin page instead of with occ
 (content-lti-launch-through-integriq). Integriq creates the subscription; this
 field only stores its id as lti_ags_subscription_id.

 @spec openspec/changes/content-lti-launch-through-integriq/specs/course-management/spec.md#requirement-the-connection-registry-says-whether-lti-works
-->
<template>
	<NcSettingsSection
		id="section-lti"
		:name="t('learniq', 'LTI tools')"
		:description="
			t(
				'learniq',
				'Integriq opens external tools from a lesson and sends their grades back. Create a subscription on grade events in integriq, then enter its id here so learniq picks up the grades.',
			)
		">
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<p v-if="loading">
			{{ t('learniq', 'Loading the LTI settings…') }}
		</p>
		<form v-else class="learniq-lti-settings" @submit.prevent="save">
			<NcTextField
				v-model="subscriptionId"
				:label="t('learniq', 'Grade subscription')"
				:helperText="
					t(
						'learniq',
						'The id of the integriq event subscription on LTI grade events. Leave empty to stop pulling grades.',
					)
				"
				data-testid="lti-ags-subscription-id" />
			<div class="learniq-lti-settings__actions">
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
import { NcButton, NcNoteCard, NcSettingsSection, NcTextField } from '@nextcloud/vue'

const SETTINGS_URL = '/apps/learniq/api/settings'

export default {
	name: 'LtiSettingsSection',

	components: {
		NcButton,
		NcNoteCard,
		NcSettingsSection,
		NcTextField,
	},

	data() {
		return {
			subscriptionId: '',
			loading: true,
			saving: false,
			error: '',
		}
	},

	/**
	 * @return {void}
	 * @spec openspec/changes/content-lti-launch-through-integriq/specs/course-management/spec.md#requirement-the-connection-registry-says-whether-lti-works
	 */
	mounted() {
		this.load()
	},

	methods: {
		/**
		 * @return {Promise<void>}
		 * @spec openspec/changes/content-lti-launch-through-integriq/specs/course-management/spec.md#requirement-the-connection-registry-says-whether-lti-works
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(generateUrl(SETTINGS_URL))
				this.subscriptionId = data?.lti_ags_subscription_id || ''
			} catch {
				this.error = this.t(
					'learniq',
					'The LTI settings could not be loaded.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * @return {Promise<void>}
		 * @spec openspec/changes/content-lti-launch-through-integriq/specs/course-management/spec.md#requirement-the-connection-registry-says-whether-lti-works
		 */
		async save() {
			this.saving = true
			try {
				await axios.put(generateUrl(SETTINGS_URL), {
					lti_ags_subscription_id: this.subscriptionId.trim(),
				})
				showSuccess(this.t('learniq', 'LTI settings saved'))
			} catch {
				showError(this.t('learniq', 'The LTI settings could not be saved.'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.learniq-lti-settings {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	max-width: 600px;
}

.learniq-lti-settings__actions {
	display: flex;
	justify-content: flex-start;
}
</style>
