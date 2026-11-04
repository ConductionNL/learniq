<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ExamBoardSettingsSection: the appeal term of the exam board, in days, as the
 school's examination regulations set it (board LqInstellingen, Examencommissie).
 The term counts from the day of the decision and is stamped on every fraud
 case the board decides from then on.

 @spec openspec/specs/exam-board/spec.md#requirement-a-decided-fraudcase-stamps-the-appeal-deadline-from-the-schools-appeal-term
-->
<template>
	<NcSettingsSection
		id="section-exam-board"
		:name="t('learniq', 'Exam board')"
		:description="description">
		<p v-if="loading">
			{{ t('learniq', 'Loading the appeal term…') }}
		</p>
		<form v-else class="learniq-exam-board-settings" @submit.prevent="save">
			<NcTextField
				v-model="days"
				type="number"
				min="1"
				:max="maxDays"
				:label="t('learniq', 'Appeal term in days')"
				:error="invalid"
				:helperText="helperText"
				data-testid="exam-board-appeal-days" />
			<NcButton
				class="learniq-exam-board-settings__save"
				type="submit"
				variant="primary"
				:disabled="saving || invalid">
				{{ t('learniq', 'Save') }}
			</NcButton>
		</form>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
	</NcSettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard, NcSettingsSection, NcTextField } from '@nextcloud/vue'
import {
	appealUntil,
	DEFAULT_APPEAL_DAYS,
	loadAppealDays,
	MAX_APPEAL_DAYS,
	parseAppealDays,
	saveAppealDays,
} from '../../services/appealTermSetting.js'

const ENDPOINT = { http: axios, url: generateUrl('/apps/learniq/api/settings') }

/**
 * The appeal term field on the admin settings page.
 *
 * @spec openspec/specs/exam-board/spec.md#scenario-the-school-sets-its-own-appeal-term
 */
export default {
	name: 'ExamBoardSettingsSection',
	components: { NcButton, NcNoteCard, NcSettingsSection, NcTextField },

	data() {
		return {
			days: String(DEFAULT_APPEAL_DAYS),
			maxDays: MAX_APPEAL_DAYS,
			loading: true,
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * What the term is, under the section's name.
		 *
		 * @return {string}
		 * @spec openspec/specs/exam-board/spec.md#scenario-the-school-sets-its-own-appeal-term
		 */
		description() {
			return t('learniq', 'How long a learner can appeal a decision of the exam board. The term counts from the day of the decision and is on every fraud case and exemption case.')
		},

		/**
		 * Whether the field holds no term the server accepts.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/exam-board/spec.md#scenario-the-school-sets-its-own-appeal-term
		 */
		invalid() {
			return parseAppealDays(this.days) === null
		},

		/**
		 * The default, and what the term means for a decision taken today.
		 *
		 * @return {string}
		 * @spec openspec/specs/exam-board/spec.md#scenario-the-school-sets-its-own-appeal-term
		 */
		helperText() {
			if (this.invalid) {
				return t('learniq', 'Enter a whole number of days, from 1 to {max}.', { max: MAX_APPEAL_DAYS })
			}
			const today = new Date()
			return t('learniq', 'Default {default} days. A decision of {decided} can then be appealed up to and including {until}.', {
				default: DEFAULT_APPEAL_DAYS,
				decided: today.toLocaleDateString(undefined, { dateStyle: 'long' }),
				until: appealUntil(today, parseAppealDays(this.days)).toLocaleDateString(undefined, { dateStyle: 'long' }),
			})
		},
	},

	/**
	 * Read the stored term.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/exam-board/spec.md#scenario-the-school-sets-its-own-appeal-term
	 */
	async created() {
		try {
			this.days = String(await loadAppealDays(ENDPOINT))
		} catch {
			this.error = t('learniq', 'The appeal term could not be loaded. Reload the page to try again.')
		} finally {
			this.loading = false
		}
	},

	methods: {
		t,
		/**
		 * Store the term.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/exam-board/spec.md#scenario-the-school-sets-its-own-appeal-term
		 */
		async save() {
			this.saving = true
			this.error = ''
			try {
				await saveAppealDays(ENDPOINT, parseAppealDays(this.days))
				showSuccess(t('learniq', 'Appeal term saved'))
			} catch {
				this.error = t('learniq', 'The appeal term could not be saved. Try again.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.learniq-exam-board-settings {
	max-width: 480px;
}

.learniq-exam-board-settings__save {
	margin-top: calc(var(--default-grid-baseline) * 3);
}
</style>
