<!--
  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  SelfAssessmentView.vue
  Custom page component for the SelfAssessmentView manifest page (type: custom).

  A learner's own rubric self-scoring surface for their own Submission
  (peer-and-self-assessment):
  1. Fetch (or create) the learner's own SelfAssessment for this Submission +
     the parent Assignment + the Assignment's Rubric.
  2. For each Rubric criterion, let the learner pick a level.
  3. Sum up points into totalScore as each level is picked.
  4. On save: create/update the SelfAssessment (rubricScores + totalScore +
     comments + timing), then dispatch the `submit` lifecycle transition
     (server-side enforced by RubricScoresCompletionGuard regardless).

  Grade authority (openspec/specs/assignments/spec.md "Marking a Submission
  emits a GradeEntry"): this view NEVER writes Submission.rubricScores,
  Submission.proposedGrade, or any GradeEntry — the SelfAssessment is a
  parallel signal only, read by the teacher as read-only context in
  MarkSubmissionView.

  Talks only to OpenRegister's REST API:
    - GET  /api/objects/learniq/Submission/:id
    - GET  /api/objects/learniq/Assignment/:id
    - GET  /api/objects/learniq/Rubric/:id
    - GET  /api/objects/learniq/self-assessment?submissionId=:id&learnerId=:uid
    - POST/PUT /api/objects/learniq/self-assessment
    - POST /api/objects/:id/transition           ({ action: 'submit' })

  Uses Options API + direct fetch calls (no custom Pinia store modules),
  mirroring MarkSubmissionView / PeerReviewMarkingView.

  @spec openspec/specs/assignments/spec.md#scenario-a-learner-completes-a-self-assessment-before-submitting
  @spec openspec/specs/assignments/spec.md#scenario-a-learner-completes-a-self-assessment-after-submitting
-->

<template>
	<div class="self-assessment-view lq-board-page">
		<!-- Loading -->
		<div v-if="loading" class="self-assessment-view__loading" aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<span>{{ t('learniq', 'Loading self-assessment…') }}</span>
		</div>

		<!-- Error -->
		<div v-else-if="error" class="self-assessment-view__error" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ error }}</p>
		</div>

		<!-- Submitted confirmation -->
		<div
			v-else-if="submitted"
			class="self-assessment-view__confirmation"
			role="status"
			aria-live="polite">
			<span class="icon-checkmark" aria-hidden="true" />
			<CnPageHeader :title="t('learniq', 'Self-assessment submitted')" />
			<p>
				{{
					t('learniq', 'Score: {score} / {max}', {
						score: computedScore,
						max: assignment.maxPoints || '?',
					})
				}}
			</p>
		</div>

		<!-- Marking form -->
		<template v-else-if="submission">
			<header class="self-assessment-view__header">
				<CnPageHeader :title="t('learniq', 'Self-assessment')" />
				<p class="self-assessment-view__meta">
					{{
						t('learniq', 'Assignment: {title}', {
							title: assignment.title || '',
						})
					}}
				</p>
			</header>

			<CnDetailCard
				v-if="rubric && rubric.criteria && rubric.criteria.length > 0"
				:title="t('learniq', 'Rubric: {name}', { name: rubric.name || '' })"
				class="self-assessment-view__rubric">
				<RubricPicker
					v-model:selectedLevels="selectedLevels"
					:rubric="rubric"
					:maxPoints="assignment.maxPoints || 0"
					:disabled="saving" />
			</CnDetailCard>

			<CnDetailCard
				:title="t('learniq', 'Your reflection')"
				class="self-assessment-view__comments">
				<textarea
					id="self-assessment-comments"
					v-model="comments"
					class="self-assessment-view__comments-input"
					:aria-label="t('learniq', 'Your reflection')"
					:placeholder="t('learniq', 'Reflect on your own work…')"
					:disabled="saving"
					rows="5" />
			</CnDetailCard>

			<div class="self-assessment-view__actions">
				<button
					class="button-vue button-vue--primary self-assessment-view__submit-btn"
					:disabled="saving || !canSubmit"
					@click="saveAndSubmit">
					<span v-if="saving" class="icon-loading" aria-hidden="true" />
					{{ t('learniq', 'Submit self-assessment') }}
				</button>
			</div>
			<p
				v-if="saveError"
				role="alert"
				class="self-assessment-view__save-error">
				{{ saveError }}
			</p>
		</template>
	</div>
