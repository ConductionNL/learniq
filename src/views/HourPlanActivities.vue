<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 HourPlanActivities (route /teaching-activities, timetabling-multi-year-hour-plan).

 What a school year has to schedule: per group, the hour plan lines of its
 programme year, with the teachers assigned to that group and course. The list
 is derived on every read by GET /api/hour-plans/activities and exported as
 CSV for the timetabling system. Groups whose programme has no active plan for
 their intake year are named, so a missing plan does not read as nothing to
 schedule. Learniq places nothing in a week or a room.

 @spec openspec/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
-->
<template>
	<div class="hour-plan-activities">
		<header class="hour-plan-activities__header">
			<h2 class="hour-plan-activities__title">
				{{ t('learniq', 'Teaching activities') }}
			</h2>
			<p class="hour-plan-activities__intro">
				{{
					t(
						'learniq',
						'What each group has to be scheduled for this school year, taken from the hour plans. Export it for the timetabling system.',
					)
				}}
			</p>
		</header>

		<div class="hour-plan-activities__filters">
			<NcSelect
				v-model="academicYear"
				:options="yearOptions"
				:clearable="false"
				:inputLabel="t('learniq', 'School year')" />
			<NcButton
				:disabled="loading || activities.length === 0"
				@click="exportCsv">
				{{ t('learniq', 'Export as CSV') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<template v-else>
			<NcNoteCard v-if="withoutPlan.length > 0" type="warning">
				{{
					t(
						'learniq',
						'These groups have no active hour plan for their intake year: {groups}',
						{ groups: withoutPlan.map((c) => c.cohortName).join(', ') },
					)
				}}
			</NcNoteCard>

			<NcEmptyContent
				v-if="activities.length === 0"
				:name="t('learniq', 'Nothing to schedule')"
				:description="
					t(
						'learniq',
						'No group of this school year has a programme year and an active hour plan.',
					)
				" />

			<section
				v-for="group in groups"
				:key="group.cohortId"
				class="hour-plan-activities__group">
				<h3>
					{{
						t('learniq', '{group}, year {n} of the programme', {
							group: group.cohortName,
							n: group.programmeYear,
						})
					}}
				</h3>
				<table class="hour-plan-activities__table">
					<thead>
						<tr>
							<th scope="col">
								{{ t('learniq', 'Course') }}
							</th>
							<th scope="col">
								{{ t('learniq', 'Period') }}
							</th>
							<th scope="col">
								{{ t('learniq', 'Contact hours') }}
							</th>
							<th scope="col">
								{{ t('learniq', 'Other hours') }}
							</th>
							<th scope="col">
								{{ t('learniq', 'Kind of activity') }}
							</th>
							<th scope="col">
								{{ t('learniq', 'Teachers') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="(row, index) in group.rows"
							:key="
								row.courseId
								+ '-'
								+ (row.periodCode || '')
								+ '-'
								+ index
							">
							<td>{{ row.courseName || row.courseId }}</td>
							<td>
								{{ row.periodCode || t('learniq', 'Whole year') }}
							</td>
							<td>{{ row.contactHours }}</td>
							<td>{{ row.otherHours }}</td>
							<td>{{ kindLabel(row.activityKind) }}</td>
							<td>{{ (row.teacherIds || []).join(', ') }}</td>
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
import {
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import { activitiesCsv, nextSchoolYear } from '../utils/hourPlan.js'

/**
 * The school year a date falls in, with 1 August as the first day.
 *
 * @param {Date} date The date.
 * @return {string} `YYYY-YYYY`.
 */
function schoolYearOf(date) {
	const start = date.getMonth() >= 7 ? date.getFullYear() : date.getFullYear() - 1
	return `${start}-${start + 1}`
}

export default {
	name: 'HourPlanActivities',

	components: { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect },

	data() {
		return {
			academicYear: nextSchoolYear(schoolYearOf(new Date())),
			loading: false,
			error: '',
			activities: [],
			withoutPlan: [],
		}
	},

	computed: {
		/**
		 * This school year, the two before and the two after.
		 *
		 * @return {string[]}
		 * @spec openspec/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
		 */
		yearOptions() {
			const now = new Date()
			const out = []
			for (let offset = -2; offset <= 2; offset++) {
				out.push(
					schoolYearOf(
						new Date(now.getFullYear() + offset, now.getMonth(), 1),
					),
				)
			}
			return out
		},

		/**
		 * The activities grouped by group.
		 *
		 * @return {Array<{cohortId: string, cohortName: string, programmeYear: number, rows: Array<object>}>}
		 * @spec openspec/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
		 */
		groups() {
			const byId = new Map()
			for (const row of this.activities) {
				if (!byId.has(row.cohortId)) {
					byId.set(row.cohortId, {
						cohortId: row.cohortId,
						cohortName: row.cohortName,
						programmeYear: row.programmeYear,
						rows: [],
					})
				}
				byId.get(row.cohortId).rows.push(row)
			}
			return [...byId.values()].sort((a, b) =>
				String(a.cohortName).localeCompare(String(b.cohortName)),
			)
		},
	},

	watch: {
		/**
		 * Reload when the school year changes.
		 *
		 * @return {void}
		 * @spec openspec/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
		 */
		academicYear() {
			this.load()
		},
	},

	/**
	 * Load the activities of the default school year.
	 *
	 * @return {void}
	 * @spec openspec/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
	 */
	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Load the activities of the chosen school year.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const res = await axios.get(
					generateUrl('/apps/learniq/api/hour-plans/activities'),
					{ params: { academicYear: this.academicYear } },
				)
				this.activities = res.data?.activities || []
				this.withoutPlan = res.data?.cohortsWithoutPlan || []
			} catch (e) {
				this.activities = []
				this.withoutPlan = []
				this.error =
					e?.response?.status === 403
						? t(
								'learniq',
								'Only teachers, team leads and compliance officers can read the teaching activities.',
							)
						: t(
								'learniq',
								'The teaching activities could not be loaded.',
							)
			} finally {
				this.loading = false
			}
		},

		/**
		 * The label of an activity kind.
		 *
		 * @param {string} kind The kind.
		 * @return {string}
		 * @spec openspec/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
		 */
		kindLabel(kind) {
			return (
				{
					lesson: t('learniq', 'Lesson'),
					practical: t('learniq', 'Practical'),
					'work-placement': t('learniq', 'Work placement'),
					'self-study': t('learniq', 'Self study'),
					exam: t('learniq', 'Exam'),
				}[kind] || kind
			)
		},

		/**
		 * Download the activities as CSV.
		 *
		 * @return {void}
		 * @spec openspec/specs/school-structure/spec.md#scenario-a-timetabler-exports-next-year-s-activities
		 */
		exportCsv() {
			const csv = activitiesCsv(this.activities, {
				cohortName: t('learniq', 'Group'),
				programmeYear: t('learniq', 'Year of the programme'),
				courseName: t('learniq', 'Course'),
				periodCode: t('learniq', 'Period'),
				contactHours: t('learniq', 'Contact hours'),
				otherHours: t('learniq', 'Other hours'),
				activityKind: t('learniq', 'Kind of activity'),
				teacherIds: t('learniq', 'Teachers'),
			})
			const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' })
			const link = document.createElement('a')
			link.href = URL.createObjectURL(blob)
			link.download = `teaching-activities-${this.academicYear}.csv`
			link.click()
			URL.revokeObjectURL(link.href)
		},
	},
}
</script>

<style scoped>
.hour-plan-activities {
	padding: 16px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.hour-plan-activities__title {
	margin: 0;
}

.hour-plan-activities__intro {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
}

.hour-plan-activities__filters {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 12px;
}

.hour-plan-activities__table {
	border-collapse: collapse;
	width: 100%;
}

.hour-plan-activities__table th,
.hour-plan-activities__table td {
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: start;
}
</style>
