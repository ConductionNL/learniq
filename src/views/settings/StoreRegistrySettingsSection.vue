<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 StoreRegistrySettingsSection: connect the course store on the Learniq admin
 settings page instead of with occ (store-rights-for-teachers).

 It edits the three app config keys OpenRegister's store plane reads for
 learniq: registry_url, registry_register and registry_token. The token is
 write-only: the server says whether one is set and never sends it back, so
 this field starts empty and only a new value, or "remove the token", changes
 what is stored.

 @spec openspec/specs/course-management/spec.md#requirement-an-administrator-connects-the-course-registry-in-the-admin-settings
-->
<template>
	<NcSettingsSection
		id="section-course-store"
		:name="t('learniq', 'Course store')"
		:description="
			t(
				'learniq',
				'Connect a course registry so teachers can find and install courses other schools share. The registry is another learniq, for example your school board\'s.',
			)
		">
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<p v-if="loading">
			{{ t('learniq', 'Loading the course store connection…') }}
		</p>

		<form v-else class="learniq-store-registry" @submit.prevent="save">
			<NcTextField
				v-model="url"
				:label="t('learniq', 'Registry address')"
				:helperText="
					t(
						'learniq',
						'For example https://store.example.nl. Leave empty to disconnect the store.',
					)
				"
				type="url"
				data-testid="store-registry-url" />

			<NcTextField
				v-model="register"
				:label="t('learniq', 'Register')"
				:helperText="
					t(
						'learniq',
						'Leave empty to use learniq, the usual register name.',
					)
				" />

			<NcPasswordField
				v-model="token"
				:label="t('learniq', 'Token')"
				:helperText="tokenHelp"
				:disabled="clearToken"
				autocomplete="new-password" />

			<NcCheckboxRadioSwitch
				v-if="tokenSet"
				v-model="clearToken"
				type="checkbox">
				{{ t('learniq', 'Remove the stored token') }}
			</NcCheckboxRadioSwitch>

			<div class="learniq-store-registry__actions">
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
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcNoteCard,
	NcPasswordField,
	NcSettingsSection,
	NcTextField,
} from '@nextcloud/vue'
import { storeRegistryUrl } from '../../utils/storeRegistrySettings.js'

export default {
	name: 'StoreRegistrySettingsSection',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcPasswordField,
		NcSettingsSection,
		NcTextField,
	},

	data() {
		return {
			url: '',
			register: '',
			token: '',
			tokenSet: false,
			clearToken: false,
			loading: true,
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The token field's help: whether one is stored, never its value.
		 *
		 * @return {string} The help text.
		 * @spec openspec/specs/course-management/spec.md#requirement-an-administrator-connects-the-course-registry-in-the-admin-settings
		 */
		tokenHelp() {
			return this.tokenSet
				? this.t(
						'learniq',
						'A token is set. Enter a new one to replace it, or leave this empty to keep it.',
					)
				: this.t(
						'learniq',
						'The token of an account in the instructors group on the registry.',
					)
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * Read the stored connection (without the token).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/course-management/spec.md#requirement-an-administrator-connects-the-course-registry-in-the-admin-settings
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(generateUrl(storeRegistryUrl()))
				this.apply(data)
			} catch {
				this.error = this.t(
					'learniq',
					'The course store connection could not be loaded.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Save the connection. The token travels only when a new one is typed.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/course-management/spec.md#requirement-an-administrator-connects-the-course-registry-in-the-admin-settings
		 */
		async save() {
			this.saving = true
			this.error = ''
			const body = {
				url: this.url.trim(),
				register: this.register.trim(),
				clearToken: this.clearToken,
			}
			if (!this.clearToken && this.token.trim() !== '') {
				body.token = this.token.trim()
			}
			try {
				const { data } = await axios.put(
					generateUrl(storeRegistryUrl()),
					body,
				)
				this.apply(data)
				showSuccess(this.t('learniq', 'Course store connection saved.'))
			} catch (e) {
				this.error =
					e?.response?.data?.error
					?? this.t(
						'learniq',
						'The course store connection could not be saved.',
					)
				showError(this.error)
			} finally {
				this.saving = false
			}
		},

		/**
		 * Show what the server stored, and forget any typed token.
		 *
		 * @param {object} data `{url, register, tokenSet}` from the server.
		 * @return {void}
		 * @spec openspec/specs/course-management/spec.md#requirement-an-administrator-connects-the-course-registry-in-the-admin-settings
		 */
		apply(data) {
			this.url = typeof data?.url === 'string' ? data.url : ''
			this.register = typeof data?.register === 'string' ? data.register : ''
			this.tokenSet = data?.tokenSet === true
			this.token = ''
			this.clearToken = false
		},
	},
}
</script>

<style scoped>
.learniq-store-registry {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 520px;
}

.learniq-store-registry__actions {
	display: flex;
	gap: 8px;
}
</style>
