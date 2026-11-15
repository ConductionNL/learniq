// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/** OpenRegister's lifecycle transition endpoint for one object. */
export const TRANSITION_URL = '/apps/openregister/api/objects/{id}/transition'

/**
 * The transition body that declines a pending sign-up with the reason the
 * learner reads (Enrolment `decline`, input `declineReason`).
 *
 * @param {string} reason The reason as typed.
 * @return {{action: string, data: {declineReason: string}}} The body.
 * @spec openspec/specs/enrolment/spec.md#requirement-a-request-waits-for-a-teacher-or-manager
 */
export function declineBody(reason) {
	return { action: 'decline', data: { declineReason: String(reason ?? '').trim() } }
}

/**
 * Whether a reason is long enough to send: anything but blank.
 *
 * @param {string} reason The reason as typed.
 * @return {boolean} True when it can be sent.
 * @spec openspec/specs/enrolment/spec.md#requirement-a-request-waits-for-a-teacher-or-manager
 */
export function canDecline(reason) {
	return declineBody(reason).data.declineReason !== ''
}
