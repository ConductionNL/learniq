// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The body of a conference signup request (`POST /api/conference-signups`).
 * The server takes the tenant from the round and the pupil's learner profile
 * from the pupil, so neither is sent.
 *
 * @param {object} input What the form holds.
 * @param {string} input.roundId The chosen ConferenceRound's uuid.
 * @param {string} input.learnerId The chosen pupil's Nextcloud user id.
 * @param {string[]} input.teacherIds The teachers asked for.
 * @param {string} input.notes A note for the teacher, or ''.
 * @return {object} The request body.
 * @spec openspec/specs/parent-conferences/spec.md#requirement-a-guardian-or-self-signup-submission-is-gated-by-a-per-object-authorization-guard
 */
export function conferenceSignupBody({ roundId, learnerId, teacherIds, notes }) {
	return {
		conferenceRoundId: roundId,
		learnerId: learnerId ?? '',
		requestedTeacherIds: teacherIds,
		notes: notes || null,
	}
}
