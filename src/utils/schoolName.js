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
