// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Double marking helpers for MarkSubmissionView (assignments-double-marking).
// Pure functions, so tests/unit-js can import them without an SFC compile.

/**
 * The marking mode for the current user.
 *
 * - `single`: one teacher marks, as before double marking existed.
 * - `marker`: the user is an allocated marker whose own mark is a draft.
 * - `waiting`: the user's own mark is handed in, other marks are not.
 * - `final`: every mark is in and the user may set the final grade.
 * - `not-allocated`: double marking is on and the user is neither a marker
 *   nor someone who reads every mark.
 *
 * @param {object} assignment The Assignment (markersPerSubmission).
 * @param {object} submission The Submission (markerIds).
 * @param {object|null} marks The GET /api/submissions/{id}/marks answer, or null when it was refused.
 * @return {string}
 * @spec openspec/specs/assignments/spec.md#requirement-each-marker-scores-in-their-own-submissionmark
 */
export function markingMode(assignment, submission, marks) {
	const markers = Number(assignment?.markersPerSubmission ?? 1)
	const allocated = Array.isArray(submission?.markerIds)
		? submission.markerIds
		: []
	if (markers < 2 || allocated.length === 0) {
		return 'single'
	}
	if (!marks) {
		return 'not-allocated'
	}
	if (marks.complete) {
		return 'final'
	}
	const own = marks.ownMark
	if (own && own.lifecycle === 'draft') {
		return 'marker'
	}
	return 'waiting'
}

/**
 * The value the final grade field starts at.
 *
 * @param {string} rule `manual`, `average` or `highest`.
 * @param {object|null} summary `{ average, highest }` from the marks answer.
 * @return {number|null} Null for `manual`: a person types the grade.
 * @spec openspec/specs/assignments/spec.md#scenario-the-average-rule-proposes-a-grade-and-waits-for-a-person
 */
export function prefillFinalGrade(rule, summary) {
	if (!summary) {
		return null
	}
	if (rule === 'average' && typeof summary.average === 'number') {
		return Math.round(summary.average * 100) / 100
	}
	if (rule === 'highest' && typeof summary.highest === 'number') {
		return summary.highest
	}
	return null
}
