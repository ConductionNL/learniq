// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Pure helpers for the manual scoring screen (learniq#948).
 *
 * AssessmentScoringView lets a teacher score one open question for every
 * submitted attempt in turn. Which items need a human, and when an attempt is
 * ready to grade, mirror lib/Lifecycle/AssessmentGradeGuard.php, so the screen
 * never offers a grade the guard would refuse. Kept free of Vue so it runs
 * under `node --test`.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
 */

/**
 * The Open Register object URL of one AssessmentResult (GET and PATCH).
 *
 * @param {string} id AssessmentResult UUID.
 * @return {string} The app-relative URL.
 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
 */
export function resultUrl(id) {
	return `/apps/openregister/api/objects/learniq/assessment-result/${encodeURIComponent(id)}`
}

/**
 * The Open Register transition endpoint; the action goes in the body.
 *
 * @param {string} id Object UUID.
 * @return {string} The app-relative URL.
 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
 */
export function transitionUrl(id) {
	return `/apps/openregister/api/objects/${encodeURIComponent(id)}/transition`
}

/**
 * Whether an item can only be scored by a person, as AssessmentGradeGuard decides it.
 *
 * @param {object} item The Item object.
 * @return {boolean} True for an essay or an item without a correct response.
 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
 */
export function needsManualScoring(item) {
	if (item?.interactionType === 'extendedText') {
		return true
	}
	return item?.correctResponse === null || item?.correctResponse === undefined
}

/**
 * The items of an assessment that need a manual score, in assessment order.
 *
 * @param {object} assessment The Assessment (exam) object with itemRefs.
 * @param {Object<string, object>} itemsById The loaded Items keyed by UUID.
 * @return {Array<{itemId: string, title: string, max: (number|null)}>} The items to score.
 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
 */
export function manualItems(assessment, itemsById) {
	return (assessment?.itemRefs || [])
		.filter((ref) => ref?.itemId && itemsById[ref.itemId])
		.filter((ref) => needsManualScoring(itemsById[ref.itemId]))
		.map((ref) => {
			const item = itemsById[ref.itemId]
			const max = ref.points ?? item.maxScore ?? null
			return {
				itemId: ref.itemId,
				title: item.title || ref.itemId,
				max: typeof max === 'number' ? max : null,
			}
		})
}

/**
 * The learner's response to one item.
 *
 * @param {object} result The AssessmentResult.
 * @param {string} itemId Item UUID.
 * @return {object|null} The response entry, or null when not answered.
 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
 */
export function responseFor(result, itemId) {
	return (result?.responses || []).find((r) => r?.itemId === itemId) || null
}

/**
 * The responses array with one manual score set, everything else untouched.
 *
 * @param {object} result The AssessmentResult.
 * @param {string} itemId Item UUID.
 * @param {number|null} score The manual score.
 * @return {object[]} A new responses array for the PATCH.
 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
 */
export function applyScore(result, itemId, score) {
	return (result?.responses || []).map((r) =>
		r?.itemId === itemId ? { ...r, manualScore: score } : r,
	)
}

/**
 * Whether every manual item carries a score, so `grade` will pass the guard.
 *
 * @param {object} result The AssessmentResult.
 * @param {string[]} manualItemIds The items that need a manual score.
 * @return {boolean} True when the attempt can be graded.
 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
 */
export function isFullyScored(result, manualItemIds) {
	return manualItemIds.every((itemId) => {
		const response = responseFor(result, itemId)
		return (
			response !== null
			&& ((response.manualScore ?? null) !== null
				|| (response.autoScore ?? null) !== null)
		)
	})
}

/**
 * Check a typed score. An empty field means not scored yet.
 *
 * @param {string|number} value The typed value.
 * @param {number|null} max The item's maximum, or null when unknown.
 * @return {string} '' when valid, else 'not-a-number', 'negative' or 'above-max'.
 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
 */
export function scoreError(value, max) {
	const text = String(value ?? '').trim()
	if (text === '') {
		return ''
	}
	const number = Number(text)
	if (!Number.isFinite(number)) {
		return 'not-a-number'
	}
	if (number < 0) {
		return 'negative'
	}
	if (typeof max === 'number' && number > max) {
		return 'above-max'
	}
	return ''
}
