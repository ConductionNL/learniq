<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 SubjectChoicePicker -- the interactive vakkenpakket (elective-course)
 picker (admissions-and-subject-choice / school-structure).

 A custom view (the "custom view only where a manifest page can't render it"
 exception, matching BookConferenceSlotsView): lets the caller pick which of
 their linked children (or themselves, for an 18+ learner) and which
 CurriculumPlan to build a vakkenpakket for, shows LIVE feedback against the
 plan's electiveRules (minElectives/maxElectives, mandatoryCombinations,
 mutuallyExclusive, capacityByCourseId) as electives are (de)selected, then
 creates a `draft` SubjectChoice and immediately triggers its `submit`
 transition. The transition is gated server-side by
 SubjectChoiceConsentGuard -- the caller's identity is resolved from the NC
 session, never trusted from the client -- and the submitted choice is then
 authoritatively re-validated server-side by SubjectChoiceValidator; the
 feedback shown here is advisory, not the enforcement mechanism.

 Storage/lifecycle/notifications are OpenRegister's; this view only creates
 the choice and drives its submit transition through the OR object API.

 @spec openspec/specs/school-structure/spec.md#requirement-frontend-is-declarative-with-one-named-subject-choice-picker-exception
 @spec openspec/specs/school-structure/spec.md#requirement-guardian-consent-gates-a-minor-s-subject-choice-submission
-->
<template>
	<div class="subject-choice-picker">
		<h2>{{ t('learniq', 'Pick electives (vakkenpakket)') }}</h2>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="plans.length === 0"
			:name="t('learniq', 'No curriculum plans with electives found')"
			:description="
				t(
					'learniq',
					'Check back once your school publishes a curriculum plan with electiveCourseIds.',
				)
			" />

		<template v-else>
			<div class="subject-choice-picker__field">
				<NcSelect
					v-model="selectedPlanId"
					:options="planOptions"
					:reduce="(o) => o.id"
					label="label"
					:inputLabel="t('learniq', 'Curriculum plan')"
					@update:modelValue="onPlanChange" />
			</div>

			<div v-if="selectedPlan" class="subject-choice-picker__field">
				<NcSelect
					v-model="selectedLearnerId"
					:options="learnerOptions"
					:reduce="(o) => o.id"
					label="label"
					:loading="loadingLearners"
					:inputLabel="t('learniq', 'For which child (or yourself)')" />
			</div>

			<div v-if="selectedPlan" class="subject-choice-picker__field">
				<label for="scp-year">{{ t('learniq', 'Academic year') }}</label>
				<input
					id="scp-year"
					v-model="academicYear"
					type="text"
					placeholder="2026-2027" />
			</div>

			<CnChoiceCards
				v-if="selectedPlan"
				v-model="selectedCourseIds"
				:multiple="true"
				:label="t('learniq', 'Electives')"
				:options="electiveOptions"
				layout="rows"
				:rules="choiceRules"
				:rulesMetText="t('learniq', 'Your selection satisfies every declared rule so far.')"
				:clashEndpoint="clashEndpoint"
				data-testid="elective-choice" />
			<div
				v-if="selectedPlan"
				class="subject-choice-picker__field subject-choice-picker__consent">
				<input
					id="scp-consent"
					v-model="guardianConsentGiven"
					type="checkbox" />
				<label for="scp-consent">{{
					t(
						'learniq',
						'A guardian (or I, if 18+) consents to this selection',
					)
				}}</label>
			</div>

			<NcNoteCard v-if="submitError" type="error">
				{{ submitError }}
			</NcNoteCard>

			<NcNoteCard v-if="submitSuccess" type="success">
				{{
					t(
						'learniq',
						'Your subject choice has been submitted for review.',
					)
				}}
			</NcNoteCard>

			<NcButton
				v-if="selectedPlan"
				variant="primary"
				:disabled="!canSubmit || submitting"
				@click="submitChoice">
				{{
					submitting
						? t('learniq', 'Submitting…')
						: t('learniq', 'Submit subject choice')
				}}
			</NcButton>
		</template>
	</div>
</template>

<script>
import { CnChoiceCards } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'

