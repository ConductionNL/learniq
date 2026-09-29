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
	<div class="forecast">
		<h2>{{ t('learniq', 'Enrolment forecast') }}</h2>
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
			<h3>{{ t('learniq', 'Rates per programme year') }}</h3>
			<table class="forecast__table" data-testid="forecast-rates">
				<thead>
					<tr>
						<th scope="col">
							{{ t('learniq', 'Programme') }}
						</th>
						<th scope="col">
							{{ t('learniq', 'Year') }}
						</th>
						<th scope="col">
							{{ t('learniq', 'Moves up') }}
						</th>
						<th scope="col">
							{{ t('learniq', 'Repeats') }}
						</th>
						<th scope="col">
							{{ t('learniq', 'Leaves') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="(rate, index) in scenario.rates" :key="index">
						<td>{{ programmeName(rate.programmeId) }}</td>
						<td>{{ rate.programmeYear }}</td>
						<td
							v-for="field in ['upRate', 'repeatRate', 'leaveRate']"
							:key="field">
							<input
								v-model.number="rate[field]"
								type="number"
								min="0"
								max="1"
								step="0.01"
								:aria-label="t('learniq', 'Rate')" />
						</td>
					</tr>
				</tbody>
			</table>
			<h3>{{ t('learniq', 'Expected intake in year 1') }}</h3>
			<ul class="forecast__intake">
				<li v-for="(row, index) in scenario.intake" :key="index">
					<label>
						{{ programmeName(row.programmeId) }}
						<input v-model.number="row.expected" type="number" min="0" />
					</label>
				</li>
			</ul>
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
		<template v-if="result">
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
			<table class="forecast__table" data-testid="forecast-years">
				<thead>
					<tr>
						<th scope="col">
							{{ t('learniq', 'Programme') }}
						</th>
						<th scope="col">
							{{ t('learniq', 'Year') }}
						</th>
						<th scope="col">
							{{ t('learniq', 'Learners') }}
						</th>
						<th scope="col">
							{{ t('learniq', 'Groups needed') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="row in result.programmeYears"
						:key="row.programmeId + row.programmeYear">
						<td>{{ row.programmeName }}</td>
						<td>{{ row.programmeYear }}</td>
						<td>{{ row.learners }}</td>
						<td>{{ row.groupsNeeded }}</td>
					</tr>
				</tbody>
			</table>
			<table
				v-if="result.subjects.length > 0"
				class="forecast__table"
				data-testid="forecast-subjects">
				<thead>
					<tr>
						<th scope="col">
							{{ t('learniq', 'Programme') }}
						</th>
						<th scope="col">
							{{ t('learniq', 'Year') }}
						</th>
						<th scope="col">
							{{ t('learniq', 'Subject') }}
						</th>
						<th scope="col">
							{{ t('learniq', 'Learners') }}
						</th>
						<th scope="col">
							{{ t('learniq', 'Groups needed') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="row in result.subjects"
						:key="row.programmeId + row.programmeYear + row.courseId">
						<td>{{ row.programmeName }}</td>
						<td>{{ row.programmeYear }}</td>
						<td>{{ row.courseName }}</td>
						<td>
							{{ row.learners }}
							<em v-if="row.isEstimated">{{
								t('learniq', 'estimated')
							}}</em>
						</td>
						<td>{{ row.groupsNeeded }}</td>
					</tr>
				</tbody>
			</table>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard, NcSelect } from '@nextcloud/vue'

const OBJECTS = '/apps/openregister/api/objects/learniq/'

export default {
	name: 'EnrolmentForecastView',

	components: {
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
				await axios.put(
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
.forecast {
	padding: 16px;
	max-width: 1100px;
}

.forecast__bar {
	display: flex;
	flex-wrap: wrap;
	align-items: end;
	gap: 12px;
	margin-block: 12px;
}

.forecast__table {
	width: 100%;
	border-collapse: collapse;
	margin-block: 8px;
}

.forecast__table th,
.forecast__table td {
	padding: 4px 8px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.forecast__table input {
	width: 80px;
}

.forecast__label {
	color: var(--color-text-maxcontrast);
}
</style>
