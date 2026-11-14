<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 CertificateSettingsSection: the phone number the public certificate check
 page shows, so someone who checks a certificate knows whom to call
 (board LqVerifieren). Empty hides the line on the page.

 @spec openspec/changes/certificate-verification-and-key-rotation/specs/certification/spec.md#requirement-the-verification-link-opens-a-page-that-says-in-words-what-was-checked
-->
<template>
	<NcSettingsSection
		id="section-certificates"
		:name="t('learniq', 'Certificates')"
		:description="t('learniq', 'Anyone who opens a certificate\'s verification link sees this phone number for questions. Leave it empty to show none.')">
		<p v-if="loading">
			{{ t('learniq', 'Loading the phone number…') }}
		</p>
		<form v-else class="learniq-certificate-settings" @submit.prevent="save">
			<NcTextField
				v-model="phone"
				type="tel"
				:label="t('learniq', 'School phone number')"
				:error="invalid"
				:helperText="invalid ? t('learniq', 'Enter a phone number: digits, spaces, + ( ) - and dots.') : ''"
				data-testid="certificate-school-phone" />
			<NcButton
				class="learniq-certificate-settings__save"
				type="submit"
				variant="primary"
				:disabled="saving || invalid">
				{{ t('learniq', 'Save') }}
			</NcButton>
		</form>
	</NcSettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcSettingsSection, NcTextField } from '@nextcloud/vue'
import { loadSchoolPhone, parseSchoolPhone, saveSchoolPhone } from '../../services/schoolPhoneSetting.js'

const ENDPOINT = { http: axios, url: generateUrl('/apps/learniq/api/settings') }

export default {
	name: 'CertificateSettingsSection',
	components: { NcButton, NcSettingsSection, NcTextField },

	data() {
		return { phone: '', loading: true, saving: false }
	},

	computed: {
		/**
		 * Whether the field holds no phone number the server accepts.
		 *
		 * @return {boolean}
		 */
		invalid() {
			return parseSchoolPhone(this.phone) === null
		},
	},

	/**
	 * Read the stored number.
	 *
	 * @return {Promise<void>}
	 */
	async created() {
		try {
			this.phone = await loadSchoolPhone(ENDPOINT)
		} catch {
			showError(t('learniq', 'The phone number could not be loaded. Reload the page to try again.'))
		} finally {
			this.loading = false
		}
	},

	methods: {
		t,
		/**
		 * Store the number.
		 *
		 * @return {Promise<void>}
		 */
		async save() {
			this.saving = true
			try {
				this.phone = await saveSchoolPhone(ENDPOINT, parseSchoolPhone(this.phone))
				showSuccess(t('learniq', 'Phone number saved'))
			} catch {
				showError(t('learniq', 'The phone number could not be saved. Try again.'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.learniq-certificate-settings {
	max-width: 480px;
}

.learniq-certificate-settings__save {
	margin-top: calc(var(--default-grid-baseline) * 3);
}
</style>