export default {
	name: 'SubjectChoicePicker',

	components: {
		CnChoiceCards,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	data() {
		return {
			loading: true,
			loadingLearners: false,
			plans: [],
			courses: [],
			learners: [],
			selectedPlanId: '',
			selectedLearnerId: '',
			selectedCourseIds: [],
			academicYear: '',
			guardianConsentGiven: false,
			submitting: false,
			submitError: '',
			submitSuccess: false,
		}
	},

	computed: {
		/**
		 * Options for the curriculum-plan picker -- only plans that declare
		 * at least one elective course.
		 *
		 * @return {Array<object>}
		 * @spec openspec/specs/school-structure/spec.md#requirement-frontend-is-declarative-with-one-named-subject-choice-picker-exception
		 */
		planOptions() {
			return this.plans
				.filter(
					(p) =>
						Array.isArray(p.electiveCourseIds)
						&& p.electiveCourseIds.length > 0,
				)
				.map((p) => ({
					id: p.id || p.uuid,
					label: p.name || p.id || p.uuid,
				}))
		},

		/**
		 * The currently selected CurriculumPlan object, or null.
		 *
		 * @return {object|null}
		 * @spec openspec/specs/school-structure/spec.md#requirement-frontend-is-declarative-with-one-named-subject-choice-picker-exception
		 */
		selectedPlan() {
			return (
				this.plans.find((p) => (p.id || p.uuid) === this.selectedPlanId)
				|| null
			)
		},

		/**
		 * The selected plan's declared electiveRules, or an empty object when unset.
		 *
		 * @return {object}
		 * @spec openspec/specs/school-structure/spec.md#requirement-frontend-is-declarative-with-one-named-subject-choice-picker-exception
		 */
		electiveRules() {
			return (this.selectedPlan && this.selectedPlan.electiveRules) || {}
		},

		/**
		 * Elective options scoped to the selected plan's electiveCourseIds.
		 *
		 * @return {Array<object>}
		 * @spec openspec/specs/school-structure/spec.md#requirement-frontend-is-declarative-with-one-named-subject-choice-picker-exception
		 */
		electiveOptions() {
			if (!this.selectedPlan) return []
			return (this.selectedPlan.electiveCourseIds || []).map((id) => {
				const course = this.courses.find((c) => (c.id || c.uuid) === id)
				return { id, label: (course && course.name) || id }
			})
		},

		/**
		 * Learner options: the caller's linked children plus themselves (18+ self-choice).
		 *
		 * @return {Array<object>}
		 * @spec openspec/specs/school-structure/spec.md#requirement-frontend-is-declarative-with-one-named-subject-choice-picker-exception
		 */
		learnerOptions() {
			return this.learners.map((l) => ({
				id: l.ncUserId,
				label: l.displayName || l.ncUserId,
			}))
		},

		/**
		 * The plan's elective rules for the choice cards.
		 *
		 * @return {object}
		 * @spec openspec/specs/school-structure/spec.md#scenario-a-learner-picks-electives-with-live-rule-feedback
		 */
		choiceRules() {
			const rules = this.electiveRules
			return {
				min: rules.minElectives,
				max: rules.maxElectives,
				together: rules.mandatoryCombinations || [],
				exclusive: rules.mutuallyExclusive || [],
			}
		},

		/**
		 * Lesson-time clashes of the picked electives, worded by the server;
		 * the pupil's own lessons count when they choose for themselves.
		 *
		 * @return {object}
		 * @spec openspec/changes/timetabling-student-choice-placement/specs/timetable-student-choice/spec.md#requirement-clash-warning-when-choosing
		 */
		clashEndpoint() {
			return {
				url: '/apps/learniq/api/timetable/elective-clashes',
				params: { courseIds: '@selected', learnerId: this.selectedLearnerId },
				responsePath: 'messages',
			}
		},

		/**
		 * Whether the form has enough input to submit.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/school-structure/spec.md#requirement-frontend-is-declarative-with-one-named-subject-choice-picker-exception
		 */
		canSubmit() {
			return (
				this.selectedPlanId !== ''
				&& this.selectedLearnerId !== ''
				&& this.academicYear !== ''
				&& this.selectedCourseIds.length > 0
			)
		},
	},

	async mounted() {
		await this.loadPlansAndCourses()
		await this.loadLearners()
	},

	methods: {
		/**
		 * Load CurriculumPlans and Courses (for elective display labels).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/school-structure/spec.md#requirement-frontend-is-declarative-with-one-named-subject-choice-picker-exception
		 */
		async loadPlansAndCourses() {
			this.loading = true
			try {
				const [plansResp, coursesResp] = await Promise.all([
					axios.get(
						generateUrl(
							'/apps/openregister/api/objects/learniq/curriculum-plan?_limit=200',
						),
					),
					axios.get(
						generateUrl(
							'/apps/openregister/api/objects/learniq/course?_limit=500',
						),
					),
				])
				this.plans =
					(plansResp.data
						&& (plansResp.data.results || plansResp.data.objects))
					|| plansResp.data
					|| []
				this.courses =
					(coursesResp.data
						&& (coursesResp.data.results || coursesResp.data.objects))
					|| coursesResp.data
					|| []
			} catch (e) {
				// eslint-disable-next-line no-console
				console.error('[SubjectChoicePicker] loadPlansAndCourses failed', e)
				this.plans = []
				this.courses = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Load the caller's linked LearnerProfiles (children via parentIds, plus
		 * self via ncUserId for an 18+ self-choice).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/school-structure/spec.md#requirement-frontend-is-declarative-with-one-named-subject-choice-picker-exception
		 */
		async loadLearners() {
			this.loadingLearners = true
			const uid = getCurrentUser()?.uid
			if (!uid) {
				this.loadingLearners = false
				return
			}

			try {
				const [childrenResp, selfResp] = await Promise.all([
					axios.get(
						generateUrl(
							'/apps/openregister/api/objects/learniq/learner-profile?parentIds={uid}&_limit=50',
							{ uid },
						),
					),
					axios.get(
						generateUrl(
							'/apps/openregister/api/objects/learniq/learner-profile?ncUserId={uid}&_limit=1',
							{ uid },
						),
					),
				])
				const children =
					(childrenResp.data
						&& (childrenResp.data.results || childrenResp.data.objects))
					|| []
				const self =
					(selfResp.data
						&& (selfResp.data.results || selfResp.data.objects))
					|| []
				const byId = new Map()
				;[...children, ...self].forEach((l) => byId.set(l.ncUserId, l))
				this.learners = Array.from(byId.values())
			} catch (e) {
				// eslint-disable-next-line no-console
				console.error('[SubjectChoicePicker] loadLearners failed', e)
				this.learners = []
			} finally {
				this.loadingLearners = false
			}
		},

		/**
		 * Reset the elective selection when the plan changes.
		 *
		 * @return {void}
		 * @spec openspec/specs/school-structure/spec.md#requirement-frontend-is-declarative-with-one-named-subject-choice-picker-exception
		 */
		onPlanChange() {
			this.selectedCourseIds = []
		},

		/**
		 * Submit the choice in one server call, which creates it, stamps the
		 * tenant and who consented, and fires the submit transition. A guard
		 * refusal comes back as a 403 and leaves no draft.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/school-structure/spec.md#scenario-a-linked-guardian-can-submit-a-subject-choice-for-their-own-child
		 */
		async submitChoice() {
			if (!this.canSubmit) return

			this.submitting = true
			this.submitError = ''
			this.submitSuccess = false

			try {
				await axios.post(generateUrl('/apps/learniq/api/subject-choices'), {
					learnerId: this.selectedLearnerId,
					curriculumPlanId: this.selectedPlanId,
					academicYear: this.academicYear,
					selectedElectiveCourseIds: this.selectedCourseIds,
					guardianConsentGiven: this.guardianConsentGiven,
				})

				this.submitSuccess = true
				this.selectedCourseIds = []
			} catch (e) {
				// eslint-disable-next-line no-console
				console.error('[SubjectChoicePicker] submitChoice failed', e)
				this.submitError = this.t(
					'learniq',
					'We could not submit your subject choice. You may not be a linked guardian for this child, or the choice may violate a plan rule.',
				)
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.subject-choice-picker {
	padding: 1rem;
	max-width: 560px;
}

.subject-choice-picker__field {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	margin-bottom: 1rem;
}

.subject-choice-picker__consent {
	flex-direction: row;
	align-items: center;
	gap: 0.5rem;
}


</style>
