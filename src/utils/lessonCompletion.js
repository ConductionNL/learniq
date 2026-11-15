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
 * The creation stamp of an enrolment, '' when it has none.
 *
 * @param {object} enrolment An Enrolment.
 * @return {string} The stamp.
 * @spec openspec/specs/progress-tracking/spec.md#requirement-a-lesson-completion-belongs-to-one-enrolment
 */
function createdStamp(enrolment) {
	return enrolment?.['@self']?.created ?? enrolment?.created ?? ''
}

/**
 * The learner's current enrolment among theirs for one course: the most
 * recently created active one, otherwise the most recent pending one.
 *
 * @param {object[]} enrolments The learner's Enrolments for the course.
 * @return {object|null} The enrolment, or null.
 * @spec openspec/specs/progress-tracking/spec.md#requirement-a-lesson-completion-belongs-to-one-enrolment
 */
export function currentEnrolment(enrolments) {
	const created = (e) => Date.parse(createdStamp(e)) || 0
	for (const state of ['active', 'pending']) {
		const candidates = (enrolments ?? [])
			.filter((e) => e?.lifecycle === state)
			.sort((a, b) => created(b) - created(a))
		if (candidates.length > 0) return candidates[0]
	}
	return null
}

/**
 * Whether a completion tied to an enrolment id is tied to this enrolment.
 *
 * @param {string} tiedTo The completion's enrolment id.
 * @param {object} enrolment The Enrolment.
 * @return {boolean} True when the ids match.
 * @spec openspec/specs/progress-tracking/spec.md#requirement-a-lesson-completion-belongs-to-one-enrolment
 */
function tiedToEnrolment(tiedTo, enrolment) {
	const enrolmentId = String(enrolment.id ?? enrolment.uuid ?? '')
	return enrolmentId !== '' && tiedTo === enrolmentId
}

/**
 * When an enrolment was created, NaN when unreadable.
 *
 * @param {object} enrolment The Enrolment.
 * @return {number} The time.
 * @spec openspec/specs/progress-tracking/spec.md#requirement-a-lesson-completion-belongs-to-one-enrolment
 */
function enrolmentStart(enrolment) {
	return Date.parse(enrolment['@self']?.created ?? enrolment.created ?? '')
}

/**
 * Whether an untied completion was completed after the enrolment was
 * created; never when either date is unreadable.
 *
 * @param {object} completion The LessonCompletion.
 * @param {object} enrolment The Enrolment.
 * @return {boolean} True when it came after.
 * @spec openspec/specs/progress-tracking/spec.md#requirement-a-lesson-completion-belongs-to-one-enrolment
 */
function completedAfterStart(completion, enrolment) {
	const started = enrolmentStart(enrolment)
	const completedAt = Date.parse(completion?.completedAt ?? '')
	if (Number.isNaN(started) || Number.isNaN(completedAt)) return false
	return completedAt >= started
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
	const tiedTo = String(completion?.enrolmentId ?? '')
	if (tiedTo !== '') return tiedToEnrolment(tiedTo, enrolment)
	return completedAfterStart(completion, enrolment)
}
