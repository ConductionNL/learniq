<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 ContactHoursReport: owed, given and attended contact hours for a window
 (timetabling-contact-hours). Per group a table of courses with owed, given
 and the difference, shortfalls marked; per learner the hours attended
 against the hours given, below the margin marked. CSV export of both.
 @spec openspec/specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours
-->
<template>
	<div class="contact-hours">
		<h2>{{ t('learniq', 'Contact hours') }}</h2>
		<form class="contact-hours__filters" @submit.prevent="load">
			<label>
				{{ t('learniq', 'From') }}
				<input v-model="from" type="date" required />
			</label>
			<label>
				{{ t('learniq', 'To') }}
				<input v-model="to" type="date" required />
			</label>
			<label>
				{{ t('learniq', 'Group (optional)') }}
				<input
					v-model="cohortId"
					type="text"
					:placeholder="t('learniq', 'All groups')" />
			</label>
			<NcButton type="submit" variant="primary" :disabled="loading">
				{{ t('learniq', 'Show') }}
			</NcButton>
			<NcButton v-if="report" :disabled="loading" @click="exportCsv">
				{{ t('learniq', 'Export CSV') }}
			</NcButton>
		</form>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<p v-if="loading">
			{{ t('learniq', 'Loading…') }}
		</p>
		<section
			v-for="cohort in report?.cohorts || []"
			:key="cohort.cohortId"
			class="contact-hours__cohort"
			data-testid="contact-hours-cohort">
			<h3>{{ cohort.cohortName }}</h3>
			<p v-if="!cohort.hasHourPlan" class="contact-hours__note">
				{{
					t(
						'learniq',
						'This group has no active hour plan, so owed hours are missing.',
					)
				}}
			</p>
			<table class="contact-hours__table">
				<thead>
					<tr>
						<th scope="col">
							{{ t('learniq', 'Course') }}
						</th>
						<th scope="col">
							{{ t('learniq', 'Owed') }}
						</th>
						<th scope="col">
							{{ t('learniq', 'Given') }}
						</th>
						<th scope="col">
							{{ t('learniq', 'Difference') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="course in cohort.courses"
						:key="course.courseId"
						:class="{ 'contact-hours__short': course.short }">
						<td>{{ course.courseName }}</td>
						<td>{{ hours(course.owed) }}</td>
						<td>{{ hours(course.given) }}</td>
						<td>
							{{ hours(course.difference) }}
							<strong v-if="course.short">{{
								t('learniq', 'short')
							}}</strong>
						</td>
					</tr>
					<tr class="contact-hours__total">
						<th scope="row">
							{{ t('learniq', 'Total') }}
						</th>
						<td>{{ hours(cohort.totals.owed) }}</td>
						<td>{{ hours(cohort.totals.given) }}</td>
						<td />
					</tr>
				</tbody>
			</table>
			<NcButton @click="toggle(cohort.cohortId)">
				{{
					open[cohort.cohortId]
						? t('learniq', 'Hide learners')
						: t('learniq', 'Show learners')
				}}
			</NcButton>
			<table
				v-if="open[cohort.cohortId]"
				class="contact-hours__table"
				data-testid="contact-hours-learners">
				<thead>
					<tr>
						<th scope="col">
							{{ t('learniq', 'Learner') }}
						</th>
						<th scope="col">
							{{ t('learniq', 'Course') }}
						</th>
						<th scope="col">
							{{ t('learniq', 'Attended of given') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<template
						v-for="learner in cohort.learners"
						:key="learner.learnerId">
						<tr
							v-for="course in learner.courses"
							:key="learner.learnerId + course.courseId"
							:class="{ 'contact-hours__short': course.below }">
							<td>{{ learner.learnerId }}</td>
							<td>{{ courseName(cohort, course.courseId) }}</td>
							<td>
								{{
									t('learniq', '{attended} of {given} hours', {
										attended: hours(course.attended),
										given: hours(course.given),
									})
								}}
								<strong v-if="course.below">{{
									t('learniq', 'below the margin')
								}}</strong>
							</td>
						</tr>
					</template>
				</tbody>
			</table>
		</section>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'ContactHoursReport',

	components: {
		NcButton,
		NcNoteCard,
	},

	data() {
		const year = new Date().getFullYear()
		const start = new Date().getMonth() >= 7 ? year : year - 1
		return {
			from: `${start}-08-01`,
			to: new Date().toISOString().slice(0, 10),
			cohortId: '',
			report: null,
			open: {},
			loading: false,
			error: '',
		}
	},

	methods: {
		t,

		/**
		 * Load the report for the chosen window.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const params = { from: this.from, to: this.to }
				if (this.cohortId.trim()) params.cohortId = this.cohortId.trim()
				const { data } = await axios.get(
					generateUrl('/apps/learniq/api/reports/contact-hours'),
					{ params },
				)
				this.report = data
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| t('learniq', 'The report could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Show or hide a group's learners.
		 *
		 * @param {string} cohortId The group.
		 * @return {void}
		 * @spec openspec/specs/attendance/spec.md#requirement-a-learner-who-attended-too-little-is-marked
		 */
		toggle(cohortId) {
			this.open = { ...this.open, [cohortId]: !this.open[cohortId] }
		},

		/**
		 * A course's name within a group's report.
		 *
		 * @param {object} cohort The group's report.
		 * @param {string} courseId The course.
		 * @return {string}
		 * @spec openspec/specs/attendance/spec.md#requirement-a-learner-who-attended-too-little-is-marked
		 */
		courseName(cohort, courseId) {
			return (
				cohort.courses.find((course) => course.courseId === courseId)
					?.courseName || courseId
			)
		},

		/**
		 * Hours with one decimal, or a dash when missing.
		 *
		 * @param {number|null} value Hours.
		 * @return {string}
		 * @spec openspec/specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours
		 */
		hours(value) {
			if (value === null || value === undefined) return '–'
			return Number(value).toLocaleString(undefined, {
				maximumFractionDigits: 1,
			})
		},

		/**
		 * Download both tables as CSV.
		 *
		 * @return {void}
		 * @spec openspec/specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours
		 */
		exportCsv() {
			const rows = [
				[
					'group',
					'course',
					'owed',
					'given',
					'difference',
					'learner',
					'attended',
				],
			]
			for (const cohort of this.report?.cohorts || []) {
				for (const course of cohort.courses) {
					rows.push([
						cohort.cohortName,
						course.courseName,
						course.owed ?? '',
						course.given,
						course.difference ?? '',
						'',
						'',
					])
				}
				for (const learner of cohort.learners) {
					for (const course of learner.courses) {
						rows.push([
							cohort.cohortName,
							this.courseName(cohort, course.courseId),
							'',
							course.given,
							'',
							learner.learnerId,
							course.attended,
						])
					}
				}
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
			link.download = `contact-hours-${this.from}-${this.to}.csv`
			link.click()
			URL.revokeObjectURL(link.href)
		},
	},
}
</script>

<style scoped>
.contact-hours {
	padding: 16px;
	max-width: 1100px;
}

.contact-hours__filters {
	display: flex;
	flex-wrap: wrap;
	align-items: end;
	gap: 12px;
	margin-bottom: 16px;
}

.contact-hours__filters label {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.contact-hours__cohort {
	margin-bottom: 24px;
}

.contact-hours__table {
	width: 100%;
	border-collapse: collapse;
	margin-block: 8px;
}

.contact-hours__table th,
.contact-hours__table td {
	padding: 4px 8px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.contact-hours__short {
	background: var(--color-error-hover);
}

.contact-hours__note {
	color: var(--color-text-maxcontrast);
}
</style>
