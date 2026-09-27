// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Which lesson completions belong to which enrolment (learniq#945).
 *
 * A learner who takes a course again (a retake, a re-enrolment, a yearly
 * recertification) must start without the completions of the earlier
 * enrolment. These helpers mirror EnrolmentProgressEvaluator::belongsToEnrolment()
 * so the lesson player and the progress roll-up agree.
 *
 * @spec openspec/specs/progress-tracking/spec.md#requirement-a-lesson-completion-belongs-to-one-enrolment
 */

/**
 * The learner's current enrolment among theirs for one course: the most
 * recently created active one, otherwise the most recent pending one.
 *
 * @param {object[]} enrolments The learner's Enrolments for the course.
 * @return {object|null} The enrolment, or null.
 * @spec openspec/specs/progress-tracking/spec.md#requirement-a-lesson-completion-belongs-to-one-enrolment
 */
export function currentEnrolment(enrolments) {
	const created = (e) => Date.parse(e?.['@self']?.created ?? e?.created ?? '') || 0
	for (const state of ['active', 'pending']) {
		const candidates = (enrolments ?? [])
			.filter((e) => e?.lifecycle === state)
			.sort((a, b) => created(b) - created(a))
		if (candidates.length > 0) return candidates[0]
	}
	return null
}

/**
 * Whether a LessonCompletion counts for an enrolment: tied to it by id, or
 * untied and completed after the enrolment was created.
 *
 * @param {object} completion The LessonCompletion.
 * @param {object|null} enrolment The Enrolment, or null.
 * @return {boolean} Whether it counts.
 * @spec openspec/specs/progress-tracking/spec.md#requirement-a-lesson-completion-belongs-to-one-enrolment
 */
export function completionBelongsTo(completion, enrolment) {
	if (!enrolment) return !completion?.enrolmentId
	const enrolmentId = String(enrolment.id ?? enrolment.uuid ?? '')
	const tiedTo = String(completion?.enrolmentId ?? '')
	if (tiedTo !== '') return enrolmentId !== '' && tiedTo === enrolmentId
	const started = Date.parse(
		enrolment['@self']?.created ?? enrolment.created ?? '',
	)
	const completedAt = Date.parse(completion?.completedAt ?? '')
	if (Number.isNaN(started) || Number.isNaN(completedAt)) return false
	return completedAt >= started
}
