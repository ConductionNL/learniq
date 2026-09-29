<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 HourPlanEditor (route /hour-plans/:id, timetabling-multi-year-hour-plan).

 The hours a group gets per course, year of the programme and period, as a
 grid: the programme's courses as rows, years and periods as columns, contact
 hours in the cells. The footer shows each year's total against its norm and
 marks a year that falls short. Activate, archive and "Copy to next intake"
 run on the plan; HourPlanActivationGuard refuses a second active plan for the
 same programme and intake year. The generic form cannot render a matrix, so
 this is a custom page.

 @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
-->
<template>
	<div class="hour-plan-editor">
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="loadError" type="error">
			{{ loadError }}
		</NcNoteCard>
		<template v-else>
			<header class="hour-plan-editor__header">
				<div>
					<h2 class="hour-plan-editor__title">
						{{ plan.name || t('learniq', 'Hour plan') }}
					</h2>
					<p class="hour-plan-editor__meta">
						{{
							t('learniq', '{programme}, intake {year}, {status}', {
								programme: programme.name || '',
								year: plan.intakeYear || '',
								status: statusLabel,
							})
						}}
					</p>
				</div>
				<div class="hour-plan-editor__actions">
					<NcButton
						v-if="plan.lifecycle === 'draft'"
						:disabled="busy"
						@click="transition('activate')">
						{{ t('learniq', 'Activate') }}
					</NcButton>
					<NcButton
						v-if="plan.lifecycle === 'active'"
						:disabled="busy"
						@click="transition('archive')">
						{{ t('learniq', 'Archive') }}
					</NcButton>
					<NcButton :disabled="busy" @click="copyToNextIntake">
						{{ t('learniq', 'Copy to next intake') }}
					</NcButton>
					<NcButton
						variant="primary"
						:disabled="busy || !dirty"
						@click="save">
						{{ t('learniq', 'Save') }}
					</NcButton>
				</div>
			</header>

			<NcNoteCard v-if="message" :type="messageType">
				{{ message }}
			</NcNoteCard>

			<NcEmptyContent
				v-if="courses.length === 0"
				:name="t('learniq', 'This programme has no courses yet')"
				:description="
					t(
						'learniq',
						'Add courses to the programme first. Each course becomes a row of the plan.',
					)
				" />

			<div v-else class="hour-plan-editor__scroll">
				<table class="hour-plan-editor__grid">
					<caption class="hidden-visually">
						{{
							t('learniq', 'Contact hours per course, year and period')
						}}
					</caption>
					<thead>
						<tr>
							<th scope="col">
								{{ t('learniq', 'Course') }}
							</th>
							<th
								v-for="col in columns"
								:key="colKey(col)"
								scope="col">
								{{ columnLabel(col) }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="course in courses" :key="course.id">
							<th scope="row">
								{{ course.name }}
							</th>
							<td v-for="col in columns" :key="colKey(col)">
								<input
									class="hour-plan-editor__cell"
									type="number"
									min="0"
									step="1"
									:aria-label="cellLabel(course, col)"
									:value="hoursOf(course.id, col) || ''"
									:disabled="plan.lifecycle === 'archived'"
									@change="
										setHours(course.id, col, $event.target.value)
									" />
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<section class="hour-plan-editor__totals">
				<h3>{{ t('learniq', 'Totals per year') }}</h3>
				<table class="hour-plan-editor__totals-table">
					<thead>
						<tr>
							<th scope="col">
								{{ t('learniq', 'Year of the programme') }}
							</th>
							<th scope="col">
								{{ t('learniq', 'Contact hours') }}
							</th>
							<th scope="col">
								{{ t('learniq', 'Other hours') }}
							</th>
							<th scope="col">
								{{ t('learniq', 'Contact hours norm') }}
							</th>
							<th scope="col">
								{{ t('learniq', 'Status') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in totals" :key="row.programmeYear">
							<th scope="row">
								{{
									t('learniq', 'Year {n}', {
										n: row.programmeYear,
									})
								}}
							</th>
							<td>{{ row.contactHours }}</td>
							<td>{{ row.otherHours }}</td>
							<td>
								<input
									class="hour-plan-editor__cell"
									type="number"
									min="0"
									:aria-label="
										t(
											'learniq',
											'Contact hours norm for year {n}',
											{
												n: row.programmeYear,
											},
										)
									"
									:value="row.norm ?? ''"
									:disabled="plan.lifecycle === 'archived'"
									@change="
										setNorm(
											row.programmeYear,
											$event.target.value,
										)
									" />
							</td>
							<td>
								<span
									v-if="row.shortBy > 0"
									class="hour-plan-editor__short">
									{{
										t(
											'learniq',
											'{hours} hours short of the norm',
											{
												hours: row.shortBy,
											},
										)
									}}
								</span>
								<span v-else-if="row.norm !== null">
									{{ t('learniq', 'Meets the norm') }}
								</span>
							</td>
						</tr>
					</tbody>
				</table>
			</section>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import {
	objectId,
	objectsUrl,
	oneObject,
	transitionUrl,
} from '../utils/customPages.js'
import {
	cellHours,
	copyForNextIntake,
	planColumns,
	setCell,
	yearTotals,
} from '../utils/hourPlan.js'

export default {
	name: 'HourPlanEditor',

	components: { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard },

	props: {
		/** Hour plan UUID from the route. */
		id: { type: String, required: true },
	},

	data() {
		return {
			loading: true,
			loadError: '',
			busy: false,
			dirty: false,
			message: '',
			messageType: 'success',
			plan: {},
			programme: {},
			courses: [],
		}
	},

	computed: {
		/**
		 * The grid columns.
		 *
		 * @return {Array<object>}
		 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
		 */
		columns() {
			return planColumns(this.plan)
		},

		/**
		 * Totals per year against the norm.
		 *
		 * @return {Array<object>}
		 * @spec openspec/specs/school-structure/spec.md#scenario-a-year-below-its-norm-is-marked
		 */
		totals() {
			return yearTotals(this.plan)
		},

		/**
		 * The plan's status in words.
		 *
		 * @return {string}
		 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
		 */
		statusLabel() {
			return (
				{
					draft: t('learniq', 'Draft'),
					active: t('learniq', 'Active'),
					archived: t('learniq', 'Archived'),
				}[this.plan.lifecycle] || ''
			)
		},
	},

	/**
	 * Load the plan, its programme and the programme's courses.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
	 */
	async mounted() {
		await this.load()
	},

	methods: {
		t,

		/**
		 * Load the plan, its programme and the programme's courses.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
		 */
		async load() {
			this.loading = true
			this.loadError = ''
			try {
				const planRes = await axios.get(
					generateUrl(objectsUrl('hour-plan', this.id)),
				)
				this.plan = { lines: [], yearNorms: [], ...oneObject(planRes.data) }
				const progRes = await axios.get(
					generateUrl(objectsUrl('programme', this.plan.programmeId)),
				)
				this.programme = oneObject(progRes.data)
				const courseIds = this.programme.courseIds || []
				const lineIds = (this.plan.lines || []).map((l) => l.courseId)
				const ids = [...new Set([...courseIds, ...lineIds])]
				// One read per course: a programme has a handful, and a course
				// that cannot be read keeps its row under its id.
				this.courses = await Promise.all(
					ids.map(async (cid) => {
						try {
							const res = await axios.get(
								generateUrl(objectsUrl('course', cid)),
							)
							return { id: cid, name: oneObject(res.data).name || cid }
						} catch {
							return { id: cid, name: cid }
						}
					}),
				)
				this.dirty = false
			} catch {
				this.loadError = t('learniq', 'This hour plan could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * A stable key for a column.
		 *
		 * @param {object} col The column.
		 * @return {string}
		 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
		 */
		colKey(col) {
			return `${col.programmeYear}-${col.periodCode ?? 'year'}`
		},

		/**
		 * A column heading: the year, and the period when the plan has periods.
		 *
		 * @param {object} col The column.
		 * @return {string}
		 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
		 */
		columnLabel(col) {
			const year = t('learniq', 'Year {n}', { n: col.programmeYear })
			return col.label ? `${year}, ${col.label}` : year
		},

		/**
		 * The accessible name of one cell.
		 *
		 * @param {object} course The course row.
		 * @param {object} col The column.
		 * @return {string}
		 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
		 */
		cellLabel(course, col) {
			return t('learniq', 'Contact hours for {course}, {column}', {
				course: course.name,
				column: this.columnLabel(col),
			})
		},

		/**
		 * The contact hours of one cell.
		 *
		 * @param {string} courseId The course.
		 * @param {object} col The column.
		 * @return {number}
		 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
		 */
		hoursOf(courseId, col) {
			return cellHours(
				this.plan.lines,
				courseId,
				col.programmeYear,
				col.periodCode,
			)
		},

		/**
		 * Set the contact hours of one cell.
		 *
		 * @param {string} courseId The course.
		 * @param {object} col The column.
		 * @param {string} value The entered hours.
		 * @return {void}
		 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
		 */
		setHours(courseId, col, value) {
			this.plan = {
				...this.plan,
				lines: setCell(
					this.plan.lines,
					courseId,
					col.programmeYear,
					col.periodCode,
					value,
				),
			}
			this.dirty = true
		},

		/**
		 * Set a year's contact hours norm.
		 *
		 * @param {number} programmeYear The year.
		 * @param {string} value The entered norm, empty for none.
		 * @return {void}
		 * @spec openspec/specs/school-structure/spec.md#scenario-a-year-below-its-norm-is-marked
		 */
		setNorm(programmeYear, value) {
			const norms = (this.plan.yearNorms || []).filter(
				(n) => Number(n.programmeYear) !== programmeYear,
			)
			if (value !== '') {
				norms.push({
					programmeYear,
					contactHours: Math.max(0, Number(value) || 0),
				})
			}
			norms.sort((a, b) => a.programmeYear - b.programmeYear)
			this.plan = { ...this.plan, yearNorms: norms }
			this.dirty = true
		},

		/**
		 * Save the lines and norms.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
		 */
		async save() {
			await this.run(async () => {
				await axios.put(generateUrl(objectsUrl('hour-plan', this.id)), {
					...this.plan,
					lines: this.plan.lines,
					yearNorms: this.plan.yearNorms,
				})
				this.dirty = false
				this.say(t('learniq', 'The hour plan is saved.'))
			})
		},

		/**
		 * Run a lifecycle transition, saving first.
		 *
		 * @param {string} action `activate` or `archive`.
		 * @return {Promise<void>}
		 * @spec openspec/specs/school-structure/spec.md#scenario-a-second-active-plan-for-the-same-intake-is-refused
		 */
		async transition(action) {
			if (this.dirty) {
				await this.save()
			}
			await this.run(async () => {
				await axios.post(generateUrl(transitionUrl(this.id)), { action })
				await this.load()
				this.say(
					action === 'activate'
						? t('learniq', 'The hour plan is active.')
						: t('learniq', 'The hour plan is archived.'),
				)
			})
		},

		/**
		 * Create a draft copy for the next intake year and open it.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
		 */
		async copyToNextIntake() {
			await this.run(async () => {
				const res = await axios.post(
					generateUrl(objectsUrl('hour-plan')),
					copyForNextIntake(this.plan),
				)
				const newId = objectId(oneObject(res.data))
				if (newId && this.$router) {
					await this.$router
						.push({ name: 'HourPlanEditor', params: { id: newId } })
						.catch(() => {})
				}
			})
		},

		/**
		 * Run a write and show the server's refusal when it fails.
		 *
		 * @param {() => Promise<void>} work The write.
		 * @return {Promise<void>}
		 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
		 */
		async run(work) {
			this.busy = true
			this.message = ''
			try {
				await work()
			} catch (e) {
				const reason = e?.response?.data?.message || e?.response?.data?.error
				this.say(
					typeof reason === 'string' && reason
						? reason
						: t('learniq', 'The change could not be saved.'),
					'error',
				)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Show a message above the grid.
		 *
		 * @param {string} text The message.
		 * @param {string} [type] `success` or `error`.
		 * @return {void}
		 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
		 */
		say(text, type = 'success') {
			this.message = text
			this.messageType = type
		},
	},
}
</script>

<style scoped>
.hour-plan-editor {
	padding: 16px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.hour-plan-editor__header {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	gap: 12px;
}

.hour-plan-editor__title {
	margin: 0;
}

.hour-plan-editor__meta {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
}

.hour-plan-editor__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.hour-plan-editor__scroll {
	overflow-x: auto;
}

.hour-plan-editor__grid,
.hour-plan-editor__totals-table {
	border-collapse: collapse;
}

.hour-plan-editor__grid th,
.hour-plan-editor__grid td,
.hour-plan-editor__totals-table th,
.hour-plan-editor__totals-table td {
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: start;
}

.hour-plan-editor__cell {
	width: 80px;
}

.hour-plan-editor__short {
	color: var(--color-text-error, var(--color-error));
	font-weight: 600;
}
</style>
