// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The parts of the proctoring flag review that are not drawing: which flags
// are open, how far into the attempt a flag came, and what a decision sends.
// Kept apart from ProctoringReviewQueue.vue so they can be tested without a
// browser.

/**
 * The flags of a session still waiting for a decision.
 *
 * @param {object} session A ProctoringSession.
 * @return {number} How many are open.
 * @spec openspec/specs/assessment/spec.md#requirement-staff-reach-the-proctoring-flag-review-page
 */
export function openFlagCount(session) {
	return ((session && session.flags) || []).filter(
		(flag) => (flag.reviewDecision ?? 'pending') === 'pending',
	).length
}

/**
 * Whole minutes from the start of the attempt to the flag, rounded down
 * (design D4); null when either time is missing or unreadable, or the flag
 * came before the start.
 *
 * @param {string|null|undefined} occurredAt When the flag was raised.
 * @param {string|null|undefined} startedAt When the attempt started.
 * @return {number|null} The minutes, or null when they cannot be told.
 * @spec openspec/specs/assessment/spec.md#requirement-staff-reach-the-proctoring-flag-review-page
 */
export function minutesIn(occurredAt, startedAt) {
	if (!occurredAt || !startedAt) {
		return null
	}
	const elapsed = Date.parse(occurredAt) - Date.parse(startedAt)
	if (Number.isNaN(elapsed) || elapsed < 0) {
		return null
	}
	return Math.floor(elapsed / 60000)
}

/**
 * The session's flags with one decision made, and nothing else changed.
 *
 * Only `reviewDecision` is sent: the server stamps who decided and when
 * (design D2), so the page neither claims a reviewer nor a time.
 *
 * @param {Array<object>} flags The session's flags as stored.
 * @param {string} flagId The flag decided on.
 * @param {'allowed'|'annulled'} decision The decision.
 * @return {Array<object>} The flags to send.
 * @spec openspec/specs/assessment/spec.md#requirement-staff-reach-the-proctoring-flag-review-page
 */
export function withDecision(flags, flagId, decision) {
	return (flags || []).map((flag) =>
		flag.flagId === flagId ? { ...flag, reviewDecision: decision } : flag,
	)
}

/**
 * The name to show for a user id: the resolved name, else the id itself,
 * never an empty line (design D3).
 *
 * @param {Record<string, string>} names Names resolved so far, by user id.
 * @param {string|null|undefined} userId The user id.
 * @return {string} The name to show.
 * @spec openspec/specs/assessment/spec.md#requirement-staff-reach-the-proctoring-flag-review-page
 */
export function nameOrId(names, userId) {
	const name = userId ? (names || {})[userId] : ''
	return name && name.trim() !== '' ? name : userId || ''
}
