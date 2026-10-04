// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { objectId } from './customPages.js'

/**
 * The ConferenceSignup a staff member files for one pupil. `learnerRef` is
 * the pupil's learner profile: the schema requires it, and OpenRegister
 * checks `required` before any listener could fill it in.
 *
 * @param {object} input What the form holds.
 * @param {string} input.roundId The chosen ConferenceRound's uuid.
 * @param {object|null} input.round The chosen ConferenceRound, for its tenant.
 * @param {object|null} input.learner The chosen pupil's LearnerProfile.
 * @param {string[]} input.teacherIds The teachers asked for.
 * @param {string} input.notes A note for the teacher, or ''.
 * @return {object} The request body.
 * @spec openspec/changes/absence-and-booking-fields-are-required/specs/parent-conferences/spec.md#requirement-a-conference-signup-names-its-pupils-learner-profile
 */
export function conferenceSignupBody({
	roundId,
	round,
	learner,
	teacherIds,
	notes,
}) {
	return {
		conferenceRoundId: roundId,
		learnerId: learner?.ncUserId ?? '',
		learnerRef: objectId(learner),
		requestedTeacherIds: teacherIds,
		notes: notes || null,
		tenant_id: round?.tenant_id ?? undefined,
	}
}
