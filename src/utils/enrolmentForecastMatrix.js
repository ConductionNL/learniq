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

/**
 * The scenario's rates as matrix rows: one per programme year, the three
 * rates editable.
 *
 * @param {Array<object>} rates The scenario's `rates`.
 * @param {function(string): string} nameOf The programme name of an id.
 * @return {Array<object>} One row per rate, its id the index in `rates`.
 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
 */
export function rateRows(rates, nameOf) {
	return (rates ?? []).map((rate, index) => ({
		id: String(index),
		label: nameOf(rate.programmeId),
		programmeYear: String(rate.programmeYear),
		upRate: rate.upRate,
		repeatRate: rate.repeatRate,
		leaveRate: rate.leaveRate,
	}))
}

/**
 * The scenario's expected intake as matrix rows, the number editable.
 *
 * @param {Array<object>} intake The scenario's `intake`.
 * @param {function(string): string} nameOf The programme name of an id.
 * @return {Array<object>} One row per programme, its id the index in `intake`.
 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
 */
export function intakeRows(intake, nameOf) {
	return (intake ?? []).map((row, index) => ({
		id: String(index),
		label: nameOf(row.programmeId),
		expected: row.expected,
	}))
}

/**
 * An editable number column: no total, since a rate does not add up.
 *
 * @param {string} key The field.
 * @param {string} label The translated header.
 * @return {object} A CnDataMatrix column.
 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
 */
export function editableColumn(key, label) {
	return { key, label, type: 'number', aggregate: 'none' }
}

/**
 * Write one committed matrix cell back into the scenario list it came from.
 *
 * @param {Array<object>} list The scenario's `rates` or `intake`.
 * @param {{rowId: string, colKey: string, value: number|null}} edit CnDataMatrix's `cell-edit` payload.
 * @return {boolean} Whether a row took the value.
 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
 */
export function applyCellEdit(list, edit) {
	const row = (list ?? [])[Number(edit?.rowId)]
	if (!row) {
		return false
	}
	row[edit.colKey] = edit.value
	return true
}
