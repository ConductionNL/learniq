<!--
  PortfolioReviewView.vue
  Custom page component for the PortfolioReviewView manifest page (type: custom).

  eportfolio: the read-only review surface for a Portfolio's entries —
  resolving and displaying the referenced Submission / WerkprocesAssessment /
  ExternalTrainingRecord / Credential per entry (attachmentRef / reflection
  entries render inline, no further lookup needed) — plus, for the grading
  teacher of a `course-bound` Portfolio in `submitted` state only, a
  `gradeValue` entry field and the `grade` transition. Once graded, the
  resulting `concept` GradeEntry is consumed unchanged by the existing
  GradebookView / GradeRollupHandler roll-up (this view does not compute a
  final grade itself — PortfolioGradeEmitHandler does the emission
  server-side on the `graded` transition).

  Talks only to OpenRegister's REST API:
    - GET  /api/objects/learniq/Portfolio/:id
    - GET  /api/objects/learniq/portfolio-entry?portfolioId=:id
    - GET  /api/objects/learniq/Submission/:id                  (per submission-kind entry)
    - GET  /api/objects/learniq/werkproces-assessment/:id        (per werkproces-assessment-kind entry)
    - GET  /api/objects/learniq/external-training-record/:id      (per external-training-record-kind entry)
    - GET  /api/objects/learniq/Credential/:id                  (per credential-kind entry)
    - PUT  /api/objects/learniq/Portfolio/:id                   (gradeValue)
    - POST /api/objects/:id/transition           ({ action: 'grade' })

  Uses Options API + direct fetch calls (no custom Pinia store modules),
  mirroring MarkSubmissionView.vue's existing shape.

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  @spec openspec/specs/eportfolio/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
  @spec openspec/specs/eportfolio/spec.md#requirement-a-graded-course-bound-portfolio-flows-through-the-existing-gradeentry-pipeline-not-a-parallel-one
-->

<template>
	<div class="portfolio-review-view lq-board-page">
		<!-- Loading -->
		<div
			v-if="loading"
			class="portfolio-review-view__loading"
			aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<span>{{ t('learniq', 'Loading portfolio...') }}</span>
		</div>

		<!-- Error -->
		<div v-else-if="error" class="portfolio-review-view__error" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ error }}</p>
		</div>

		<template v-else-if="portfolio">
			<header class="portfolio-review-view__header">
				<CnPageHeader :title="t('learniq', 'Review portfolio: {title}', { title: portfolio.title || '' })" />
				<p class="portfolio-review-view__meta">
					{{
						t('learniq', 'Kind: {kind}', { kind: portfolio.kind || '' })
					}}
					<span class="portfolio-review-view__lifecycle">
						{{
							t('learniq', 'Status: {status}', {
								status: portfolio.lifecycle || '',
							})
						}}
					</span>
				</p>
			</header>

			<!-- Entries (read-only) -->
			<CnDetailCard
				:title="t('learniq', 'Evidence entries')"
				class="portfolio-review-view__entries">
				<ul
					v-if="entries.length > 0"
					class="portfolio-review-view__entry-list">
					<li
						v-for="entry in entries"
						:key="entry.id"
						class="portfolio-review-view__entry-item">
						<span class="portfolio-review-view__entry-kind">{{
							/**
							 * @spec openspec/specs/eportfolio/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
							 */
							evidenceKindLabel(entry.evidenceKind)
						}}</span>
						<span class="portfolio-review-view__entry-title">{{
							entry.title
						}}</span>

						<p
							v-if="entry.evidenceKind === 'reflection'"
							class="portfolio-review-view__entry-reflection">
							{{ entry.reflectionText }}
						</p>
						<p
							v-else-if="
								entry.evidenceKind === 'file' && entry.attachmentRef
							"
							class="portfolio-review-view__entry-file">
							{{ entry.attachmentRef }}
						</p>
						<p
							v-else-if="resolvedReferences[entry.id]"
							class="portfolio-review-view__entry-resolved">
							{{ resolvedReferences[entry.id] }}
						</p>
					</li>
				</ul>
				<p v-else class="portfolio-review-view__no-entries">
					{{ t('learniq', 'No evidence entries.') }}
				</p>
			</CnDetailCard>

			<!-- Teacher grading (course-bound + submitted only) -->
			<CnDetailCard
				v-if="canGrade"
				:title="t('learniq', 'Grade this portfolio')"
				class="portfolio-review-view__grading">

				<label
					for="pr-grade-value"
					class="portfolio-review-view__field-label">
					{{ t('learniq', 'Grade value') }}
				</label>
				<input
					id="pr-grade-value"
					v-model.number="gradeValue"
					type="number"
					class="portfolio-review-view__grade-input"
					:disabled="grading" />

				<div class="portfolio-review-view__actions">
					<button
						class="button-vue button-vue--primary portfolio-review-view__grade-btn"
						:disabled="
							grading || gradeValue === null || gradeValue === ''
						"
						@click="gradePortfolio">
						<span
							v-if="grading"
							class="icon-loading"
							aria-hidden="true" />
						{{ t('learniq', 'Save grade & transition to graded') }}
					</button>
				</div>
				<p
					v-if="gradeError"
					role="alert"
					class="portfolio-review-view__grade-error">
					{{ gradeError }}
				</p>
			</CnDetailCard>

			<p
				v-if="portfolio.lifecycle === 'graded'"
				class="portfolio-review-view__graded-confirmation"
				role="status">
				{{
					t('learniq', 'This portfolio has been graded. Grade: {grade}', {
						grade: portfolio.gradeValue,
					})
				}}
			</p>
		</template>
	</div>
