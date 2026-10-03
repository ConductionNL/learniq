<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 TimetableExchangeSettingsSection: keep the group code to cohort map per
 rostering system, and the SWV receiver, on the Learniq admin settings page
 instead of with occ (timetable-connection-and-import-screen).

 A timetable import sends the map of the system it reads, unless the request
 carries its own. The SWV receiver names the integriq receiver that takes the
 school's support requests (swv_receiver_id).

 @spec openspec/specs/timetabling/spec.md#requirement-an-administrator-keeps-the-group-code-maps-and-the-swv-receiver-on-the-admin-page
-->
<template>
	<NcSettingsSection
		id="section-timetable-exchange"
		:name="t('learniq', 'Timetable and SWV exchange')"
		:description="
			t(
				'learniq',
				'Say which group in your rostering system is which group in learniq, so an imported timetable lands on the right groups. Name the receiver that takes your support requests for the SWV.',
			)
		">
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<p v-if="loading">
			{{ t('learniq', 'Loading the exchange settings…') }}
		</p>
		<form v-else class="learniq-timetable-exchange" @submit.prevent="save">
			<NcTextField
				v-model="swvReceiverId"
				:label="t('learniq', 'SWV receiver')"
				:helperText="
					t(
						'learniq',
						'The integriq receiver of your support requests, for example swv-kindkans. Leave empty when you send none.',
					)
				"
				data-testid="swv-receiver-id" />

			<fieldset
				v-for="source in sources"
				:key="source"
				class="learniq-timetable-exchange__source">
				<legend>
					{{
						t('learniq', 'Groups from {system}', {
							system: label(source),
						})
					}}
				</legend>
				<p
					v-if="rows[source].length === 0"
					class="learniq-timetable-exchange__empty">
					{{ t('learniq', 'No groups mapped yet.') }}
				</p>
				<div
					v-for="(row, index) in rows[source]"
					:key="source + '-' + index"
					class="learniq-timetable-exchange__row">
					<NcTextField
						v-model="row.code"
						:label="
							t('learniq', 'Group code in {system}', {
								system: label(source),
							})
						" />
					<NcSelect
						:modelValue="cohortOption(row.cohortId)"
						:options="cohortOptions"
						:inputLabel="t('learniq', 'Group in learniq')"
						label="label"
						@update:modelValue="
							(option) => (row.cohortId = option ? option.id : '')
						" />
					<NcButton
						variant="tertiary"
						:aria-label="t('learniq', 'Remove this group')"
						@click="rows[source].splice(index, 1)">
						<template #icon>
							<Delete :size="20" />
						</template>
					</NcButton>
				</div>
				<NcButton
					variant="secondary"
					@click="rows[source].push({ code: '', cohortId: '' })">
					{{ t('learniq', 'Add a group') }}
				</NcButton>
			</fieldset>

			<div class="learniq-timetable-exchange__actions">
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
	NcNoteCard,
	NcSelect,
	NcSettingsSection,
	NcTextField,
} from '@nextcloud/vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import {
	mapsToRows,
	rowsToMaps,
	SETTINGS_URL,
	SOURCE_LABELS,
} from '../../utils/timetableExchangeSettings.js'

export default {
	name: 'TimetableExchangeSettingsSection',

	components: {
		Delete,
		NcButton,
		NcNoteCard,
		NcSelect,
		NcSettingsSection,
		NcTextField,
	},

	data() {
		return {
			sources: [],
			rows: {},
			swvReceiverId: '',
			cohorts: [],
			loading: true,
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * @return {Array<{id: string, label: string}>} The learniq groups to pick from.
		 * @spec openspec/specs/timetabling/spec.md#requirement-an-administrator-keeps-the-group-code-maps-and-the-swv-receiver-on-the-admin-page
		 */
		cohortOptions() {
			return this.cohorts.map((cohort) => ({
				id: cohort.id || cohort.uuid,
				label: cohort.name || cohort.id || cohort.uuid,
			}))
		},
	},

	async mounted() {
		await Promise.all([this.load(), this.loadCohorts()])
	},

	methods: {
		/**
		 * @param {string} source A rostering system id.
		 * @return {string} Its display name.
		 * @spec openspec/specs/timetabling/spec.md#requirement-an-administrator-keeps-the-group-code-maps-and-the-swv-receiver-on-the-admin-page
		 */
		label(source) {
			return SOURCE_LABELS[source] || source
		},

		/**
		 * The select option for a stored cohort id, kept even when the group is
		 * not in the loaded list so a saved row never looks empty.
		 *
		 * @param {string} cohortId The stored id.
		 * @return {object|null} The option.
		 * @spec openspec/specs/timetabling/spec.md#requirement-an-administrator-keeps-the-group-code-maps-and-the-swv-receiver-on-the-admin-page
		 */
		cohortOption(cohortId) {
			if (!cohortId) {
				return null
			}
			return (
				this.cohortOptions.find((option) => option.id === cohortId) || {
					id: cohortId,
					label: cohortId,
				}
			)
		},

		/**
		 * @param {object} data The server's settings.
		 * @return {void}
		 * @spec openspec/specs/timetabling/spec.md#requirement-an-administrator-keeps-the-group-code-maps-and-the-swv-receiver-on-the-admin-page
		 */
		apply(data) {
			this.sources = data.sources || []
			this.rows = mapsToRows(this.sources, data.groupMaps || {})
			this.swvReceiverId = data.swvReceiverId || ''
		},

		/**
		 * @return {Promise<void>}
		 * @spec openspec/specs/timetabling/spec.md#requirement-an-administrator-keeps-the-group-code-maps-and-the-swv-receiver-on-the-admin-page
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(generateUrl(SETTINGS_URL))
				this.apply(data)
			} catch {
				this.error = this.t(
					'learniq',
					'The exchange settings could not be loaded.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * @return {Promise<void>}
		 * @spec openspec/specs/timetabling/spec.md#requirement-an-administrator-keeps-the-group-code-maps-and-the-swv-receiver-on-the-admin-page
		 */
		async loadCohorts() {
			try {
				const { data } = await axios.get(
					generateUrl(
						'/apps/openregister/api/objects/learniq/cohort?_limit=500',
					),
				)
				this.cohorts = (data && (data.results || data.objects)) || []
			} catch {
				// The rows still save by id; only the names are missing.
				this.cohorts = []
			}
		},

		/**
		 * @return {Promise<void>}
		 * @spec openspec/specs/timetabling/spec.md#requirement-an-administrator-keeps-the-group-code-maps-and-the-swv-receiver-on-the-admin-page
		 */
		async save() {
			this.saving = true
			this.error = ''
			try {
				const { data } = await axios.put(generateUrl(SETTINGS_URL), {
					groupMaps: rowsToMaps(this.rows),
					swvReceiverId: this.swvReceiverId.trim(),
				})
				this.apply(data)
				showSuccess(this.t('learniq', 'Exchange settings saved.'))
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('learniq', 'The exchange settings could not be saved.')
				showError(this.error)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.learniq-timetable-exchange {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 720px;
}

.learniq-timetable-exchange__source {
	display: flex;
	flex-direction: column;
	gap: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
}

.learniq-timetable-exchange__row {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: flex-end;
}

.learniq-timetable-exchange__empty {
	color: var(--color-text-maxcontrast);
}
</style>
