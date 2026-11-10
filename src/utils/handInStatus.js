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
 * The cohorts an assignment is for: the one it names, or all when it names
 * none.
 *
 * @param {object} assignment The Assignment.
 * @param {object[]} cohorts The cohort it names, or the course's cohorts.
 * @return {object[]} The cohorts.
 * @spec openspec/specs/assignments/spec.md#requirement-a-teacher-sees-who-has-not-handed-in-an-assignment
 */
function cohortsFor(assignment, cohorts) {
	const all = cohorts ?? []
	if (!assignment?.cohortId) {
		return all
	}
	return all.filter((c) => (c?.id ?? c?.uuid) === assignment.cohortId)
}

/**
 * Add a cohort's learner ids to the roster being built; blank ids are skipped.
 *
 * @param {Set<string>} seen The roster being built.
 * @param {object} cohort A cohort.
 * @return {void}
 * @spec openspec/specs/assignments/spec.md#requirement-a-teacher-sees-who-has-not-handed-in-an-assignment
 */
function addLearners(seen, cohort) {
	for (const learnerId of cohort?.learnerIds ?? []) {
		if (typeof learnerId === 'string' && learnerId !== '') {
			seen.add(learnerId)
		}
	}
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
	const seen = new Set()
	for (const cohort of cohortsFor(assignment, cohorts)) {
		addLearners(seen, cohort)
	}
	return [...seen]
}

/**
 * Whether the assignment's due date has passed. An unreadable date never has.
 *
 * @param {object} assignment The Assignment (for `dueAt`).
 * @param {Date} now The current moment.
 * @return {boolean} True once it is due.
 * @spec openspec/specs/assignments/spec.md#requirement-a-teacher-sees-who-has-not-handed-in-an-assignment
 */
function isPastDue(assignment, now) {
	const due = assignment?.dueAt ? new Date(assignment.dueAt) : null
	return Boolean(due) && !Number.isNaN(due.getTime()) && due < now
}

/**
 * The submissions that list a learner.
 *
 * @param {object[]|undefined} submissions The assignment's submissions.
 * @param {string} learnerId The learner.
 * @return {object[]} Their submissions.
 * @spec openspec/specs/assignments/spec.md#requirement-a-teacher-sees-who-has-not-handed-in-an-assignment
 */
function submissionsOf(submissions, learnerId) {
	return (submissions ?? []).filter((s) =>
		(s?.learnerIds ?? []).includes(learnerId),
	)
}

/**
 * The hand-in state of a learner.
 *
 * @param {object|undefined} handedIn Their handed-in submission.
 * @param {object|undefined} draft Their draft.
 * @return {string} `handed-in`, `started` or `not-started`.
 * @spec openspec/specs/assignments/spec.md#requirement-a-teacher-sees-who-has-not-handed-in-an-assignment
 */
function handInState(handedIn, draft) {
	if (handedIn) {
		return 'handed-in'
	}
	if (draft) {
		return 'started'
	}
	return 'not-started'
}

/**
 * The id of the submission a row names.
 *
 * @param {object|null} hit The submission.
 * @return {string|null} Its id or uuid.
 * @spec openspec/specs/assignments/spec.md#requirement-a-teacher-sees-who-has-not-handed-in-an-assignment
 */
function submissionIdOf(hit) {
	return hit?.id ?? hit?.uuid ?? null
}

/**
 * The row of one learner.
 *
 * @param {string} learnerId The learner.
 * @param {{submissions: (object[]|undefined), pastDue: boolean}} run The submissions and whether the work is due.
 * @return {{learnerId: string, state: string, lifecycle: string|null, submissionId: string|null, overdue: boolean}} The row.
 * @spec openspec/specs/assignments/spec.md#requirement-a-teacher-sees-who-has-not-handed-in-an-assignment
 */
function handInRow(learnerId, run) {
	const own = submissionsOf(run.submissions, learnerId)
	const handedIn = own.find((s) => HANDED_IN_STATES.includes(s?.lifecycle))
	const draft = own.find((s) => s?.lifecycle === 'draft')
	const hit = handedIn ?? draft ?? null
	const state = handInState(handedIn, draft)
	return {
		learnerId,
		state,
		lifecycle: hit?.lifecycle ?? null,
		submissionId: submissionIdOf(hit),
		overdue: state !== 'handed-in' && run.pastDue,
	}
}

/**
 * One row per roster learner with their hand-in state: `handed-in` when any
 * submission listing them is submitted, late or returned; `started` when
 * they only have drafts; `not-started` otherwise. Missing work is overdue
 * once the due date has passed.
 *
 * @param {{learnerIds: string[], submissions: object[], assignment: object}} handIn
 *   The roster, the assignment's submissions and the Assignment (for `dueAt`).
 * @param {Date} now The current moment.
 * @return {Array<{learnerId: string, state: string, lifecycle: string|null, submissionId: string|null, overdue: boolean}>}
 * @spec openspec/specs/assignments/spec.md#requirement-a-teacher-sees-who-has-not-handed-in-an-assignment
 */
export function handInRows(handIn, now = new Date()) {
	const run = {
		submissions: handIn.submissions,
		pastDue: isPastDue(handIn.assignment, now),
	}
	return (handIn.learnerIds ?? []).map((learnerId) => handInRow(learnerId, run))
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
