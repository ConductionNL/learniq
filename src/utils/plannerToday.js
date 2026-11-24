// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The planner's Today (training-provider-course-days T6): the course days
// that ask for a go/no-go decision now, what waits for confirmation, and the
// occupancy, trainers and rooms of the coming course days. Reads the answer
// of GET /api/course-days/today. Pure, tested in
// tests/unit-js/plannerToday.test.mjs.

/**
 * A Y-m-d date as a day and month in the reader's language ("22 October").
 *
 * @param {string|null} value The date.
 * @param {string} locale The BCP 47 locale.
 * @return {string} The day, or '' when the date cannot be read.
 * @spec openspec/specs/course-management/spec.md#scenario-four-of-six
 */
export function dayAndMonth(value, locale) {
	if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
		return ''
	}
	const [year, month, day] = value.split('-').map(Number)
	const date = new Date(Date.UTC(year, month - 1, day))
	if (date.getUTCDate() !== day) {
		return ''
	}
	return new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'long', timeZone: 'UTC' }).format(date)
}

/**
 * A Y-m-d date as weekday, day and month ("Thursday 8 October").
 *
 * @param {string|null} value The date.
 * @param {string} locale The BCP 47 locale.
 * @return {string} The day, or '' when the date cannot be read.
 * @spec openspec/specs/course-management/spec.md#scenario-four-of-six
 */
