// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Work group helpers (enrolment-self-join-work-group). Pure functions, so
// tests/unit-js can import them without an SFC compile.

/**
 * The learners of a hand-in: the caller's whole work group in the
 * assignment's set when the assignment is a group hand-in that names a set,
 * else only the caller. The caller is always first, so the submission's
 * learnerRef is the caller's own.
 *
 * @param {object} assignment The Assignment (groupSubmission, workGroupSetName, cohortId).
 * @param {object[]} sets The `sets` of GET /api/my/work-groups.
 * @param {string} learnerId The caller's user id.
 * @return {string[]} The learnerIds for the Submission.
 * @spec openspec/specs/enrolment/spec.md#requirement-a-group-hand-in-names-the-whole-work-group
 */
export function handInLearners(assignment, sets, learnerId) {
	if (!assignment?.groupSubmission || !assignment.workGroupSetName) {
		return [learnerId]
	}
	const set = (sets || []).find(
		(s) =>
			s.setName === assignment.workGroupSetName
			&& (!assignment.cohortId || s.cohortId === assignment.cohortId),
	)
	const own = set?.groups?.find((g) => g.mine)
	const others = (own?.memberIds || []).filter((id) => id !== learnerId)
	return [learnerId, ...others]
}
