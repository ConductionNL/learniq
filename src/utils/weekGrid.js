// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The week grid of My timetable (week-timetable-grid T2): a week's sessions as
 * the `rows` and `items` of nextcloud-vue's CnWeekGrid, the days as columns
 * and the lesson hours as rows.
 *
 * The lesson hours are the distinct start times of the week's sessions, in
 * order: the first is the 1st hour, and so on. A school's lessons start on its
 * bell times, so the hours a teacher teaches line up across the week; a
 * training provider's start times read as their clock time. A session sits in
 * the row of its start time and the column of its date.
 *
 * Each session carries its change as a pill in words: cancelled, a substitute
 * (cover), or, for a lesson the roosterkamer moved (`movedAt`), the room it is
 * in now. The grid fetches nothing; My timetable passes the week it loaded.
 *
 * @spec openspec/changes/week-timetable-grid/specs/week-timetable-grid/spec.md#requirement-a-teacher-reads-her-week-as-day-columns-and-lesson-hours
 */

/**
 * A two-digit clock time of an ISO timestamp, in local time.
 *
 * @param {string} iso The timestamp.
 * @return {string} `HH:MM`, or '' when the value is no timestamp.
 */
export function clockOf(iso) {
	if (typeof iso !== 'string' || iso === '') {
		return ''
	}
	const ts = Date.parse(iso)
	if (Number.isNaN(ts)) {
		return ''
	}
	const date = new Date(ts)
	return String(date.getHours()).padStart(2, '0') + ':' + String(date.getMinutes()).padStart(2, '0')
}

/**
 * The ISO 8601 week number of a date.
 *
 * @param {Date} date Any day of the week.
 * @return {number} The week number.
 */
export function isoWeek(date) {
	const thursday = new Date(date.getFullYear(), date.getMonth(), date.getDate() + 3 - ((date.getDay() + 6) % 7))
	const jan4 = new Date(thursday.getFullYear(), 0, 4)
	const week1Thursday = new Date(jan4.getFullYear(), 0, 4 + 3 - ((jan4.getDay() + 6) % 7))
	return 1 + Math.round((thursday - week1Thursday) / (7 * 86400000))
}

/**
 * The local `YYYY-MM-DD` of an ISO timestamp.
 *
 * @param {string} iso The timestamp.
 * @return {string} The day, or '' when the value is no timestamp.
 */
export function dayOf(iso) {
	const ts = typeof iso === 'string' ? Date.parse(iso) : Number.NaN
	if (Number.isNaN(ts)) {
		return ''
	}
	const date = new Date(ts)
	return [
		date.getFullYear(),
		String(date.getMonth() + 1).padStart(2, '0'),
		String(date.getDate()).padStart(2, '0'),
	].join('-')
}

/**
 * The lesson hours of a week: one row per distinct start time, earliest first.
 *
 * @param {Array<object>} sessions The week's sessions.
 * @param {(text: string, vars?: object) => string} translate Translates `{n}th hour` style labels.
 * @return {Array<{key: string, label: string, start: string, end: string}>} The rows.
 */
export function lessonHourRows(sessions, translate) {
	const ends = new Map()
	for (const session of Array.isArray(sessions) ? sessions : []) {
		const start = clockOf(session?.startsAt)
		if (start === '') {
			continue
		}
		const end = clockOf(session?.endsAt)
		if (ends.has(start) === false || (end !== '' && (ends.get(start) === '' || end < ends.get(start)))) {
			ends.set(start, end)
		}
	}
	return [...ends.keys()].sort().map((start, index) => ({
		key: start,
		label: translate('Hour {n}', { n: index + 1 }),
		start,
		end: ends.get(start),
	}))
}

/**
 * The change pill of a session, in words, or null for an unchanged one.
 *
 * @param {object} session The session.
 * @param {(text: string, vars?: object) => string} translate The translate function.
 * @return {{label: string, variant: string}|null} The pill.
 */
export function changePill(session, translate) {
	if (session?.lifecycle === 'cancelled') {
		return { label: translate('Cancelled'), variant: 'cancelled' }
	}
	if (session?.cover === true || (typeof session?.substituteTeacherId === 'string' && session.substituteTeacherId !== '')) {
		return { label: translate('Cover'), variant: 'substitute' }
	}
	const room = roomName(session)
	if (typeof session?.movedAt === 'string' && session.movedAt !== '' && room !== '') {
		return { label: translate('Room {room}', { room }), variant: 'changed' }
	}
	return null
}

/**
 * The room a session is in: the Room's name, else its free-text location
 * unless that is an online link.
 *
 * @param {object} session The session.
 * @return {string} The room, or ''.
 */
export function roomName(session) {
	if (session?.room && typeof session.room.name === 'string' && session.room.name !== '') {
		return session.room.name
	}
	const location = typeof session?.location === 'string' ? session.location : ''
	return /^https?:\/\//i.test(location) ? '' : location
}

/**
 * The week's sessions as CnWeekGrid entries, in the row of their start time.
 *
 * @param {Array<object>} sessions The week's sessions.
 * @param {(text: string, vars?: object) => string} translate The translate function.
 * @return {Array<object>} The entries; `session` is the session itself.
 */
export function weekGridItems(sessions, translate) {
	const items = []
	for (const session of Array.isArray(sessions) ? sessions : []) {
		const start = clockOf(session?.startsAt)
		const date = dayOf(session?.startsAt)
		if (start === '' || date === '') {
			continue
		}
		const meta = [session.cohortName || session.cohort?.name || '', roomName(session)]
		items.push({
			key: String(session.id ?? `${date}|${start}|${session.title ?? ''}`),
			date,
			row: start,
			title: session.title || translate('Untitled session'),
			meta: meta.filter((part) => typeof part === 'string' && part !== ''),
			pill: changePill(session, translate),
			session,
		})
	}
	return items
}
