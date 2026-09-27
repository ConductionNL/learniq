// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * What the peer review section on an assignment shows, and how it reads the
 * allocation endpoint's answer. Pure functions, pinned by node tests.
 *
 * @spec openspec/changes/peer-review-allocation-trigger/specs/assignments/spec.md#requirement-a-teacher-allocates-peer-reviewers-from-the-assignment-page
 */

import { canSeeHandInStatus } from './handInStatus.js'

/** Allocation strategies the service knows; anything else is round-robin. */
export const ALLOCATION_STRATEGIES = ['round-robin', 'random', 'manual']

/**
 * The state of the peer review section for one assignment and user.
 *
 * @param {object} assignment The Assignment.
 * @param {string[]} dashboardViews The `dashboardRoles` initial state.
 * @param {Date} now The current moment.
 * @return {{visible: boolean, canAllocate: boolean, strategy: string, reviewersPerSubmission: number, beforeDeadline: boolean}}
 * @spec openspec/changes/peer-review-allocation-trigger/specs/assignments/spec.md#requirement-a-teacher-allocates-peer-reviewers-from-the-assignment-page
 */
export function peerReviewPanel(assignment, dashboardViews, now = new Date()) {
	const strategy = ALLOCATION_STRATEGIES.includes(
		assignment?.peerReviewAllocationStrategy,
	)
		? assignment.peerReviewAllocationStrategy
		: 'round-robin'
	const count = Number(assignment?.peerReviewersPerSubmission)
	const due = assignment?.dueAt ? new Date(assignment.dueAt) : null
	return {
		// Same staff gate as the hand-in section: a pupil cannot allocate.
		visible:
			canSeeHandInStatus(dashboardViews)
			&& assignment?.peerReviewEnabled === true,
		canAllocate: strategy !== 'manual',
		strategy,
		reviewersPerSubmission: Number.isInteger(count) && count >= 1 ? count : 2,
		beforeDeadline: Boolean(due) && !Number.isNaN(due.getTime()) && due > now,
	}
}

/**
 * Read the endpoint's `{ result: { strategy, submissionsProcessed,
 * createdCount } }` answer into what the section reports.
 *
 * @param {object} body The response body.
 * @return {{kind: string, created: number, processed: number}} `created`
 *   when reviews were added, `complete` when every submission already had
 *   its reviewers, `empty` when there was no handed-in work.
 * @spec openspec/changes/peer-review-allocation-trigger/specs/assignments/spec.md#requirement-a-teacher-allocates-peer-reviewers-from-the-assignment-page
 */
export function allocationOutcome(body) {
	const result = body?.result ?? {}
	const created = Number(result.createdCount) || 0
	const processed = Number(result.submissionsProcessed) || 0
	let kind = 'created'
	if (processed === 0) {
		kind = 'empty'
	} else if (created === 0) {
		kind = 'complete'
	}
	return { kind, created, processed }
}
