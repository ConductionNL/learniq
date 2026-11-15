/**
 * The roll-call page's rules that need no component (attendance-roll-call):
 * which marks a save sends, which marks are incomplete, the counts above the
 * list, and how the teacher dashboard's "Sessions to mark" leads to the page.
 *
 * Plain ES module (not a .vue SFC) so it is directly importable from a Node
 * test runner without an SFC compile step.
 *
 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/** The quick choices for minutes late. */
export const QUICK_LATE_MINUTES = [5, 10, 15]

/** The reasons for an absence, as AttendanceRecord.absenceReasonKind. */
export const ABSENCE_REASON_KINDS = ['illness', 'appointment', 'other']

/** The marks a teacher picks from, in the order of the buttons. */
export const ROLL_CALL_STATUSES = [
	'present',
	'late',
	'absent-excused',
	'absent-unexcused',
]

/** The route of the roll-call page. */
export const ROLL_CALL_PATH = '/attendance/roll-call'

/** Upper bound on minutes late, as the server checks it. */
export const MAX_LATE_MINUTES = 600

/**
 * Give a pupil a mark, keeping what belongs to it and clearing the rest.
 * Late starts at the first quick choice; an absence with permission starts
 * on illness unless it already had a reason.
 *
 * @param {object} pupil A pupil row from the server.
 * @param {string} status The new status.
 * @return {object} The changed row (a new object).
 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
 */
export function withStatus(pupil, status) {
	const next = { ...pupil, status }
	next.lateMinutes = lateMinutesFor(pupil, status)
	if (status === 'absent-excused') {
		next.absenceReasonKind = pupil.absenceReasonKind || 'illness'
	} else if (status !== 'absent-unexcused') {
		next.absenceReasonKind = null
	}
	return next
}

/**
 * The minutes late a pupil gets with a new status: kept or the first quick
 * choice when late, else none.
 *
 * @param {object} pupil A pupil row.
 * @param {string} status The new status.
 * @return {number|null}
 */
function lateMinutesFor(pupil, status) {
	return status === 'late' ? pupil.lateMinutes || QUICK_LATE_MINUTES[0] : null
}

/**
 * Whether a minutes value is a whole number of minutes late the server takes.
 *
 * @param {string|number|null} value The value.
 * @return {boolean}
 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#scenario-a-late-mark-without-minutes-is-refused
 */
export function validLateMinutes(value) {
	const minutes = Number(value)
	return Number.isInteger(minutes) && minutes >= 1 && minutes <= MAX_LATE_MINUTES
}

/**
 * The pupils whose mark cannot be saved yet: late without valid minutes, or
 * absent with permission without a reason.
 *
 * @param {object[]} pupils Pupil rows.
 * @return {object[]} The incomplete rows.
 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#scenario-a-late-mark-without-minutes-is-refused
 */
export function incompleteMarks(pupils) {
	return pupils.filter(
		(p) =>
			(p.status === 'late' && !validLateMinutes(p.lateMinutes))
			|| (p.status === 'absent-excused'
				&& !ABSENCE_REASON_KINDS.includes(p.absenceReasonKind)),
	)
}

/**
 * The marks a save sends: one per pupil, only the fields the server reads.
 *
 * @param {object[]} pupils Pupil rows.
 * @return {object[]} The marks.
 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
 */
export function marksToSave(pupils) {
	return pupils.map(markToSave)
}

/**
 * The mark a save sends for one pupil.
 *
 * @param {object} p A pupil row.
 * @return {object} The mark.
 */
function markToSave(p) {
	const mark = { learnerId: p.learnerId, status: p.status }
	if (p.status === 'late') mark.lateMinutes = Number(p.lateMinutes)
	if (isAbsence(p.status)) {
		mark.absenceReasonKind = p.absenceReasonKind || null
	}
	const reason = trimmedReason(p)
	if (reason) mark.reason = reason
	return mark
}

/**
 * Whether a status is an absence, with or without permission.
 *
 * @param {string} status A mark status.
 * @return {boolean}
 */
function isAbsence(status) {
	return status === 'absent-excused' || status === 'absent-unexcused'
}

/**
 * A pupil row's typed reason, trimmed; '' when there is none.
 *
 * @param {object} p A pupil row.
 * @return {string}
 */
function trimmedReason(p) {
	return (p.reason || '').trim()
}

/**
 * The counts shown above the list.
 *
 * @param {object[]} pupils Pupil rows.
 * @return {{present: number, late: number, absentAuthorised: number, absentUnauthorised: number}}
 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
 */
export function rollCallCounts(pupils) {
	const counts = {
		present: 0,
		late: 0,
		absentAuthorised: 0,
		absentUnauthorised: 0,
	}
	for (const p of pupils) {
		if (p.status === 'late') counts.late++
		else if (p.status === 'absent-excused') counts.absentAuthorised++
		else if (p.status === 'absent-unexcused') counts.absentUnauthorised++
		else counts.present++
	}
	return counts
}

/**
 * The date of a timestamp in the browser's time zone, `YYYY-MM-DD`.
 *
 * @param {string|Date} value An ISO date-time or a Date.
 * @return {string} The date, or '' when it is not one.
 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-the-teacher-reaches-the-register-from-the-menu-and-the-dashboard
 */
export function localDate(value) {
	const date = value instanceof Date ? value : new Date(value)
	if (Number.isNaN(date.getTime())) return ''
	const pad = (n) => String(n).padStart(2, '0')
	return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

/**
 * The list filter of the teacher dashboard's "Sessions to mark": the lessons
 * of the scope that started today or earlier, newest first.
 *
 * @param {object} scopeFilter The scope filter (e.g. `{ cohortId: [...] }`), or `{}`.
 * @param {Date} now The current moment.
 * @return {object} The filter, in the flat key shape OpenRegister reads.
 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-the-teacher-reaches-the-register-from-the-menu-and-the-dashboard
 */
export function sessionsToMarkFilter(scopeFilter, now) {
	const endOfToday = new Date(now)
	endOfToday.setHours(23, 59, 59, 999)
	return {
		...(scopeFilter || {}),
		'startsAt[lte]': endOfToday.toISOString(),
		'_order[startsAt]': 'desc',
	}
}

/**
 * The roll-call route for a lesson: its group and its day.
 *
 * @param {object} session A Session row.
 * @return {{path: string, query: object}} A vue-router location.
 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-the-teacher-reaches-the-register-from-the-menu-and-the-dashboard
 */
export function rollCallRoute(session) {
	const query = {}
	if (session?.cohortId) query.cohortId = session.cohortId
	const date = sessionDate(session)
	if (date) query.date = date
	const id = sessionIdOf(session)
	if (id) query.sessionId = id
	return { path: ROLL_CALL_PATH, query }
}

/**
 * The local day a session starts on, '' when it has no readable start.
 *
 * @param {object|null} session A Session row.
 * @return {string}
 */
function sessionDate(session) {
	return session?.startsAt ? localDate(session.startsAt) : ''
}

/**
 * A session's id, whichever key OpenRegister returned it under.
 *
 * @param {object|null} session A Session row.
 * @return {string|undefined}
 */
function sessionIdOf(session) {
	return session?.id || session?.uuid || session?.['@self']?.id
}
