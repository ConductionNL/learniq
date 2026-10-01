/**
 * Which part of the school the teacher dashboard lists (teacher-dashboard-own-groups).
 *
 * A group teacher (primary role `instructor`) sees the cohorts they teach and
 * what hangs off them: the sessions and assignments of those cohorts, and the
 * courses those cohorts run (Cohort.courseId, plus Programme.courseIds of the
 * cohort's programme). Every other role that reaches the teacher view
 * (coordinator, administration-manager, team-lead, admin) keeps the
 * school-wide lists.
 *
 * Plain ES module (not a .vue SFC) so it is directly importable from a Node
 * test runner without an SFC compile step.
 *
 * @spec openspec/changes/teacher-dashboard-own-groups/specs/dashboard/spec.md#requirement-the-teacher-dashboard-of-a-group-teacher-lists-only-their-own-groups
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/**
 * Whether the teacher dashboard is scoped to the caller's own groups.
 *
 * @param {string} primaryRole The resolved role (initial state `primaryRole`).
 * @return {boolean} True for a group teacher only.
 * @spec openspec/changes/teacher-dashboard-own-groups/specs/dashboard/spec.md#requirement-the-teacher-dashboard-of-a-group-teacher-lists-only-their-own-groups
 */
export function isScopedTeacher(primaryRole) {
	return primaryRole === 'instructor'
}

/**
 * The OpenRegister id of a row, whatever shape the list endpoint returned.
 *
 * @param {object} row An object from the list endpoint.
 * @return {string} The id, or '' when it has none.
 */
function idOf(row) {
	return String(row?.id || row?.uuid || row?.['@self']?.id || '')
}

/**
 * The scope of a group teacher: the cohorts they teach and the courses those
 * cohorts run. A cohort that does not list the teacher is ignored, so a
 * widened answer from the server never widens the scope.
 *
 * @param {object} input The rows to scope from.
 * @param {string} input.userId The teacher's Nextcloud user id.
 * @param {object[]} input.cohorts Cohorts from `?teacherIds=<userId>`.
 * @param {object[]} [input.programmes] The programmes those cohorts name.
 * @return {{cohortIds: string[], courseIds: string[], programmeIds: string[]}}
 * @spec openspec/changes/teacher-dashboard-own-groups/specs/dashboard/spec.md#requirement-the-teacher-dashboard-of-a-group-teacher-lists-only-their-own-groups
 */
export function teacherScope({ userId, cohorts = [], programmes = [] }) {
	const taught = cohorts.filter((cohort) =>
		(cohort?.teacherIds || []).includes(userId),
	)
	const cohortIds = [...new Set(taught.map(idOf).filter(Boolean))]
	const programmeIds = [
		...new Set(taught.map((cohort) => cohort.programmeId).filter(Boolean)),
	]

	const courseIds = new Set(
		taught.map((cohort) => cohort.courseId).filter(Boolean),
	)
	for (const programme of programmes) {
		if (programmeIds.includes(idOf(programme))) {
			for (const courseId of programme.courseIds || []) {
				if (courseId) {
					courseIds.add(courseId)
				}
			}
		}
	}

	return { cohortIds, courseIds: [...courseIds], programmeIds }
}

/**
 * The list filter of each teacher widget. Without a scope (school-wide
 * roles) every filter is empty. An empty id list stays an empty list: the
 * widget reads it as "matches nothing", never as "no filter".
 *
 * @param {string} userId The teacher's Nextcloud user id.
 * @param {{cohortIds: string[], courseIds: string[]}|null} scope The teacher scope, or null for school-wide.
 * @return {{cohorts: object, courses: object, sessions: object, assignments: object}}
 * @spec openspec/changes/teacher-dashboard-own-groups/specs/dashboard/spec.md#requirement-the-teacher-dashboard-of-a-group-teacher-lists-only-their-own-groups
 */
export function teacherWidgetFilters(userId, scope) {
	if (!scope) {
		return { cohorts: {}, courses: {}, sessions: {}, assignments: {} }
	}

	return {
		// `teacherIds` is an array property: OpenRegister reads a scalar
		// filter on it as "the array contains this value".
		cohorts: { teacherIds: userId },
		courses: { _ids: scope.courseIds },
		sessions: { cohortId: scope.cohortIds },
		assignments: { cohortId: scope.cohortIds },
	}
}

/**
 * Whether a list filter can match no row: one of its values is an empty
 * list. OpenRegister drops an empty list from the query, which would return
 * every row, so the caller must not ask.
 *
 * @param {object} filter A list filter.
 * @return {boolean}
 * @spec openspec/changes/teacher-dashboard-own-groups/specs/dashboard/spec.md#scenario-a-teacher-without-a-group-sees-empty-lists
 */
export function filterMatchesNothing(filter) {
	return Object.values(filter || {}).some(
		(value) => Array.isArray(value) && value.length === 0,
	)
}

/**
 * Append a list filter to query parameters the way OpenRegister reads them:
 * a list becomes `key[]=a&key[]=b` (PHP parses it to an array, an IN filter);
 * anything else is one `key=value`.
 *
 * @param {URLSearchParams} params The parameters to add to.
 * @param {object} filter A list filter.
 * @return {URLSearchParams} The same parameters.
 * @spec openspec/changes/teacher-dashboard-own-groups/specs/dashboard/spec.md#requirement-the-teacher-dashboard-of-a-group-teacher-lists-only-their-own-groups
 */
export function appendFilter(params, filter) {
	for (const [key, value] of Object.entries(filter || {})) {
		if (Array.isArray(value)) {
			for (const item of value) {
				params.append(`${key}[]`, String(item))
			}
		} else if (value !== undefined && value !== null) {
			params.append(key, String(value))
		}
	}
	return params
}
