// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Who has handed in an assignment, who started and who has not begun.
 *
 * Pure functions, so the diff is pinned by node tests and the section that
 * renders it stays thin. The roster is the assignment's cohort, the same
 * roster the attendance register marks against.
 *
 * @spec openspec/specs/assignments/spec.md#requirement-a-teacher-sees-who-has-not-handed-in-an-assignment
 */

/** Submission lifecycles that count as handed in. */
export const HANDED_IN_STATES = ['submitted', 'late', 'returned']

/** Dashboard views that may see the roster. */
export const STAFF_VIEWS = ['teacher', 'admin']

/**
 * Whether the signed-in user may see the hand-in status. A pupil can read
 * the cohort roster but only their own submission, so for them the diff
 * would call every classmate missing.
 *
 * @param {string[]} dashboardViews The `dashboardRoles` initial state.
 * @return {boolean} True for the teacher and admin views.
 * @spec openspec/specs/assignments/spec.md#requirement-a-teacher-sees-who-has-not-handed-in-an-assignment
 */
export function canSeeHandInStatus(dashboardViews) {
	return (
		Array.isArray(dashboardViews)
		&& dashboardViews.some((view) => STAFF_VIEWS.includes(view))
	)
}

/**
 * The learners an assignment is for: its cohort's learners, or every
 * learner of every cohort of its course when it names no cohort.
 * De-duplicated, in first-seen order.
 *
 * @param {object} assignment The Assignment.
 * @param {object[]} cohorts The cohort it names, or the course's cohorts.
 * @return {string[]} Nextcloud user ids.
 * @spec openspec/specs/assignments/spec.md#requirement-a-teacher-sees-who-has-not-handed-in-an-assignment
 */
export function rosterFor(assignment, cohorts) {
	const wanted = assignment?.cohortId
		? (cohorts ?? []).filter((c) => (c?.id ?? c?.uuid) === assignment.cohortId)
		: (cohorts ?? [])
	const seen = new Set()
	for (const cohort of wanted) {
		for (const learnerId of cohort?.learnerIds ?? []) {
			if (typeof learnerId === 'string' && learnerId !== '') {
				seen.add(learnerId)
			}
		}
	}
	return [...seen]
}

/**
 * One row per roster learner with their hand-in state: `handed-in` when any
 * submission listing them is submitted, late or returned; `started` when
 * they only have drafts; `not-started` otherwise. Missing work is overdue
 * once the due date has passed.
 *
 * @param {string[]} learnerIds The roster.
 * @param {object[]} submissions The assignment's submissions.
 * @param {object} assignment The Assignment (for `dueAt`).
 * @param {Date} now The current moment.
 * @return {Array<{learnerId: string, state: string, lifecycle: string|null, submissionId: string|null, overdue: boolean}>}
 * @spec openspec/specs/assignments/spec.md#requirement-a-teacher-sees-who-has-not-handed-in-an-assignment
 */
export function handInRows(learnerIds, submissions, assignment, now = new Date()) {
	const due = assignment?.dueAt ? new Date(assignment.dueAt) : null
	const pastDue = Boolean(due) && !Number.isNaN(due.getTime()) && due < now
	return (learnerIds ?? []).map((learnerId) => {
		const own = (submissions ?? []).filter((s) =>
			(s?.learnerIds ?? []).includes(learnerId),
		)
		const handedIn = own.find((s) => HANDED_IN_STATES.includes(s?.lifecycle))
		const draft = own.find((s) => s?.lifecycle === 'draft')
		const hit = handedIn ?? draft ?? null
		let state = 'not-started'
		if (handedIn) {
			state = 'handed-in'
		} else if (draft) {
			state = 'started'
		}
		return {
			learnerId,
			state,
			lifecycle: hit?.lifecycle ?? null,
			submissionId: hit?.id ?? hit?.uuid ?? null,
			overdue: state !== 'handed-in' && pastDue,
		}
	})
}

/**
 * Counts per state, plus the roster size.
 *
 * @param {object[]} rows Output of handInRows().
 * @return {{total: number, handedIn: number, started: number, notStarted: number, overdue: number}}
 * @spec openspec/specs/assignments/spec.md#requirement-a-teacher-sees-who-has-not-handed-in-an-assignment
 */
export function handInSummary(rows) {
	const list = rows ?? []
	return {
		total: list.length,
		handedIn: list.filter((r) => r.state === 'handed-in').length,
		started: list.filter((r) => r.state === 'started').length,
		notStarted: list.filter((r) => r.state === 'not-started').length,
		overdue: list.filter((r) => r.overdue).length,
	}
}
