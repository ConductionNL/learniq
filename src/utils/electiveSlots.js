// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Weekly slots of electives and the clashes between them, for the subject
 * choice picker (timetabling-student-choice-placement). A slot is
 * `{ weekday, start, end }` with weekday 1 (Monday) to 7 and times as `HH:MM`
 * in the reader's time zone, as `GET /api/timetable/course-slots` returns them.
 *
 * @spec openspec/changes/timetabling-student-choice-placement/specs/timetable-student-choice/spec.md#requirement-clash-warning-when-choosing
 */

const TIME = /^([01]\d|2[0-3]):[0-5]\d$/

/**
 * Minutes since midnight of an `HH:MM` time, or null.
 *
 * @param {string} value The time.
 * @return {number|null} The minutes.
 */
function minutes(value) {
	if (typeof value !== 'string' || !TIME.test(value)) {
		return null
	}
	const [h, m] = value.split(':').map(Number)
	return h * 60 + m
}

/**
 * Whether two slots meet at the same time; touching ends do not overlap.
 *
 * @param {object} a A slot.
 * @param {object} b Another slot.
 * @return {boolean} True when they overlap.
 * @spec openspec/changes/timetabling-student-choice-placement/specs/timetable-student-choice/spec.md#requirement-clash-warning-when-choosing
 */
export function slotsOverlap(a, b) {
	if (!a || !b || Number(a.weekday) !== Number(b.weekday)) {
		return false
	}
	const [aStart, aEnd, bStart, bEnd] = [a.start, a.end, b.start, b.end].map(
		minutes,
	)
	if ([aStart, aEnd, bStart, bEnd].includes(null)) {
		return false
	}
	return aStart < bEnd && bStart < aEnd
}

/**
 * The first overlapping slot of two slot lists, or null.
 *
 * @param {Array<object>} first  Slots.
 * @param {Array<object>} second Slots.
 * @return {object|null} The slot of `first` that overlaps.
 */
function firstOverlap(first, second) {
	for (const a of Array.isArray(first) ? first : []) {
		for (const b of Array.isArray(second) ? second : []) {
			if (slotsOverlap(a, b)) {
				return a
			}
		}
	}
	return null
}

/**
 * The clashes among chosen electives, and between an elective and a core lesson.
 *
 * One clash per pair, naming the elective's slot.
 *
 * @param {Array<{id: string, label: string, slots: Array<object>}>} chosen The chosen electives.
 * @param {Array<{weekday: number, start: string, end: string, label: string}>} core The learner's core lessons.
 * @return {Array<{first: string, second: string, slot: object}>} The clashes.
 * @spec openspec/changes/timetabling-student-choice-placement/specs/timetable-student-choice/spec.md#requirement-clash-warning-when-choosing
 */
export function clashes(chosen, core) {
	const list = Array.isArray(chosen) ? chosen : []
	const found = []
	list.forEach((a, i) => {
		list.slice(i + 1).forEach((b) => {
			const slot = firstOverlap(a.slots, b.slots)
			if (slot) {
				found.push({ first: a.label, second: b.label, slot })
			}
		})
	})

	const coreLessons = Array.isArray(core) ? core : []
	list.forEach((a) => {
		const seen = new Set()
		coreLessons.forEach((lesson) => {
			const slot = firstOverlap(a.slots, [lesson])
			if (slot && !seen.has(lesson.label)) {
				seen.add(lesson.label)
				found.push({ first: a.label, second: lesson.label, slot })
			}
		})
	})

	return found
}

/**
 * A slot as text, such as "Tuesday 10:00 to 11:00", in the reader's language.
 *
 * @param {object} slot   A slot.
 * @param {string} locale A BCP 47 locale, such as `nl` or `en`.
 * @return {string} The label.
 * @spec openspec/changes/timetabling-student-choice-placement/specs/timetable-student-choice/spec.md#requirement-clash-warning-when-choosing
 */
export function slotLabel(slot, locale) {
	// 1 January 2024 is a Monday, so day `weekday` of that week names the day.
	const day = new Date(2024, 0, Number(slot?.weekday) || 1).toLocaleDateString(
		locale || 'en',
		{
			weekday: 'long',
		},
	)
	return `${day} ${slot?.start ?? ''}–${slot?.end ?? ''}`
}