export function weekdayAndDate(value, locale) {
	if (dayAndMonth(value, locale) === '') {
		return ''
	}
	const [year, month, day] = value.split('-').map(Number)
	return new Intl.DateTimeFormat(locale, { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' })
		.format(new Date(Date.UTC(year, month - 1, day)))
}

/**
 * The decision cards, first today: one per course day under its minimum
 * with its decision due, with the next day of the course that has places.
 *
 * @param {object|null} answer The endpoint's answer.
 * @param {string} locale The BCP 47 locale.
 * @return {Array<{id: string, course: string, date: string, participants: number, minimum: number, decideBy: string, next: {id: string, date: string, free: number|null}|null}>} The cards.
 * @spec openspec/specs/course-management/spec.md#scenario-four-of-six
 */
export function decisionCards(answer, locale) {
	const decisions = Array.isArray(answer?.decisions) ? answer.decisions : []
	return decisions
		.filter((day) => day && typeof day.id === 'string' && day.id !== '')
		.map((day) => {
			const nextDate = dayAndMonth(day.next?.date ?? null, locale)
			return {
				id: day.id,
				course: day.courseName || day.name || '',
				date: dayAndMonth(day.date, locale),
				participants: Number(day.participants) || 0,
				minimum: Number(day.minimum) || 0,
				decideBy: weekdayAndDate(day.decideBy, locale),
				next: nextDate === ''
					? null
					: { id: day.next.id, date: nextDate, free: Number.isInteger(day.next.free) ? day.next.free : null },
			}
		})
}

/**
 * The occupancy rows: a coming course day with its places taken, its
 * maximum and minimum, and whether it is short of its minimum or of a
 * trainer or a room.
 *
 * @param {object|null} answer The endpoint's answer.
 * @param {string} locale The BCP 47 locale.
 * @return {Array<{id: string, label: string, date: string, taken: number, capacity: number|null, short: boolean, trainers: number, rooms: Array<string>, unstaffed: boolean}>} The rows, earliest first.
 * @spec openspec/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
 */
export function occupancyRows(answer, locale) {
	const days = Array.isArray(answer?.occupancy?.days) ? answer.occupancy.days : []
	return days
		.filter((day) => day && typeof day.id === 'string' && day.id !== '')
		.map((day) => {
			const taken = Number(day.participants) || 0
			const minimum = Number.isInteger(day.minimum) ? day.minimum : null
			const trainers = Array.isArray(day.trainers) ? day.trainers.length : 0
			const rooms = Array.isArray(day.rooms) ? day.rooms : []
			return {
				id: day.id,
				label: day.courseName || day.name || '',
				date: dayAndMonth(day.date, locale),
				taken,
				capacity: Number.isInteger(day.capacity) ? day.capacity : null,
				short: minimum !== null && taken < minimum && day.status !== 'goes-ahead',
				trainers,
				rooms,
				unstaffed: trainers === 0 || rooms.length === 0,
			}
		})
}

/**
 * How many bookings and enrolments wait for confirmation.
 *
 * @param {object|null} answer The endpoint's answer.
 * @return {number} The count.
 * @spec openspec/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
 */
export function toConfirmCount(answer) {
	return (Number(answer?.toConfirm?.bookings) || 0) + (Number(answer?.toConfirm?.enrolments) || 0)
}

/**
 * A dashboard declaration with one more full-width tile under the others.
 * The declaration passed in is left as it is.
 *
 * @param {{widgets: Array<object>, layout: Array<object>}} config The declaration.
 * @param {object} tile The widget.
 * @param {number} height Its grid height.
 * @return {{widgets: Array<object>, layout: Array<object>}} The new declaration.
 * @spec openspec/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
 */
export function withTileBelow(config, tile, height) {
	const layout = Array.isArray(config?.layout) ? config.layout : []
	const bottom = layout.reduce((max, item) => Math.max(max, (Number(item.gridY) || 0) + (Number(item.gridHeight) || 0)), 0)
	const nextId = layout.reduce((max, item) => Math.max(max, Number(item.id) || 0), 0) + 1
	return {
		...config,
		widgets: [...(Array.isArray(config?.widgets) ? config.widgets : []), tile],
		layout: [...layout, { id: nextId, widgetId: tile.id, gridX: 0, gridY: bottom, gridWidth: 12, gridHeight: height }],
	}
}

/**
 * This week's five working days for the planner's week strip
 * (week-timetable-grid T4): each day with its course days, their trainers,
 * rooms and occupancy. A day that has passed is marked past, since the answer
 * only carries the days still to come; a coming day without a course day has
 * no entries, which the strip says in words.
 *
 * @param {object|null} answer The endpoint's answer, with `today` (Y-m-d).
 * @param {string} locale The BCP 47 locale.
 * @return {Array<{date: string, label: string, today: boolean, past: boolean, days: Array<{id: string, course: string, trainers: Array<string>, rooms: Array<string>, taken: number, capacity: number|null}>}>} Monday to Friday.
 * @spec openspec/changes/week-timetable-grid/specs/personal-timetable/spec.md#requirement-a-training-planner-sees-this-weeks-course-days-with-their-occupancy
 */
export function weekStrip(answer, locale) {
	const today = answer ? answer.today : null
	if (dayAndMonth(today, 'en') === '') {
		return []
	}
	const byDate = courseDaysByDate(answer)
	return workingDaysOf(today).map((date) => ({
		date,
		label: weekdayAndDate(date, locale),
		today: date === today,
		past: date < today,
		days: byDate.get(date) || [],
	}))
}

/**
 * The course days of the answer's occupancy, by date.
 *
 * @param {object|null} answer The endpoint's answer.
 * @return {Map<string, Array<object>>} The course days per Y-m-d date.
 * @spec openspec/changes/week-timetable-grid/specs/personal-timetable/spec.md#requirement-a-training-planner-sees-this-weeks-course-days-with-their-occupancy
 */
function courseDaysByDate(answer) {
	const byDate = new Map()
	const days = Array.isArray(answer?.occupancy?.days) ? answer.occupancy.days : []
	for (const day of days) {
		if (!day || typeof day.id !== 'string' || day.id === '' || typeof day.date !== 'string') {
			continue
		}
		if (!byDate.has(day.date)) {
			byDate.set(day.date, [])
		}
		byDate.get(day.date).push({
			id: day.id,
			course: day.courseName || day.name || '',
			trainers: Array.isArray(day.trainers) ? day.trainers : [],
			rooms: Array.isArray(day.rooms) ? day.rooms : [],
			taken: Number(day.participants) || 0,
			capacity: Number.isInteger(day.capacity) ? day.capacity : null,
		})
	}
	return byDate
}

/**
 * Monday to Friday of the week a Y-m-d date falls in.
 *
 * @param {string} date The date.
 * @return {Array<string>} Five Y-m-d dates.
 * @spec openspec/changes/week-timetable-grid/specs/personal-timetable/spec.md#requirement-a-training-planner-sees-this-weeks-course-days-with-their-occupancy
 */
export function workingDaysOf(date) {
	const [year, month, day] = date.split('-').map(Number)
	const start = new Date(Date.UTC(year, month - 1, day))
	start.setUTCDate(start.getUTCDate() - ((start.getUTCDay() + 6) % 7))
	const out = []
	for (let i = 0; i < 5; i++) {
		const next = new Date(start)
		next.setUTCDate(start.getUTCDate() + i)
		out.push(next.toISOString().slice(0, 10))
	}
	return out
}
