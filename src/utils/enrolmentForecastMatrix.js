// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The forecast as CnDataMatrix rows and columns. The figures come from the
 * server (EnrolmentForecastService); the matrix only shows them, so no column
 * carries a formula.
 */

/**
 * The matrix rows of the forecast per programme year.
 *
 * @param {Array<object>} years The `programmeYears` of a forecast.
 * @return {Array<object>} One row per programme year.
 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
 */
export function yearRows(years) {
	return (years ?? []).map((row) => ({
		id: `${row.programmeId}-${row.programmeYear}`,
		label: row.programmeName,
		programmeYear: String(row.programmeYear),
		learners: row.learners,
		groupsNeeded: row.groupsNeeded,
	}))
}

/**
 * The matrix rows of the forecast per subject; an estimated figure says so.
 *
 * @param {Array<object>} subjects The `subjects` of a forecast.
 * @param {string} estimated The translated word for an estimated figure.
 * @return {Array<object>} One row per programme year and subject.
 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
 */
export function subjectRows(subjects, estimated) {
	return (subjects ?? []).map((row) => ({
		id: `${row.programmeId}-${row.programmeYear}-${row.courseId}`,
		label: row.programmeName,
		programmeYear: String(row.programmeYear),
		courseName: row.courseName,
		learners: row.learners,
		estimate: row.isEstimated ? estimated : '',
		groupsNeeded: row.groupsNeeded,
	}))
}

/**
 * Read-only matrix columns from `[key, label, type]` triples: numbers add up
 * in the totals row, text does not.
 *
 * @param {Array<Array<string>>} specs The columns.
 * @return {Array<object>} CnDataMatrix columns.
 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
 */
export function matrixColumns(specs) {
	return specs.map(([key, label, type]) => ({
		key,
		label,
		type,
		readOnly: true,
		aggregate: type === 'number' ? 'sum' : 'none',
	}))
}
