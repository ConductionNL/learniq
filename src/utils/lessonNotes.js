/**
 * Lesson notes (timetabling-lesson-note): which lessons a series note lands
 * on, and the object a note is stored as.
 *
 * Pure functions, so the series rule is tested without a browser
 * (tests/unit-js/lessonNotes.test.mjs).
 *
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/**
 * The local weekday and clock time of a lesson start, as `d HH:MM`.
 *
 * @param {string} iso ISO 8601 timestamp.
 * @return {string} The slot key, or '' when unparseable.
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson
 */
export function slotOf(iso) {
	const ts = Date.parse(iso || '')
	if (Number.isNaN(ts)) {
		return ''
	}
	const d = new Date(ts)
	const hh = String(d.getHours()).padStart(2, '0')
	const mm = String(d.getMinutes()).padStart(2, '0')
	return `${d.getDay()} ${hh}:${mm}`
}

/**
 * Whether two lessons are the same lesson in the week pattern: the same
 * group, the same course (or, without a course, the same title), the same
 * weekday and the same start time.
 *
 * @param {object} a A lesson from a timetable endpoint.
 * @param {object} b Another lesson.
 * @return {boolean} True when b repeats a.
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson
 */
export function repeatsLesson(a, b) {
	if (!a || !b || a.cohortId !== b.cohortId) {
		return false
	}
	const sameCourse = a.courseId
		? a.courseId === b.courseId
		: (a.title || '') === (b.title || '')
	return (
		sameCourse
		&& slotOf(a.startsAt) !== ''
		&& slotOf(a.startsAt) === slotOf(b.startsAt)
	)
}

/**
 * The lessons a note lands on: the chosen lesson, plus its repeats in the
 * next `weeks` weeks (cancelled lessons included, a note may explain them).
 *
 * @param {object} lesson The lesson the teacher chose.
 * @param {Array<object>} candidates The cohort's lessons in the weeks after it.
 * @param {number} weeks How many following weeks to include (0 for this lesson only).
 * @return {Array<object>} The target lessons, the chosen one first, no duplicates.
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson
 */
export function seriesTargets(lesson, candidates, weeks) {
	const out = [lesson]
	if (!weeks || weeks < 1) {
		return out
	}
	const start = Date.parse(lesson.startsAt)
	const end = start + weeks * 7 * 86400000 + 3600000
	const seen = new Set([lesson.id])
	for (const other of candidates || []) {
		const ts = Date.parse(other.startsAt)
		if (seen.has(other.id) || Number.isNaN(ts) || ts <= start || ts > end) {
			continue
		}
		if (repeatsLesson(lesson, other)) {
			seen.add(other.id)
			out.push(other)
		}
	}
	return out
}

/**
 * The `lesson-note` object for one lesson. A learniq Session is named by
 * `sessionId`; a planninq lesson by its `timetableSessionRef`.
 *
 * @param {object} lesson The target lesson.
 * @param {{topic: string, text: string, audience: string}} form The note.
 * @return {object} The object to POST.
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson
 */
export function noteFor(lesson, form) {
	const note = {
		cohortId: lesson.cohortId,
		topic: form.topic ? form.topic.trim() : null,
		text: form.text.trim(),
		audience: form.audience === 'cover' ? 'cover' : 'learners',
	}
	if ((lesson.source || 'learniq') === 'planninq') {
		note.timetableSessionRef = {
			sourceSystem: lesson.sourceSystem || '',
			externalRef: lesson.externalRef || '',
		}
	} else {
		note.sessionId = lesson.id
	}
	return note
}
