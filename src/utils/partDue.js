// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The trainer's Today card for a course day split into parts: which part has
// begun and is not registered yet, and how many were there in the part before
// it (training-provider-course-days T5). Reads the answer of
// GET /api/course-days/sessions/{sessionId}/parts. Pure, tested in
// tests/unit-js/partDue.test.mjs.

/**
 * What the trainer's Today says about one session's parts.
 *
 * `due` is the part that has begun and has no attendance yet, or null when
 * nothing is due. `earlier` is the last registered part before it, with how
 * many of the participants were there and how many came late.
 *
 * @param {{parts?: Array<{key: string, begun: boolean, registered: boolean, there: number, of: number, late?: Array<object>}>, due?: string|null}|null} summary The endpoint's answer.
 * @return {{due: string, earlier: {key: string, there: number, of: number, late: number}|null}|null} The notice, or null.
 * @spec openspec/changes/training-provider-course-days/specs/attendance/spec.md#requirement-a-trainer-registers-attendance-per-part-of-the-day
 */
export function partDueNotice(summary) {
	const parts = Array.isArray(summary?.parts) ? summary.parts : []
	const due = typeof summary?.due === 'string' ? summary.due : ''
	const index = parts.findIndex((part) => part && part.key === due)
	if (due === '' || index === -1) {
		return null
	}

	const earlier = parts.slice(0, index).filter((part) => part && part.registered).pop() ?? null
	return {
		due,
		earlier: earlier
			? {
				key: earlier.key,
				there: Number(earlier.there) || 0,
				of: Number(earlier.of) || 0,
				late: Array.isArray(earlier.late) ? earlier.late.length : 0,
			}
			: null,
	}
}

/**
 * The sessions of today a trainer's card asks about: those split into parts.
 *
 * @param {Array<object>} sessions Session rows.
 * @param {Date} now The current time.
 * @return {Array<object>} The sessions of today with parts, in their order.
 * @spec openspec/changes/training-provider-course-days/specs/attendance/spec.md#requirement-a-trainer-registers-attendance-per-part-of-the-day
 */
export function sessionsWithPartsToday(sessions, now) {
	const day = (value) => {
		const date = new Date(value)
		return Number.isNaN(date.getTime()) ? '' : date.toDateString()
	}
	const today = now.toDateString()
	return (Array.isArray(sessions) ? sessions : []).filter(
		(session) => Array.isArray(session?.parts) && session.parts.length > 0 && day(session.startsAt) === today,
	)
}
