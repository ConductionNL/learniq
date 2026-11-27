// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The planner's week strip on Today (week-timetable-grid T4): this week's five
// working days with their course days and occupancy, read from the answer of
// GET /api/course-days/today. Pure, tested in tests/unit-js/plannerWeekStrip.test.mjs.

import { dayAndMonth, weekdayAndDate } from './plannerToday.js'

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
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-training-planner-sees-this-weeks-course-days-with-their-occupancy
 */
export function weekStrip(answer, locale) {
	const today = answer ? answer.today : null
	if (dayAndMonth(today, 'en') === '') {
		return []
	}
	const byDate = courseDaysByDate(answer)
	const strip = []
	for (const date of workingDaysOf(today)) {
		const day = { date, label: weekdayAndDate(date, locale), today: date === today, past: today > date }
		day.days = byDate.get(date) || []
		strip.push(day)
	}
	return strip
}

/**
 * The course days of the answer's occupancy, by date.
 *
 * @param {object|null} answer The endpoint's answer.
 * @return {Map<string, Array<object>>} The course days per Y-m-d date.
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-training-planner-sees-this-weeks-course-days-with-their-occupancy
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
		byDate.get(day.date).push(stripEntry(day))
	}
	return byDate
}

/**
 * One course day as the strip shows it.
 *
 * @param {object} day A day of the answer's occupancy.
 * @return {{id: string, course: string, trainers: Array<string>, rooms: Array<string>, taken: number, capacity: number|null}} The entry.
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-training-planner-sees-this-weeks-course-days-with-their-occupancy
 */
function stripEntry(day) {
	const entry = { id: day.id, course: day.courseName || day.name || '' }
	entry.trainers = Array.isArray(day.trainers) ? day.trainers : []
	entry.rooms = Array.isArray(day.rooms) ? day.rooms : []
	entry.taken = Number(day.participants) || 0
	entry.capacity = Number.isInteger(day.capacity) ? day.capacity : null
	return entry
}

/**
 * Monday to Friday of the week a Y-m-d date falls in.
 *
 * @param {string} date The date.
 * @return {Array<string>} Five Y-m-d dates.
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-training-planner-sees-this-weeks-course-days-with-their-occupancy
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
