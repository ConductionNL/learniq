/**
 * How the teacher dashboard's "Sessions to mark" leads to the roll-call page
 * (attendance-roll-call). The page itself is CnRowMarkList, configured in
 * manifest.d/people.json.
 *
 * Plain ES module (not a .vue SFC) so it is directly importable from a Node
 * test runner without an SFC compile step.
 *
 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/** The route of the roll-call page. */
export const ROLL_CALL_PATH = '/attendance/roll-call'

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
	const date = session?.startsAt ? localDate(session.startsAt) : ''
	if (date) query.date = date
	const id = session?.id || session?.uuid || session?.['@self']?.id
	if (id) query.sessionId = id
	return { path: ROLL_CALL_PATH, query }
}
