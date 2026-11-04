// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The name of the instance's school, when there is exactly one.
 *
 * With several schools no single name is true for the whole instance.
 *
 * @param {object[]} schools The School rows the reader can see.
 * @return {string} The name, or '' when there is not exactly one school.
 * @spec openspec/changes/vo-lesson-store-and-wednesday-records/specs/dashboard/spec.md#requirement-the-hr-today-subtitle-names-the-school
 */
export function soleSchoolName(schools) {
	if (!Array.isArray(schools) || schools.length !== 1) {
		return ''
	}
	return String(schools[0]?.name ?? '').trim()
}

/**
 * The subtitle line of a role from a page's manifest config.
 *
 * @param {object|null} lines The lines per role.
 * @param {string} role The reader's primary role.
 * @return {string} The line, or '' when the role has none.
 * @spec openspec/changes/vo-lesson-store-and-wednesday-records/specs/dashboard/spec.md#requirement-the-hr-today-subtitle-names-the-school
 */
export function roleLine(lines, role) {
	const line = (lines ?? {})[role]
	return typeof line === 'string' ? line : ''
}
