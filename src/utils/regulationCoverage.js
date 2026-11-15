// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The per-rule coverage table on the compliance page
// (compliance-rule-coverage-table). Pure helpers, so node --test covers them.

/** The figures route, ComplianceRollupController::regulations. */
export const COVERAGE_URL = '/apps/learniq/api/compliance/coverage-by-regulation'

/**
 * The figures URL, narrowed to a department when one is chosen.
 *
 * @param {string|null} department The department path, or null for all.
 * @return {string} The app-relative URL.
 * @spec openspec/changes/compliance-rule-coverage-table/specs/compliance-rule-coverage/spec.md#scenario-the-department-filter-narrows-the-table
 */
export function coverageUrl(department) {
	if (!department) {
		return COVERAGE_URL
	}
	return `${COVERAGE_URL}?department=${encodeURIComponent(department)}`
}

/**
 * Sort the rows by coverage percentage. A rule nobody is in scope for has no
 * percentage and always goes last, whichever way the table is sorted.
 *
 * @param {object[]} rows The rows from the server.
 * @param {'asc'|'desc'} direction Lowest first or highest first.
 * @return {object[]} A sorted copy.
 * @spec openspec/changes/compliance-rule-coverage-table/specs/compliance-rule-coverage/spec.md#requirement-per-rule-coverage-table
 */
export function sortByPercent(rows, direction) {
	const sign = direction === 'desc' ? -1 : 1
	return [...(Array.isArray(rows) ? rows : [])].sort((left, right) => {
		const a = left?.coveragePercent
		const b = right?.coveragePercent
		if (a === null || a === undefined) {
			return b === null || b === undefined ? 0 : 1
		}
		if (b === null || b === undefined) {
			return -1
		}
		return sign * (a - b)
	})
}

/**
 * The department paths a filter can offer, from the department roll-up rows.
 *
 * @param {object[]} departments Rows of GET /api/compliance/departments.
 * @return {string[]} Non-empty paths, in roll-up order.
 * @spec openspec/changes/compliance-rule-coverage-table/specs/compliance-rule-coverage/spec.md#scenario-the-department-filter-narrows-the-table
 */
export function departmentPaths(departments) {
	return (Array.isArray(departments) ? departments : [])
		.map((row) => String(row?.department ?? ''))
		.filter((path) => path !== '')
}
