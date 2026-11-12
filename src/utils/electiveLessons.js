// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * What the optional-lesson screens (a learner's MyElectives and the
 * coordinator's ElectiveRosterView) show and send alike: a lesson's date and
 * time, which lesson a sign-up or placement names, and the server's reason
 * for a refusal.
 *
 * Pure: the translator is passed in, so the module runs under `node --test`
 * with nothing mocked.
 *
 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-signs-up-for-an-optional-lesson-inside-the-window
 * @spec openspec/specs/enrolment/spec.md#requirement-a-coordinator-places-learners-who-missed-the-window
 */

/**
 * A readable date and time, or '' when there is none.
 *
 * @param {string} iso A date-time.
 * @return {string}
 */
export function lessonDateTime(iso) {
	const date = new Date(iso)
	if (!iso || Number.isNaN(date.getTime())) return ''
	return date.toLocaleString(undefined, {
		weekday: 'short',
		day: 'numeric',
		month: 'short',
		hour: '2-digit',
		minute: '2-digit',
	})
}

/**
 * The lesson a sign-up or placement names: its session, else its timetable
 * reference.
 *
 * @param {{sessionId?: string, timetableSessionRef?: string}} lesson The lesson.
 * @return {object}
 */
export function lessonReference(lesson) {
	return lesson.sessionId
		? { sessionId: lesson.sessionId }
		: { timetableSessionRef: lesson.timetableSessionRef }
}

/**
 * The server's reason for a refusal, or a general one.
 *
 * @param {Error} e The failure.
 * @param {function(string, string): string} t Translates.
 * @return {string}
 */
export function refusalReason(e, t) {
	return (
		e?.response?.data?.error
		|| t('learniq', 'Something went wrong. Try again.')
	)
}
