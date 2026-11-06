<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 EnrolmentForecastView: next year's learners per programme year and subject
 (timetabling-enrolment-forecast). Pick or copy a scenario, adjust its rates
 and intake, compute, and read how many learners and groups each programme
 year and subject needs. Every figure is a forecast, labelled with its
 scenario and the time it was computed; estimated subject figures are marked.
 @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
-->
<template>
	<div class="forecast lq-board-page">
		<CnPageHeader :title="t('learniq', 'Enrolment forecast')" />
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<div class="forecast__bar">
			<NcSelect
				v-model="selectedId"
				:inputLabel="t('learniq', 'Scenario')"
				:options="scenarioOptions"
				:reduce="(opt) => opt.value"
				:clearable="false"
				@update:modelValue="pick" />
			<NcButton :disabled="!scenario || busy" @click="copy">
				{{ t('learniq', 'Copy scenario') }}
			</NcButton>
		</div>
		<template v-if="scenario">
			<CnDetailCard :title="t('learniq', 'Rates per programme year')">
				<CnDataMatrix
					data-testid="forecast-rates"
					:rows="rateMatrixRows"
					:columns="rateColumns"
					:rowHeader="t('learniq', 'Programme')"
					@cellEdit="(edit) => onCellEdit(scenario.rates, edit)" />
			</CnDetailCard>
			<CnDetailCard :title="t('learniq', 'Expected intake in year 1')">
				<CnDataMatrix
					data-testid="forecast-intake"
					:rows="intakeMatrixRows"
					:columns="intakeColumns"
					:rowHeader="t('learniq', 'Programme')"
					@cellEdit="(edit) => onCellEdit(scenario.intake, edit)" />
			</CnDetailCard>
			<div class="forecast__bar">
				<NcButton :disabled="busy" @click="save">
					{{ t('learniq', 'Save scenario') }}
				</NcButton>
				<NcButton variant="primary" :disabled="busy" @click="compute">
					{{ t('learniq', 'Compute forecast') }}
				</NcButton>
				<NcButton v-if="result" @click="exportCsv">
					{{ t('learniq', 'Export CSV') }}
				</NcButton>
			</div>
		</template>
		<CnDetailCard v-if="result" :title="t('learniq', 'Forecast')">
			<p class="forecast__label">
				{{
					t(
						'learniq',
						'Forecast "{scenario}" for {year}, computed {time}.',
						{
							scenario: result.scenario,
							year: result.targetYear,
							time: formatTime(result.computedAt),
						},
					)
				}}
			</p>
			<CnDataMatrix
				data-testid="forecast-years"
				:rows="yearMatrixRows"
				:columns="yearColumns"
				:rowHeader="t('learniq', 'Programme')"
				:emptyLabel="t('learniq', 'No programme years in this forecast.')"
				:columnTotalsLabel="t('learniq', 'Total')"
				readOnly
				showColumnTotals />
			<CnDataMatrix
				v-if="result.subjects.length > 0"
				data-testid="forecast-subjects"
				:rows="subjectMatrixRows"
				:columns="subjectColumns"
				:rowHeader="t('learniq', 'Programme')"
				:columnTotalsLabel="t('learniq', 'Total')"
				readOnly
				showColumnTotals />
		</CnDetailCard>
	</div>
</template>

<script>
import { CnDataMatrix, CnDetailCard, CnPageHeader } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { applyCellEdit, editableColumn, intakeRows, matrixColumns, rateRows, subjectRows, yearRows } from '../utils/enrolmentForecastMatrix.js'

const OBJECTS = '/apps/openregister/api/objects/learniq/'

