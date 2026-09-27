<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 BulkEnrolView: enrol a whole audience in one course
 (route /enrolments/bulk, learniq#947).

 Pick a course, then the audience: a cohort (all its learners) and/or
 individual learners. The confirm step says how many are new; learners with
 an open enrolment for the course are skipped. Each new learner gets an
 Enrolment with source bulk. The prerequisite gate (EnrolmentPrerequisiteListener)
 still applies per learner, so a refusal is reported by name.

 @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
-->
<template>
	<div class="bulk-enrol">
		<h2>{{ t('learniq', 'Enrol a group in a course') }}</h2>
		<form class="bulk-enrol__form" @submit.prevent="enrol">
			<NcSelect
				v-model="course"
				:options="courseOptions"
				:getOptionLabel="(c) => c.name || c.code || c.id"
				:inputLabel="t('learniq', 'Course')" />

			<NcSelect
				v-model="cohort"
				:options="cohortOptions"
				:getOptionLabel="(c) => c.name || c.id"
				:inputLabel="t('learniq', 'Group (optional)')" />

			<NcSelect
				v-model="learners"
				:options="learnerOptions"
				:multiple="true"
				:filterable="false"
				:loading="searching"
				:getOptionLabel="learnerLabel"
				:inputLabel="t('learniq', 'Individual learners (optional)')"
				:placeholder="t('learniq', 'Search by name')"
				@search="searchLearners" />

			<p v-if="course" class="bulk-enrol__summary" aria-live="polite">
				{{
					n(
						'learniq',
						'%n learner will be enrolled.',
						'%n learners will be enrolled.',
						toEnrol.length,
					)
				}}
				<span v-if="skipped > 0">
					{{
						n(
							'learniq',
							'%n already has an open enrolment and is skipped.',
							'%n already have an open enrolment and are skipped.',
							skipped,
						)
					}}
				</span>
			</p>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard v-if="done > 0" type="success">
				{{
					n(
						'learniq',
						'%n learner enrolled.',
						'%n learners enrolled.',
						done,
					)
				}}
			</NcNoteCard>

			<div class="bulk-enrol__actions">
				<NcButton
					type="submit"
					variant="primary"
					:disabled="saving || !course || toEnrol.length === 0">
					{{ t('learniq', 'Enrol') }}
				</NcButton>
			</div>
		</form>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard, NcSelect } from '@nextcloud/vue'
import {
	bulkEnrolmentBody,
	learnersToEnrol,
	listRows,
	objectId,
	objectsUrl,
} from '../utils/customPages.js'

export default {
	name: 'BulkEnrolView',

	components: { NcButton, NcNoteCard, NcSelect },

	data() {
		return {
			course: null,
			courseOptions: [],
			cohort: null,
			cohortOptions: [],
			learners: [],
			learnerOptions: [],
			searching: false,
			existing: [],
			saving: false,
			error: '',
			done: 0,
		}
	},

	computed: {
		/**
		 * @return {string[]} Every picked learner (Nextcloud user ids).
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		audience() {
			const fromCohort = this.cohort?.learnerIds ?? []
			const picked = this.learners.map((l) => l.ncUserId).filter(Boolean)
			return [...new Set([...fromCohort, ...picked])]
		},

		/**
		 * @return {string[]} Learners who get a new enrolment.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		toEnrol() {
			return learnersToEnrol(this.audience, this.existing)
		},

		/**
		 * @return {number} Picked learners skipped for an open enrolment.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		skipped() {
			return this.audience.length - this.toEnrol.length
		},
	},

	watch: {
		/**
		 * Reload the course's existing enrolments when the course changes.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		async course() {
			this.existing = []
			this.done = 0
			if (!this.course) return
			try {
				this.existing = listRows(
					(
						await axios.get(generateUrl(objectsUrl('enrolment')), {
							params: {
								courseId: objectId(this.course),
								_limit: 5000,
							},
						})
					).data,
				)
			} catch {
				this.error = this.t(
					'learniq',
					'The enrolments of this course could not be loaded.',
				)
			}
		},
	},

	async mounted() {
		try {
			const [courses, cohorts] = await Promise.all([
				axios.get(generateUrl(objectsUrl('course')), {
					params: { _limit: 500 },
				}),
				axios.get(generateUrl(objectsUrl('cohort')), {
					params: { _limit: 500 },
				}),
			])
			this.courseOptions = listRows(courses.data)
			this.cohortOptions = listRows(cohorts.data)
		} catch {
			this.error = this.t('learniq', 'Courses and groups could not be loaded.')
		}
		this.searchLearners('')
	},

	methods: {
		/**
		 * @param {object} p A LearnerProfile.
		 * @return {string} The name.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		learnerLabel(p) {
			return (
				[p.givenName, p.familyName].filter(Boolean).join(' ')
				|| p.ncUserId
				|| objectId(p)
			)
		},

		/**
		 * @param {string} query Typed text.
		 * @return {Promise<void>}
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		async searchLearners(query) {
			this.searching = true
			try {
				this.learnerOptions = listRows(
					(
						await axios.get(generateUrl(objectsUrl('learner-profile')), {
							params: { _search: query || undefined, _limit: 50 },
						})
					).data,
				)
			} catch {
				this.learnerOptions = []
			} finally {
				this.searching = false
			}
		},

		/**
		 * Create one Enrolment per new learner.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		async enrol() {
			this.saving = true
			this.error = ''
			this.done = 0
			const refused = []
			const cohortId = this.cohort ? objectId(this.cohort) : null
			for (const learnerId of this.toEnrol) {
				try {
					const response = await axios.post(
						generateUrl(objectsUrl('enrolment')),
						bulkEnrolmentBody(learnerId, this.course, cohortId),
					)
					this.existing.push({
						...response.data,
						learnerId,
						lifecycle: 'pending',
					})
					this.done++
				} catch (e) {
					refused.push(
						learnerId
							+ (e?.response?.data?.error
								? ' (' + e.response.data.error + ')'
								: ''),
					)
				}
			}
			this.existing = [...this.existing]
			if (refused.length > 0) {
				this.error = this.t('learniq', 'Not enrolled: {names}', {
					names: refused.join(', '),
				})
			}
			this.saving = false
		},
	},
}
</script>

<style scoped>
.bulk-enrol {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	max-inline-size: 40rem;
}

.bulk-enrol__form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}
</style>