</template>

<script>
import { CnDetailCard, CnPageHeader } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import RubricPicker from '../components/RubricPicker.vue'
import { transitionUrl as objectTransitionUrl } from '../utils/customPages.js'
import {
	readFirstRow,
	readObject,
	readRequiredObject,
	restoreLevels,
	rubricComplete,
	rubricPoints,
	rubricScoresOf,
	sendJsonOrThrow,
	SUBMIT_TRANSITION,
} from '../utils/registerRequests.js'

export default {
	name: 'SelfAssessmentView',
	components: { CnDetailCard, CnPageHeader, RubricPicker },

	props: {
		/**
		 * Assignment UUID injected by vue-router from :assignmentId param.
		 */
		assignmentId: {
			type: String,
			required: true,
		},

		/**
		 * Submission UUID injected by vue-router from :id param.
		 */
		id: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			/** @type {object|null} */
			submission: null,
			/** @type {object} */
			assignment: {},
			/** @type {object|null} */
			rubric: null,
			/** @type {object|null} Existing SelfAssessment row, if any. */
			selfAssessment: null,
			/**
			 * Map of criterionId → { levelId, points }
			 *
			 * @type {Record<string, { levelId: string, points: number }>}
			 */
			selectedLevels: {},
			/** @type {string} */
			comments: '',
			loading: false,
			saving: false,
			submitted: false,
			error: null,
			saveError: null,
		}
	},

	computed: {
		/**
		 * Sum of points for all selected criterion levels.
		 *
		 * @return {number}
		 * @spec openspec/specs/assignments/spec.md#scenario-a-learner-completes-a-self-assessment-before-submitting
		 */
		computedScore() {
			return rubricPoints(this.selectedLevels)
		},

		/**
		 * Whether every Rubric criterion has been scored (client-side hint only —
		 * the server enforces this via RubricScoresCompletionGuard regardless).
		 *
		 * @return {boolean}
		 * @spec openspec/specs/assignments/spec.md#scenario-a-learner-completes-a-self-assessment-before-submitting
		 */
		canSubmit() {
			return rubricComplete(this.rubric, this.selectedLevels)
		},

		/**
		 * Timing this self-assessment is completed at, per whether the linked
		 * Submission has already been submitted.
		 *
		 * @return {string} 'before-submission' or 'after-submission'
		 * @spec openspec/specs/assignments/spec.md#scenario-a-learner-completes-a-self-assessment-before-submitting
		 */
		timing() {
			return this.submission && this.submission.lifecycle === 'draft'
				? 'before-submission'
				: 'after-submission'
		},
	},

	watch: {
		id: {
			immediate: true,
			/**
			 * React to the Submission id prop changing by loading all data.
			 *
			 * @param {string} newId New Submission UUID
			 * @return {void}
			 * @spec openspec/specs/assignments/spec.md#scenario-a-learner-completes-a-self-assessment-before-submitting
			 */
			handler(newId) {
				if (newId) {
					this.loadData(newId)
				}
			},
		},
	},

	methods: {
		/**
		 * Load the Submission, Assignment, Rubric, and any existing SelfAssessment
		 * for the current learner.
		 *
		 * @param {string} submissionId Submission UUID
		 * @return {Promise<void>}
		 * @spec openspec/specs/assignments/spec.md#scenario-a-learner-completes-a-self-assessment-before-submitting
		 */
		async loadData(submissionId) {
			this.loading = true
			this.error = null

			try {
				await this.loadSubmission(submissionId)
				await this.loadAssignment(
					this.submission.assignmentId ?? this.assignmentId,
				)

				if (this.assignment.rubricId) {
					await this.loadRubric(this.assignment.rubricId)
				}

				await this.loadExistingSelfAssessment(submissionId)

				if (this.selfAssessment) {
					restoreLevels(
						this.selectedLevels,
						this.selfAssessment.rubricScores ?? [],
					)
					this.comments = this.selfAssessment.comments ?? ''
				}
			} catch (err) {
				this.error = this.t(
					'learniq',
					'Failed to load self-assessment. Please try again.',
				)
				// eslint-disable-next-line no-console
				console.error('[SelfAssessmentView] loadData error', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the Submission from OR.
		 *
		 * @param {string} submissionId Submission UUID
		 * @return {Promise<void>}
		 * @spec openspec/specs/assignments/spec.md#scenario-a-learner-completes-a-self-assessment-before-submitting
		 */
		async loadSubmission(submissionId) {
			this.submission = await readRequiredObject(
				generateUrl(`/apps/openregister/api/objects/learniq/Submission/${submissionId}`),
				'Submission fetch failed',
			)
		},

		/**
		 * Fetch the Assignment from OR.
		 *
		 * @param {string} assignmentId Assignment UUID
		 * @return {Promise<void>}
		 * @spec openspec/specs/assignments/spec.md#scenario-a-learner-completes-a-self-assessment-before-submitting
		 */
		async loadAssignment(assignmentId) {
			this.assignment = await readRequiredObject(
				generateUrl(`/apps/openregister/api/objects/learniq/Assignment/${assignmentId}`),
				'Assignment fetch failed',
			)
		},

		/**
		 * Fetch the Rubric from OR.
		 *
		 * @param {string} rubricId Rubric UUID
		 * @return {Promise<void>}
		 * @spec openspec/specs/assignments/spec.md#scenario-a-learner-completes-a-self-assessment-before-submitting
		 */
		async loadRubric(rubricId) {
			const read = await readObject(
				generateUrl(`/apps/openregister/api/objects/learniq/Rubric/${rubricId}`),
			)
			// A rubric that cannot be read is not fatal: the page shows none.
			if (read.ok) {
				this.rubric = read.object ?? null
			}
		},

		/**
		 * Fetch the current learner's own SelfAssessment for this Submission, if
		 * one already exists (draft in progress).
		 *
		 * @param {string} submissionId Submission UUID
		 * @return {Promise<void>}
		 * @spec openspec/specs/assignments/spec.md#scenario-a-learner-completes-a-self-assessment-before-submitting
		 */
		async loadExistingSelfAssessment(submissionId) {
			const uid = getCurrentUser()?.uid ?? ''
			const url = generateUrl(
				`/apps/openregister/api/objects/learniq/self-assessment?submissionId=${submissionId}&learnerId=${uid}&_limit=1`,
			)
			const read = await readFirstRow(url)
			if (read.ok) {
				this.selfAssessment = read.row
			}
		},

		/**
		 * Build the rubricScores array from current selections.
		 *
		 * @return {Array<{criterionId: string, levelId: string, points: number}>}
		 * @spec openspec/specs/assignments/spec.md#scenario-a-learner-completes-a-self-assessment-before-submitting
		 */
		buildRubricScores() {
			return rubricScoresOf(this.selectedLevels)
		},

		/**
		 * Create/update the SelfAssessment, then dispatch the `submit` lifecycle
		 * transition. Never writes Submission.rubricScores/proposedGrade or a
		 * GradeEntry — grade authority stays with the teacher.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/assignments/spec.md#scenario-a-learner-completes-a-self-assessment-before-submitting
		 */
		async saveAndSubmit() {
			if (!this.submission) {
				return
			}
			this.saving = true
			this.saveError = null

			const scores = this.learnerScores()

			try {
				const selfAssessmentId = await this.saveSelfAssessment(
					this.selfAssessmentPayload(scores),
				)
				await sendJsonOrThrow(
					generateUrl(objectTransitionUrl(selfAssessmentId)),
					SUBMIT_TRANSITION,
				)
				this.submitted = true
			} catch (err) {
				this.saveError = this.t(
					'learniq',
					'Failed to save self-assessment. Please try again.',
				)
				// eslint-disable-next-line no-console
				console.error('[SelfAssessmentView] saveAndSubmit error', err)
			} finally {
				this.saving = false
			}
		},

		/**
		 * The learner's rubric scores, their total and who they are.
		 *
		 * @return {{rubricScores: Array<object>, totalScore: number, learnerId: string}} The scores.
		 * @spec openspec/specs/assignments/spec.md#scenario-a-learner-completes-a-self-assessment-before-submitting
		 */
		learnerScores() {
			return {
				rubricScores: this.buildRubricScores(),
				totalScore: this.computedScore,
				learnerId: getCurrentUser()?.uid ?? '',
			}
		},

		/**
		 * The SelfAssessment to save.
		 *
		 * @param {{rubricScores: Array<object>, totalScore: number, learnerId: string}} scores The learner's scores.
		 * @return {object} The SelfAssessment.
		 * @spec openspec/specs/assignments/spec.md#scenario-a-learner-completes-a-self-assessment-before-submitting
		 */
		selfAssessmentPayload(scores) {
			return {
				assignmentId: this.assignment.id ?? this.assignmentId,
				submissionId: this.id,
				learnerId: scores.learnerId,
				timing: this.timing,
				rubricScores: scores.rubricScores,
				totalScore: scores.totalScore,
				comments: this.comments,
				tenant_id: this.submission.tenant_id ?? '',
			}
		},

		/**
		 * The request that saves the SelfAssessment: a create, or an update of
		 * the draft already there.
		 *
		 * @param {object} payload The SelfAssessment.
		 * @return {{url: string, method: string, body: object}} The request.
		 * @spec openspec/specs/assignments/spec.md#scenario-a-learner-completes-a-self-assessment-before-submitting
		 */
		selfAssessmentRequest(payload) {
			const isUpdate = this.selfAssessment != null
			const collection = '/apps/openregister/api/objects/learniq/self-assessment'
			const path = isUpdate ? `${collection}/${this.selfAssessment.id}` : collection
			return {
				url: generateUrl(path),
				method: isUpdate ? 'PUT' : 'POST',
				body: isUpdate ? { ...payload, id: this.selfAssessment.id } : payload,
			}
		},

		/**
		 * Save the SelfAssessment.
		 *
		 * @param {object} payload The SelfAssessment.
		 * @return {Promise<string>} Its id.
		 * @spec openspec/specs/assignments/spec.md#scenario-a-learner-completes-a-self-assessment-before-submitting
		 */
		async saveSelfAssessment(payload) {
			const request = this.selfAssessmentRequest(payload)
			const saveResp = await sendJsonOrThrow(request.url, {
				method: request.method,
				body: request.body,
				failure: 'SelfAssessment save failed',
			})
			const savedJson = await saveResp.json()
			const saved = savedJson.object ?? savedJson ?? {}
			const selfAssessmentId = saved.id ?? this.selfAssessment?.id
			if (!selfAssessmentId) {
				throw new Error('SelfAssessment save did not return an id')
			}
			return selfAssessmentId
		},
	},
}
</script>

<style scoped>
.self-assessment-view__loading,
.self-assessment-view__error {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.self-assessment-view__meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}









.self-assessment-view__comments-input {
	width: 100%;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	padding: 8px;
	resize: vertical;
	font-family: inherit;
}

.self-assessment-view__actions {
	display: flex;
	justify-content: flex-end;
	margin-top: calc(var(--default-grid-baseline, 8px) * 3);
}

.self-assessment-view__save-error {
	color: var(--color-error);
	font-size: 0.9em;
	margin-top: var(--default-grid-baseline, 8px);
	text-align: right;
}

.self-assessment-view__confirmation {
	text-align: center;
	padding: calc(var(--default-grid-baseline, 8px) * 4);
}
</style>
