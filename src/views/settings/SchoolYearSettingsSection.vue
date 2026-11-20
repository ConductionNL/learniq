<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 SchoolYearSettingsSection: the day the school year starts (board
 LqInstellingen, school calendar). Stored as MM-DD in school_year_start and
 read by the contact hours calendar and the standby slot validity; 1 August
 when nothing is set.

 @spec openspec/changes/school-year-setting/specs/school-structure/spec.md#requirement-the-school-year-starts-on-the-day-the-school-sets
-->
<template>
	<NcSettingsSection
		id="section-school-calendar"
		:name="t('learniq', 'School calendar')"
		:description="t('learniq', 'The day the school year starts. Contact hours and standby slots count the school year from it.')">
		<p v-if="loading">
			{{ t('learniq', 'Loading the school year…') }}
		</p>
		<form v-else class="learniq-school-year-settings" @submit.prevent="save">
			<NcDateTimePickerNative
				id="school-year-start"
				v-model="picked"
				type="date"
				:label="t('learniq', 'The school year starts on')"
				data-testid="school-year-start" />
			<p class="learniq-school-year-settings__helper">
				{{ helperText }}
			</p>
			<NcButton
				class="learniq-school-year-settings__save"
				type="submit"
				variant="primary"
				:disabled="saving || start === null">
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
import { NcButton, NcDateTimePickerNative, NcSettingsSection } from '@nextcloud/vue'
import {
	DEFAULT_SCHOOL_YEAR_START,
	loadSchoolYearStart,
	saveSchoolYearStart,
	schoolYearOf,
	startInYear,
	startOfDate,
} from '../../services/schoolYearSetting.js'

const ENDPOINT = { http: axios, url: generateUrl('/apps/learniq/api/settings') }

/**
 * The school year start on the admin settings page.
 *
 * @spec openspec/changes/school-year-setting/specs/school-structure/spec.md#requirement-the-school-year-starts-on-the-day-the-school-sets
 */
export default {
	name: 'SchoolYearSettingsSection',
	components: { NcButton, NcDateTimePickerNative, NcSettingsSection },

	data() {
		return {
			picked: startInYear(DEFAULT_SCHOOL_YEAR_START, new Date().getFullYear()),
			loading: true,
			saving: false,
		}
	},

	computed: {
		/**
		 * The picked day as the stored start, `MM-DD`; null for no usable day.
		 *
		 * @return {string|null}
		 * @spec openspec/changes/school-year-setting/specs/school-structure/spec.md#requirement-the-school-year-starts-on-the-day-the-school-sets
		 */
		start() {
			return startOfDate(this.picked)
		},

		/**
		 * The school year today falls in with this start, or what is wrong.
		 *
		 * @return {string}
		 * @spec openspec/changes/school-year-setting/specs/school-structure/spec.md#requirement-the-school-year-starts-on-the-day-the-school-sets
		 */
		helperText() {
			if (this.start === null) {
				return t('learniq', 'Choose a day other than 29 February.')
			}
			const year = schoolYearOf(new Date(), this.start)
			return t('learniq', 'School year {year} runs from {from} to {until}. The year in the picker does not matter.', {
				year: year.label,
				from: year.from.toLocaleDateString(undefined, { dateStyle: 'long' }),
				until: year.until.toLocaleDateString(undefined, { dateStyle: 'long' }),
			})
		},
	},

	/**
	 * Read the stored start.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/school-year-setting/specs/school-structure/spec.md#requirement-the-school-year-starts-on-the-day-the-school-sets
	 */
	async created() {
		try {
			this.picked = startInYear(await loadSchoolYearStart(ENDPOINT), new Date().getFullYear())
		} catch {
			showError(t('learniq', 'The school year could not be loaded. Reload the page to try again.'))
		} finally {
			this.loading = false
		}
	},

	methods: {
		t,
		/**
		 * Store the start.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/school-year-setting/specs/school-structure/spec.md#requirement-the-school-year-starts-on-the-day-the-school-sets
		 */
		async save() {
			if (this.start === null) return
			this.saving = true
			try {
				await saveSchoolYearStart(ENDPOINT, this.start)
				showSuccess(t('learniq', 'School year start saved'))
			} catch {
				showError(t('learniq', 'The school year start could not be saved. Try again.'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.learniq-school-year-settings {
	max-width: 480px;
}

.learniq-school-year-settings__helper {
	color: var(--color-text-maxcontrast);
	margin-top: calc(var(--default-grid-baseline) * 2);
}

.learniq-school-year-settings__save {
	margin-top: calc(var(--default-grid-baseline) * 3);
}
</style>
