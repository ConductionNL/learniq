<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 AssessmentScoringView: score open answers one question at a time (learniq#948).

 A teacher picks an open question (an essay, or any item without a correct
 response) and scores every submitted attempt's answer to it, then moves to
 the next question. Scores are written as `responses[].manualScore` with a
 PATCH on the AssessmentResult; AssessmentResultIntegrityListener allows that
 one write on a submitted attempt and keeps the answers frozen. Attempts whose
 open questions are all scored can then be graded through Open Register's
 transition endpoint, which runs AssessmentGradeGuard.

 A custom view because no manifest page can show one question across many
 attempts. Reached from the results list of an assessment.

 @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
-->
<template>
	<div class="assessment-scoring">
		<h2>{{ t('learniq', 'Score open answers') }}</h2>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcNoteCard v-else-if="loadError" type="error">
			{{ loadError }}
		</NcNoteCard>

		<NcEmptyContent
			v-else-if="items.length === 0"
			:name="t('learniq', 'No open questions')"
			:description="
				t(
					'learniq',
					'Every question in this assessment is scored automatically.',
				)
			" />

		<NcEmptyContent
			v-else-if="results.length === 0"
			:name="t('learniq', 'Nothing to score')"
			:description="
				t('learniq', 'No submitted attempts are waiting for a score.')
			" />

		<template v-else>
			<div class="assessment-scoring__question">
				<NcButton
					variant="tertiary"
					:disabled="index === 0"
					@click="index -= 1">
					{{ t('learniq', 'Previous question') }}
				</NcButton>
				<h3>
					{{
						t('learniq', 'Question {n} of {total}: {title}', {
							n: index + 1,
							total: items.length,
							title: current.title,
						})
					}}
				</h3>
				<NcButton
					variant="tertiary"
					:disabled="index === items.length - 1"
					@click="index += 1">
					{{ t('learniq', 'Next question') }}
				</NcButton>
			</div>
			<p v-if="current.max !== null" class="assessment-scoring__max">
				{{ t('learniq', 'Maximum score: {max}', { max: current.max }) }}
			</p>

			<table class="assessment-scoring__table">
				<thead>
					<tr>
						<th scope="col">{{ t('learniq', 'Learner') }}</th>
						<th scope="col">{{ t('learniq', 'Answer') }}</th>
						<th scope="col">{{ t('learniq', 'Score') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="result in results" :key="result.id">
						<td>{{ result.learnerId }}</td>
						<td class="assessment-scoring__answer">
							{{ answerText(result) }}
						</td>
						<td>
							<input
								v-model="drafts[result.id][current.itemId]"
								type="number"
								min="0"
								:max="current.max === null ? undefined : current.max"
								step="any"
								:aria-label="
									t('learniq', 'Score for {learner}', {
										learner: result.learnerId,
									})
								"
								:aria-invalid="rowError(result) !== ''" />
							<p
								v-if="rowError(result)"
								class="assessment-scoring__error">
								{{ rowError(result) }}
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<NcNoteCard v-if="message" :type="messageType">
				{{ message }}
			</NcNoteCard>

			<div class="assessment-scoring__actions">
				<NcButton
					variant="primary"
					:disabled="saving || hasErrors"
					@click="saveScores">
					{{ t('learniq', 'Save scores') }}
				</NcButton>
				<NcButton
					variant="secondary"
					:disabled="saving || readyToGrade.length === 0"
					@click="gradeReady">
					{{
						n(
							'learniq',
							'Grade %n finished attempt',
							'Grade %n finished attempts',
							readyToGrade.length,
						)
					}}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import {
	applyScore,
	isFullyScored,
	manualItems,
	responseFor,
	resultUrl,
	scoreError,
	transitionUrl,
} from '../utils/manualScoring.js'

const OBJECTS = '/apps/openregister/api/objects/learniq'

/**
 * Read an object out of an Open Register response.
 *
 * @param {object} response The axios response.
 * @return {object} The object.
 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
 */
function objectOf(response) {
	const data = response?.data || {}
	return data.object ?? data
}

export default {
	name: 'AssessmentScoringView',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
	},

	data() {
		return {
			loading: true,
			loadError: '',
			items: [],
			results: [],
			drafts: {},
			index: 0,
			saving: false,
			message: '',
			messageType: 'success',
		}
	},

	computed: {
		/**
		 * The question being scored.
		 *
		 * @return {{itemId: string, title: string, max: (number|null)}} The item.
		 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
		 */
		current() {
			return this.items[this.index]
		},

		/**
		 * Whether any typed score on this question is invalid.
		 *
		 * @return {boolean} True when saving must wait.
		 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
		 */
		hasErrors() {
			return this.results.some((result) => this.rowError(result) !== '')
		},

		/**
		 * Attempts whose open questions all carry a saved score.
		 *
		 * @return {object[]} The attempts ready for `grade`.
		 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
		 */
		readyToGrade() {
			const ids = this.items.map((item) => item.itemId)
			return this.results.filter((result) => isFullyScored(result, ids))
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Load the assessment, its open questions and the submitted attempts.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
		 */
		async load() {
			const assessmentId = this.$route?.params?.assessmentId
			this.loading = true
			try {
				const assessment = objectOf(
					await axios.get(
						generateUrl(
							`${OBJECTS}/exam/${encodeURIComponent(assessmentId)}`,
						),
					),
				)
				const itemIds = [
					...new Set((assessment.itemRefs || []).map((ref) => ref.itemId)),
				].filter(Boolean)
				const loaded = await Promise.all(
					itemIds.map((id) =>
						axios
							.get(
								generateUrl(
									`${OBJECTS}/item/${encodeURIComponent(id)}`,
								),
							)
							.then((response) => [id, objectOf(response)])
							.catch(() => [id, null]),
					),
				)
				const itemsById = Object.fromEntries(
					loaded.filter(([, item]) => item !== null),
				)
				this.items = manualItems(assessment, itemsById)

				const response = await axios.get(
					generateUrl(`${OBJECTS}/assessment-result`),
					{ params: { assessmentId, _limit: 500 } },
				)
				const rows = response.data?.results || response.data?.objects || []
				this.results = rows
					.map((r) => ({ ...r, id: r.id || r.uuid }))
					.filter((r) => r.lifecycle === 'submitted')
				this.drafts = Object.fromEntries(
					this.results.map((result) => [
						result.id,
						this.draftsFor(result),
					]),
				)
			} catch {
				this.loadError = this.t(
					'learniq',
					'The attempts could not be loaded.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * The typed-score state of one attempt, seeded from saved scores.
		 *
		 * @param {object} result The AssessmentResult.
		 * @return {{[key: string]: string}} Score text per open item.
		 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
		 */
		draftsFor(result) {
			return Object.fromEntries(
				this.items.map((item) => {
					const saved = responseFor(result, item.itemId)?.manualScore
					return [
						item.itemId,
						saved === null || saved === undefined ? '' : String(saved),
					]
				}),
			)
		},

		/**
		 * Show what the learner answered to the current question.
		 *
		 * @param {object} result The AssessmentResult.
		 * @return {string} The answer text.
		 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
		 */
		answerText(result) {
			const value = responseFor(result, this.current.itemId)?.response?.value
			if (value === null || value === undefined || value === '') {
				return this.t('learniq', 'No answer')
			}
			return typeof value === 'string' ? value : JSON.stringify(value)
		},

		/**
		 * The validation message for one row of the current question.
		 *
		 * @param {object} result The AssessmentResult.
		 * @return {string} The message, or ''.
		 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
		 */
		rowError(result) {
			const code = scoreError(
				this.drafts[result.id]?.[this.current.itemId],
				this.current.max,
			)
			if (code === 'above-max') {
				return this.t('learniq', 'The score is higher than the maximum.')
			}
			if (code !== '') {
				return this.t('learniq', 'Enter a number of zero or more.')
			}
			return ''
		},

		/**
		 * Save the changed scores of the current question, one PATCH per attempt.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
		 */
		async saveScores() {
			const itemId = this.current.itemId
			const changed = this.results.filter((result) => {
				const typed = this.drafts[result.id][itemId]
				const score = typed === '' ? null : Number(typed)
				return score !== (responseFor(result, itemId)?.manualScore ?? null)
			})
			this.saving = true
			let failed = 0
			for (const result of changed) {
				const typed = this.drafts[result.id][itemId]
				const responses = applyScore(
					result,
					itemId,
					typed === '' ? null : Number(typed),
				)
				try {
					await axios.patch(generateUrl(resultUrl(result.id)), {
						responses,
					})
					result.responses = responses
				} catch {
					failed += 1
				}
			}
			this.saving = false
			this.report(
				failed,
				this.n(
					'learniq',
					'%n score saved.',
					'%n scores saved.',
					changed.length - failed,
				),
				this.t('learniq', 'Some scores could not be saved. Try again.'),
			)
		},

		/**
		 * Fire `grade` on every attempt that is fully scored.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
		 */
		async gradeReady() {
			const ready = [...this.readyToGrade]
			this.saving = true
			const graded = new Set()
			for (const result of ready) {
				try {
					await axios.post(generateUrl(transitionUrl(result.id)), {
						action: 'grade',
					})
					graded.add(result.id)
				} catch {
					// Reported below; the attempt stays in the list.
				}
			}
			this.results = this.results.filter((result) => !graded.has(result.id))
			this.saving = false
			this.report(
				ready.length - graded.size,
				this.n(
					'learniq',
					'%n attempt graded.',
					'%n attempts graded.',
					graded.size,
				),
				this.t('learniq', 'Some attempts could not be graded. Try again.'),
			)
		},

		/**
		 * Show the outcome of a save or grade run.
		 *
		 * @param {number} failed How many writes failed.
		 * @param {string} success The message when none failed.
		 * @param {string} failure The message when some failed.
		 * @return {void}
		 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
		 */
		report(failed, success, failure) {
			this.messageType = failed > 0 ? 'error' : 'success'
			this.message = failed > 0 ? failure : success
		},
	},
}
</script>

<style scoped>
.assessment-scoring {
	padding: 1rem;
}

.assessment-scoring__question {
	display: flex;
	gap: 0.5rem;
	align-items: center;
	flex-wrap: wrap;
}

.assessment-scoring__table {
	width: 100%;
	border-collapse: collapse;
	margin-top: 1rem;
}

.assessment-scoring__table th,
.assessment-scoring__table td {
	padding: 0.5rem;
	text-align: start;
	vertical-align: top;
	border-bottom: 1px solid var(--color-border);
}

.assessment-scoring__answer {
	white-space: pre-wrap;
}

.assessment-scoring__error {
	color: var(--color-error-text);
}

.assessment-scoring__actions {
	display: flex;
	gap: 0.5rem;
	margin-top: 1rem;
}
</style>