export default {
	name: 'EnrolmentForecastView',

	components: {
		CnDataMatrix,
		CnDetailCard,
		CnPageHeader,
		NcButton,
		NcNoteCard,
		NcSelect,
	},

	data() {
		return {
			scenarios: [],
			programmes: {},
			selectedId: null,
			scenario: null,
			result: null,
			busy: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The rate columns: the year read only, the three rates editable.
		 *
		 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
		 * @return {Array<object>} CnDataMatrix columns.
		 */
		rateColumns() {
			return [
				...matrixColumns([['programmeYear', t('learniq', 'Year'), 'string']]),
				editableColumn('upRate', t('learniq', 'Moves up')),
				editableColumn('repeatRate', t('learniq', 'Repeats')),
				editableColumn('leaveRate', t('learniq', 'Leaves')),
			]
		},

		/**
		 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
		 * @return {Array<object>} The editable intake column.
		 */
		intakeColumns() {
			return [editableColumn('expected', t('learniq', 'Expected learners'))]
		},

		/**
		 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
		 * @return {Array<object>} The scenario's rates as matrix rows.
		 */
		rateMatrixRows() {
			return rateRows(this.scenario?.rates, this.programmeName)
		},

		/**
		 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
		 * @return {Array<object>} The scenario's intake as matrix rows.
		 */
		intakeMatrixRows() {
			return intakeRows(this.scenario?.intake, this.programmeName)
		},

		/**
		 * The columns of the forecast per programme year.
		 *
		 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
		 * @return {Array<object>} CnDataMatrix columns.
		 */
		yearColumns() {
			return matrixColumns([
				['programmeYear', t('learniq', 'Year'), 'string'],
				['learners', t('learniq', 'Learners'), 'number'],
				['groupsNeeded', t('learniq', 'Groups needed'), 'number'],
			])
		},

		/**
		 * The columns of the forecast per subject.
		 *
		 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
		 * @return {Array<object>} CnDataMatrix columns.
		 */
		subjectColumns() {
			return matrixColumns([
				['programmeYear', t('learniq', 'Year'), 'string'],
				['courseName', t('learniq', 'Subject'), 'string'],
				['learners', t('learniq', 'Learners'), 'number'],
				['estimate', t('learniq', 'Estimate'), 'string'],
				['groupsNeeded', t('learniq', 'Groups needed'), 'number'],
			])
		},

		/**
		 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
		 * @return {Array<object>} The forecast per programme year as matrix rows.
		 */
		yearMatrixRows() {
			return yearRows(this.result?.programmeYears)
		},

		/**
		 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
		 * @return {Array<object>} The forecast per subject as matrix rows.
		 */
		subjectMatrixRows() {
			return subjectRows(this.result?.subjects, t('learniq', 'estimated'))
		},

		/**
		 * The scenarios as select options.
		 *
		 * @return {Array<{value: string, label: string}>}
		 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
		 */
		scenarioOptions() {
			return this.scenarios.map((row) => ({
				value: row.id,
				label: `${row.name} (${row.targetYear})`,
			}))
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Keep a committed matrix cell in the scenario; Save scenario stores it.
		 *
		 * @param {Array<object>} list The scenario's `rates` or `intake`.
		 * @param {object} edit CnDataMatrix's `cell-edit` payload.
		 * @return {void}
		 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
		 */
		onCellEdit(list, edit) {
			applyCellEdit(list, edit)
		},

		t,

		/**
		 * Load the scenarios and programme names.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
		 */
		async load() {
			try {
				const [scenarios, programmes] = await Promise.all([
					axios.get(generateUrl(OBJECTS + 'enrolment-forecast'), {
						params: { _limit: 200 },
					}),
					axios.get(generateUrl(OBJECTS + 'programme'), {
						params: { _limit: 500 },
					}),
				])
				this.scenarios = scenarios.data?.results || []
				this.programmes = Object.fromEntries(
					(programmes.data?.results || []).map((row) => [
						row.id,
						row.name,
					]),
				)
				if (!this.selectedId && this.scenarios.length > 0) {
					this.pick(this.scenarios[0].id)
				}
			} catch (e) {
				this.error = this.reason(e)
			}
		},

		/**
		 * Open a scenario.
		 *
		 * @param {string} id The scenario.
		 * @return {void}
		 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
		 */
		pick(id) {
			const found = this.scenarios.find((row) => row.id === id)
			this.selectedId = id
			this.result = null
			this.scenario = null
			if (!found) return
			const copy = JSON.parse(JSON.stringify(found))
			copy.rates = copy.rates || []
			copy.intake = copy.intake || []
			this.scenario = copy
			this.result = copy.result || null
		},

		/**
		 * Save the rates and intake.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
		 */
		async save() {
			await this.run(async () => {
				await axios.patch(
					generateUrl(OBJECTS + 'enrolment-forecast/' + this.scenario.id),
					{
						rates: this.scenario.rates,
						intake: this.scenario.intake,
					},
				)
			})
		},

		/**
		 * Save, then compute the forecast.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
		 */
		async compute() {
			await this.save()
			await this.run(async () => {
				const { data } = await axios.post(
					generateUrl(
						'/apps/learniq/api/enrolment-forecasts/{id}/compute',
						{ id: this.scenario.id },
					),
				)
				this.result = data
			})
		},

		/**
		 * Copy the scenario as a new draft.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
		 */
		async copy() {
			await this.run(async () => {
				const { data } = await axios.post(
					generateUrl(OBJECTS + 'enrolment-forecast'),
					{
						name: t('learniq', '{name} (copy)', {
							name: this.scenario.name,
						}),
						targetYear: this.scenario.targetYear,
						rates: this.scenario.rates,
						intake: this.scenario.intake,
						targetGroupSize: this.scenario.targetGroupSize,
					},
				)
				await this.load()
				this.pick(data.id)
			})
		},

		/**
		 * Run an action with the busy flag and error handling.
		 *
		 * @param {function(): Promise<void>} action The action.
		 * @return {Promise<void>}
		 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
		 */
		async run(action) {
			this.busy = true
			this.error = ''
			try {
				await action()
			} catch (e) {
				this.error = this.reason(e)
			} finally {
				this.busy = false
			}
		},

		/**
		 * A programme's name.
		 *
		 * @param {string} id The programme.
		 * @return {string}
		 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
		 */
		programmeName(id) {
			return this.programmes[id] || id
		},

		/**
		 * A readable time.
		 *
		 * @param {string} iso A date-time.
		 * @return {string}
		 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
		 */
		formatTime(iso) {
			const date = new Date(iso)
			return Number.isNaN(date.getTime()) ? '' : date.toLocaleString()
		},

		/**
		 * Download the result tables as CSV.
		 *
		 * @return {void}
		 * @spec openspec/specs/student-analytics/spec.md#requirement-the-forecast-says-how-many-groups-are-needed
		 */
		exportCsv() {
			const rows = [
				[
					'scenario',
					'programme',
					'year',
					'subject',
					'learners',
					'estimated',
					'groups',
				],
			]
			for (const row of this.result.programmeYears) {
				rows.push([
					this.result.scenario,
					row.programmeName,
					row.programmeYear,
					'',
					row.learners,
					'',
					row.groupsNeeded,
				])
			}
			for (const row of this.result.subjects) {
				rows.push([
					this.result.scenario,
					row.programmeName,
					row.programmeYear,
					row.courseName,
					row.learners,
					row.estimated,
					row.groupsNeeded,
				])
			}
			const csv = rows
				.map((row) =>
					row
						.map((cell) => `"${String(cell).replaceAll('"', '""')}"`)
						.join(','),
				)
				.join('\n')
			const link = document.createElement('a')
			link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }))
			link.download = `enrolment-forecast-${this.result.targetYear}.csv`
			link.click()
			URL.revokeObjectURL(link.href)
		},

		/**
		 * The server's reason, or a general one.
		 *
		 * @param {Error} e The failure.
		 * @return {string}
		 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
		 */
		reason(e) {
			return (
				e?.response?.data?.error
				|| t('learniq', 'Something went wrong. Try again.')
			)
		},
	},
}
</script>

<style scoped>
.forecast__bar {
	display: flex;
	flex-wrap: wrap;
	align-items: end;
	gap: 12px;
}

.forecast__label {
	color: var(--color-text-maxcontrast);
}
</style>
