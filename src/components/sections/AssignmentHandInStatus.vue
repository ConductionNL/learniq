<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 AssignmentHandInStatus: who has not handed in an assignment yet
 (assignment-missing-submissions-view). A body section on AssignmentDetail.

 Diffs the assignment's cohort roster against its submissions, the way the
 attendance register diffs a session against its cohort. Staff only: a pupil
 can read the roster but only their own submission, so for them the diff
 would call every classmate missing.

 @spec openspec/changes/assignment-missing-submissions-view/specs/assignments/spec.md#requirement-a-teacher-sees-who-has-not-handed-in-an-assignment
-->
<template>
	<div v-if="allowed" class="hand-in-status">
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="loadError" type="error">
			{{ loadError }}
		</NcNoteCard>
		<NcEmptyContent
			v-else-if="summary.total === 0"
			:name="t('learniq', 'No learners in this group')"
			:description="
				t('learniq', 'Link the assignment to a group to see who handed in.')
			" />
		<template v-else>
			<p class="hand-in-status__summary">
				{{
					t('learniq', '{done} of {total} handed in', {
						done: summary.handedIn,
						total: summary.total,
					})
				}}
			</p>
			<p v-if="missing.length === 0" class="hand-in-status__complete">
				{{ t('learniq', 'Everyone has handed in.') }}
			</p>
			<ul v-else class="hand-in-status__list">
				<li
					v-for="row in missing"
					:key="row.learnerId"
					class="hand-in-status__row">
					<span class="hand-in-status__name">{{
						learnerName(row.learnerId)
					}}</span>
					<span class="hand-in-status__state">{{ stateLabel(row) }}</span>
					<span v-if="row.overdue" class="hand-in-status__overdue">
						{{ t('learniq', 'Overdue') }}
					</span>
				</li>
			</ul>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import { NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { listRows, objectsUrl, oneObject } from '../../utils/customPages.js'
import {
	canSeeHandInStatus,
	handInRows,
	handInSummary,
	rosterFor,
} from '../../utils/handInStatus.js'

export default {
	name: 'AssignmentHandInStatus',

	components: { NcEmptyContent, NcLoadingIcon, NcNoteCard },

	props: {
		/** Assignment UUID, from the manifest's `@objectId`. */
		assignmentId: { type: String, default: '' },
	},

	data() {
		return {
			allowed: canSeeHandInStatus(loadState('learniq', 'dashboardRoles', [])),
			loading: true,
			loadError: '',
			rows: [],
			names: {},
		}
	},

	computed: {
		/**
		 * @return {object} Counts per state.
		 * @spec openspec/changes/assignment-missing-submissions-view/specs/assignments/spec.md#requirement-a-teacher-sees-who-has-not-handed-in-an-assignment
		 */
		summary() {
			return handInSummary(this.rows)
		},

		/**
		 * Started first, then not started, each in roster order.
		 *
		 * @return {object[]} The learners who have not handed in.
		 * @spec openspec/changes/assignment-missing-submissions-view/specs/assignments/spec.md#requirement-a-teacher-sees-who-has-not-handed-in-an-assignment
		 */
		missing() {
			return [
				...this.rows.filter((r) => r.state === 'started'),
				...this.rows.filter((r) => r.state === 'not-started'),
			]
		},
	},

	watch: {
		assignmentId() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Load the assignment, its roster and its submissions, then diff.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/assignment-missing-submissions-view/specs/assignments/spec.md#requirement-a-teacher-sees-who-has-not-handed-in-an-assignment
		 */
		async load() {
			if (!this.allowed || !this.assignmentId) {
				this.loading = false
				return
			}
			this.loading = true
			this.loadError = ''
			try {
				const assignment = oneObject(
					(
						await axios.get(
							generateUrl(objectsUrl('assignment', this.assignmentId)),
						)
					).data,
				)
				const cohorts = await this.loadCohorts(assignment)
				const submissions = listRows(
					(
						await axios.get(generateUrl(objectsUrl('submission')), {
							params: { assignmentId: this.assignmentId, _limit: 500 },
						})
					).data,
				)
				const roster = rosterFor(assignment, cohorts)
				this.rows = handInRows(roster, submissions, assignment, new Date())
				await this.loadNames()
			} catch {
				this.loadError = this.t(
					'learniq',
					'The hand-in status could not be loaded.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * The assignment's cohort, or every cohort of its course.
		 *
		 * @param {object} assignment The Assignment.
		 * @return {Promise<object[]>} Cohort rows.
		 * @spec openspec/changes/assignment-missing-submissions-view/specs/assignments/spec.md#requirement-a-teacher-sees-who-has-not-handed-in-an-assignment
		 */
		async loadCohorts(assignment) {
			if (assignment.cohortId) {
				const cohort = oneObject(
					(
						await axios.get(
							generateUrl(objectsUrl('cohort', assignment.cohortId)),
						)
					).data,
				)
				return [{ ...cohort, id: cohort.id ?? assignment.cohortId }]
			}
			if (!assignment.courseId) return []
			return listRows(
				(
					await axios.get(generateUrl(objectsUrl('cohort')), {
						params: { courseId: assignment.courseId, _limit: 100 },
					})
				).data,
			)
		},

		/**
		 * Names from LearnerProfile; the user id is the fallback.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/assignment-missing-submissions-view/specs/assignments/spec.md#requirement-a-teacher-sees-who-has-not-handed-in-an-assignment
		 */
		async loadNames() {
			if (this.missing.length === 0) return
			try {
				const profiles = listRows(
					(
						await axios.get(generateUrl(objectsUrl('learner-profile')), {
							params: { _limit: 1000 },
						})
					).data,
				)
				const names = {}
				for (const p of profiles) {
					const name = [p.givenName, p.familyName]
						.filter(Boolean)
						.join(' ')
					if (p.ncUserId && name) names[p.ncUserId] = name
				}
				this.names = names
			} catch {
				this.names = {}
			}
		},

		/**
		 * @param {string} learnerId Nextcloud user id.
		 * @return {string} The display name.
		 * @spec openspec/changes/assignment-missing-submissions-view/specs/assignments/spec.md#requirement-a-teacher-sees-who-has-not-handed-in-an-assignment
		 */
		learnerName(learnerId) {
			return this.names[learnerId] || learnerId
		},

		/**
		 * @param {object} row A hand-in row.
		 * @return {string} The translated state.
		 * @spec openspec/changes/assignment-missing-submissions-view/specs/assignments/spec.md#requirement-a-teacher-sees-who-has-not-handed-in-an-assignment
		 */
		stateLabel(row) {
			return row.state === 'started'
				? this.t('learniq', 'Started, not handed in')
				: this.t('learniq', 'Not started')
		},
	},
}
</script>

<style scoped>
.hand-in-status__summary {
	font-weight: bold;
	margin-block-end: calc(var(--default-grid-baseline, 4px) * 2);
}

.hand-in-status__complete {
	color: var(--color-text-maxcontrast);
}

.hand-in-status__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.hand-in-status__row {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	padding-block: calc(var(--default-grid-baseline, 4px) * 2);
	border-block-end: 1px solid var(--color-border);
}

.hand-in-status__name {
	flex: 1 1 12rem;
}

.hand-in-status__state {
	color: var(--color-text-maxcontrast);
}

.hand-in-status__overdue {
	color: var(--color-error-text);
	font-weight: bold;
}
</style>