</template>

<script>
import { CnDetailCard, CnPageHeader } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import { transitionUrl as objectTransitionUrl } from '../utils/customPages.js'
import {
	evidenceKindLabel,
	lookupList,
	lookupObject,
	portfolioLoadError,
	referencedTitle,
} from '../utils/portfolioEvidence.js'
import { sendJsonOrThrow } from '../utils/registerRequests.js'

/**
 * Map from PortfolioEntry.evidenceKind to the OR schema + id field to
 * resolve for display.
 *
 * @type {Record<string, { schema: string, idField: string }>}
 */
const REFERENCE_SCHEMAS = {
	submission: { schema: 'Submission', idField: 'submissionId' },
	// `schema` is the OpenRegister SLUG — the URL segment resolves by
	// lower(slug), so a multi-word title such as "WerkprocesAssessment" 404s.
	// The map key already IS the slug for these two.
	'werkproces-assessment': {
		schema: 'werkproces-assessment',
		idField: 'werkprocesAssessmentId',
	},
	'external-training-record': {
		schema: 'external-training-record',
		idField: 'externalTrainingRecordId',
	},
	credential: { schema: 'Credential', idField: 'credentialId' },
}

export default {
	name: 'PortfolioReviewView',
	components: { CnDetailCard, CnPageHeader },

	props: {
		/**
		 * Portfolio UUID injected by vue-router from the :id param.
		 */
		id: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			/** @type {object|null} */
			portfolio: null,
			/** @type {Array<object>} */
			entries: [],
			/** @type {Record<string, string>} entry.id -> a display label for the resolved reference */
			resolvedReferences: {},
			/** @type {number|string|null} */
			gradeValue: null,
			loading: false,
			error: null,
			grading: false,
			gradeError: null,
		}
	},

	computed: {
		/**
		 * Grading is only offered for a course-bound portfolio in `submitted`
		 * state — mirrors PortfolioGradeEmitHandler's `to: graded` listener
		 * (`submitted -> graded` is the only transition it reacts to).
		 *
		 * @return {boolean}
		 * @spec openspec/specs/eportfolio/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
		 */
		canGrade() {
			return (
				this.portfolio?.kind === 'course-bound'
				&& this.portfolio?.lifecycle === 'submitted'
			)
		},
	},

	watch: {
		id: {
			immediate: true,
			/**
			 * React to the portfolio id prop changing by loading all data.
			 *
			 * @param {string} newId New portfolio UUID
			 * @return {Promise<void>}
			 * @spec openspec/specs/eportfolio/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
			 */
			async handler(newId) {
				if (newId) {
					await this.loadData(newId)
				}
			},
		},
	},

	methods: {
		/**
		 * Load the Portfolio, its PortfolioEntry rows, and resolve each
		 * reference-kind entry's referenced object for display.
		 *
		 * @param {string} portfolioId Portfolio UUID
		 * @return {Promise<void>}
		 * @spec openspec/specs/eportfolio/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
		 */
		async loadData(portfolioId) {
			this.loading = true
			this.error = null

			try {
				this.portfolio = await this.fetchObject('Portfolio', portfolioId)
				this.gradeValue = this.portfolio.gradeValue ?? null

				this.entries = await this.fetchList(
					'portfolio-entry',
					`portfolioId=${portfolioId}&_limit=100`,
				)
				await this.resolveEntryReferences()
			} catch (err) {
				// A missing record is input, not a fault — see the same branch in
				// PortfolioBuilder.vue. A 404 here means the portfolio is gone or
				// was never visible to this reviewer; that is worth telling them
				// and not worth logging as an application error.
				this.error = portfolioLoadError(err, (app, text) => this.t(app, text))
				if (err?.notFound !== true) {
					// eslint-disable-next-line no-console
					console.error('[PortfolioReviewView] loadData error', err)
				}
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch a single OpenRegister object.
		 *
		 * @param {string} schema OR schema PascalCase key.
		 * @param {string} objId  Object UUID.
		 * @return {Promise<object>}
		 * @spec openspec/specs/eportfolio/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
		 */
		async fetchObject(schema, objId) {
			return lookupObject(
				generateUrl(`/apps/openregister/api/objects/learniq/${schema}/${objId}`),
				schema,
			)
		},

		/**
		 * Fetch a filtered list of OpenRegister objects.
		 *
		 * @param {string} schema OR schema PascalCase key.
		 * @param {string} query  Pre-built query string (already URL-encoded).
		 * @return {Promise<Array<object>>}
		 * @spec openspec/specs/eportfolio/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
		 */
		async fetchList(schema, query) {
			return lookupList(
				generateUrl(`/apps/openregister/api/objects/learniq/${schema}?${query}`),
			)
		},

		/**
		 * For every entry whose evidenceKind resolves through one of
		 * REFERENCE_SCHEMAS, fetch the referenced object and build a display
		 * label. Best-effort: a failed lookup just leaves that entry's
		 * resolved reference unset.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/eportfolio/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
		 */
		async resolveEntryReferences() {
			const resolved = {}
			for (const entry of this.entries) {
				const title = await this.referenceTitle(entry)
				if (title !== undefined) {
					resolved[entry.id] = title
				}
			}
			this.resolvedReferences = resolved
		},

		/**
		 * The label of the record one entry refers to; undefined for an entry
		 * that refers to nothing, or whose record could not be read.
		 *
		 * @param {object} entry A PortfolioEntry.
		 * @return {Promise<string|undefined>} The label.
		 * @spec openspec/specs/assignments/spec.md#scenario-a-reviewer-completes-an-assigned-peerreview
		 */
		async referenceTitle(entry) {
			const mapping = REFERENCE_SCHEMAS[entry.evidenceKind]
			const refId = mapping ? entry[mapping.idField] : undefined
			if (!refId) {
				return undefined
			}
			try {
				return referencedTitle(await this.fetchObject(mapping.schema, refId), refId)
			} catch (err) {
				// eslint-disable-next-line no-console
				console.error('[PortfolioReviewView] resolveEntryReferences error', err)
				return undefined
			}
		},

		/**
		 * Human-readable label for an evidenceKind value.
		 *
		 * @param {string} kind evidenceKind value.
		 * @return {string}
		 * @spec exclude Presentation-only label map from the 6 fixed evidenceKind values to their localized display names; the per-kind reference discipline itself is governed by requirement-portfolioentry-references-existing-evidence-objects-via-per-kind-fields-never-a-polymorphic-ref, not by this string lookup.
		 */
		evidenceKindLabel(kind) {
			return evidenceKindLabel(kind, (app, text) => this.t(app, text))
		},

		/**
		 * Save the teacher-entered gradeValue and dispatch the Portfolio
		 * `grade` lifecycle transition. PortfolioGradeEmitHandler emits the
		 * concept GradeEntry server-side once the transition lands — this
		 * view computes no grade itself.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/eportfolio/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
		 */
		async gradePortfolio() {
			if (!this.portfolio) {
				return
			}
			this.grading = true
			this.gradeError = null

			try {
				await this.saveGrade()
				this.portfolio = await this.fetchObject('Portfolio', this.id)
			} catch (err) {
				this.gradeError = this.t(
					'learniq',
					'Failed to save grade. Please try again.',
				)
				// eslint-disable-next-line no-console
				console.error('[PortfolioReviewView] gradePortfolio error', err)
			} finally {
				this.grading = false
			}
		},

		/**
		 * Save the grade on the Portfolio and dispatch its `grade` transition.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/assignments/spec.md#scenario-a-reviewer-completes-an-assigned-peerreview
		 */
		async saveGrade() {
			await sendJsonOrThrow(
				generateUrl(`/apps/openregister/api/objects/learniq/Portfolio/${this.id}`),
				{
					method: 'PATCH',
					body: { gradeValue: Number(this.gradeValue) },
					failure: 'Portfolio update failed',
				},
			)
			await sendJsonOrThrow(generateUrl(objectTransitionUrl(this.id)), {
				method: 'POST',
				body: { action: 'grade' },
				failure: 'Portfolio grade transition failed',
			})
		},
	},
}
</script>

<style scoped>
.portfolio-review-view__loading,
.portfolio-review-view__error {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.portfolio-review-view__header {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}

.portfolio-review-view__meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.portfolio-review-view__lifecycle {
	margin-left: var(--default-grid-baseline, 8px);
}

.portfolio-review-view__entry-list {
	list-style: none;
	padding: 0;
}

.portfolio-review-view__entry-item {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: calc(var(--default-grid-baseline, 8px)) 0;
	border-bottom: 1px solid var(--color-border);
}

.portfolio-review-view__entry-kind {
	font-weight: bold;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.portfolio-review-view__entry-reflection,
.portfolio-review-view__entry-file,
.portfolio-review-view__entry-resolved {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.portfolio-review-view__no-entries {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.portfolio-review-view__field-label {
	display: block;
	margin-bottom: 4px;
}

.portfolio-review-view__grade-input {
	width: 120px;
	padding: 4px 8px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
}

.portfolio-review-view__actions {
	display: flex;
	justify-content: flex-end;
	margin-top: calc(var(--default-grid-baseline, 8px) * 2);
}

.portfolio-review-view__grade-error {
	color: var(--color-error);
	font-size: 0.9em;
	margin-top: var(--default-grid-baseline, 8px);
	text-align: right;
}

.portfolio-review-view__graded-confirmation {
	color: var(--color-success-text, var(--color-success));
	font-weight: bold;
}
</style>
