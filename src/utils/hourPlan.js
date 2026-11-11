/**
 * Hour plans (timetabling-multi-year-hour-plan): the grid of a plan, its
 * totals per year against the norm, and the copy for the next intake.
 *
 * Pure functions, tested in tests/unit-js/hourPlan.test.mjs.
 *
 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/**
 * The number of years of a plan: its duration, at least 1.
 *
 * @param {object} plan The hour plan.
 * @return {number} The years of the programme.
 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
 */
function planYears(plan) {
	return Math.max(1, Number(plan?.durationYears) || 1)
}

/**
 * A number of hours as entered, 0 when it is not a number.
 *
 * @param {(number|string|null|undefined)} value The stored hours.
 * @return {number} The hours.
 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
 */
function hoursOrZero(value) {
	return Number(value) || 0
}

/**
 * The columns of one year: one per period, or the whole year when the plan
 * has no periods.
 *
 * @param {number} year The year of the programme.
 * @param {Array<object>} periods The periods of a year.
 * @return {Array<{programmeYear: number, periodCode: (string|null), label: string}>} The columns.
 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
 */
function yearColumns(year, periods) {
	if (periods.length === 0) {
		return [{ programmeYear: year, periodCode: null, label: '' }]
	}
	return periods.map((period) => ({
		programmeYear: year,
		periodCode: period.periodCode,
		label: period.label || period.periodCode,
	}))
}

/**
 * The columns of the grid: every year of the programme times every period,
 * or one column per year when the plan has no periods.
 *
 * @param {object} plan The hour plan.
 * @return {Array<{programmeYear: number, periodCode: (string|null), label: string}>} The columns.
 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
 */
export function planColumns(plan) {
	const years = planYears(plan)
	const periods = Array.isArray(plan?.periodsPerYear) ? plan.periodsPerYear : []
	const columns = []
	for (let year = 1; year <= years; year++) {
		columns.push(...yearColumns(year, periods))
	}
	return columns
}

/**
 * Whether a plan line fills one cell of the grid.
 *
 * @param {object} line A plan line.
 * @param {{courseId: string, programmeYear: number, periodCode: (string|null|undefined)}} cell The cell.
 * @return {boolean} True when the line is the cell's line.
 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
 */
function lineFillsCell(line, cell) {
	return line.courseId === cell.courseId
		&& Number(line.programmeYear) === cell.programmeYear
		&& (line.periodCode ?? null) === (cell.periodCode ?? null)
}

/**
 * The contact hours in one cell of the grid.
 *
 * @param {Array<object>} lines The plan lines.
 * @param {{courseId: string, programmeYear: number, periodCode: (string|null)}} cell The course, the year of the programme and the period (null for the whole year).
 * @return {number} The contact hours, 0 when there is no line.
 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
 */
export function cellHours(lines, cell) {
	const line = (lines || []).find((l) => lineFillsCell(l, cell))
	return line ? hoursOrZero(line.contactHours) : 0
}

/**
 * One line after its cell was set: kept as is when it is another cell's,
 * dropped at 0 hours when it carries nothing else, else given the hours.
 *
 * @param {object} line A plan line.
 * @param {{courseId: string, programmeYear: number, periodCode: (string|null), hours: number}} entry The cell and its hours.
 * @return {Array<object>} The line, changed, or nothing.
 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
 */
function lineAfterEntry(line, entry) {
	if (!lineFillsCell(line, entry)) {
		return [line]
	}
	if (entry.hours === 0 && !(Number(line.otherHours) > 0)) {
		return []
	}
	return [{ ...line, contactHours: entry.hours }]
}

/**
 * A new lesson line for a cell that had none.
 *
 * @param {{courseId: string, programmeYear: number, periodCode: (string|null), hours: number}} entry The cell and its hours.
 * @return {object} The new line.
 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
 */
function newCellLine(entry) {
	return {
		courseId: entry.courseId,
		programmeYear: entry.programmeYear,
		periodCode: entry.periodCode ?? null,
		contactHours: entry.hours,
		otherHours: 0,
		activityKind: 'lesson',
	}
}

/**
 * The lines with one cell set. A cell set to 0 drops a line that carries
 * nothing else; a line's other hours, kind and remark are kept.
 *
 * @param {Array<object>} lines The plan lines.
 * @param {{courseId: string, programmeYear: number, periodCode: (string|null), hours: (number|string)}} entry The course, the year of the programme, the period (or null) and the contact hours entered.
 * @return {Array<object>} New lines.
 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
 */
export function setCell(lines, entry) {
	const cell = { ...entry, hours: Math.max(0, hoursOrZero(entry.hours)) }
	const current = lines || []
	const out = current.flatMap((line) => lineAfterEntry(line, cell))
	if (!current.some((line) => lineFillsCell(line, cell)) && cell.hours > 0) {
		out.push(newCellLine(cell))
	}
	return out
}

/**
 * The contact and other hours planned in one year of the programme.
 *
 * @param {Array<object>|undefined} lines The plan lines.
 * @param {number} year The year of the programme.
 * @return {{contact: number, other: number}} The sums.
 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
 */
function yearSums(lines, year) {
	const sums = { contact: 0, other: 0 }
	for (const line of (lines || []).filter((l) => Number(l.programmeYear) === year)) {
		sums.contact += hoursOrZero(line.contactHours)
		sums.other += hoursOrZero(line.otherHours)
	}
	return sums
}

/**
 * The contact hours norm of one year, or null when the year has none.
 *
 * @param {Array<object>|undefined} yearNorms The norms of the plan.
 * @param {number} year The year of the programme.
 * @return {number|null} The norm.
 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
 */
function yearNorm(yearNorms, year) {
	const norms = Array.isArray(yearNorms) ? yearNorms : []
	const normRow = norms.find((n) => Number(n.programmeYear) === year)
	if (!normRow || normRow.contactHours === undefined || normRow.contactHours === null) {
		return null
	}
	return Number(normRow.contactHours)
}

/**
 * The hours a year is short of its norm, 0 when it has none or meets it.
 *
 * @param {number} contact The contact hours planned.
 * @param {number|null} norm The norm.
 * @return {number} The shortfall.
 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
 */
function shortOfNorm(contact, norm) {
	if (norm !== null && contact < norm) {
		return norm - contact
	}
	return 0
}

/**
 * The totals of one year of the programme, with its norm and shortfall.
 *
 * @param {object} plan The hour plan.
 * @param {number} year The year of the programme.
 * @return {{programmeYear: number, contactHours: number, otherHours: number, norm: (number|null), shortBy: number}} The row.
 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
 */
function yearTotal(plan, year) {
	const sums = yearSums(plan?.lines, year)
	const norm = yearNorm(plan?.yearNorms, year)
	return {
		programmeYear: year,
		contactHours: sums.contact,
		otherHours: sums.other,
		norm,
		shortBy: shortOfNorm(sums.contact, norm),
	}
}

/**
 * Totals per year of the programme, with the norm and the shortfall.
 *
 * @param {object} plan The hour plan.
 * @return {Array<{programmeYear: number, contactHours: number, otherHours: number, norm: (number|null), shortBy: number}>} One row per year.
 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
 */
export function yearTotals(plan) {
	const years = planYears(plan)
	const rows = []
	for (let year = 1; year <= years; year++) {
		rows.push(yearTotal(plan, year))
	}
	return rows
}

/**
 * The school year after this one: 2026-2027 becomes 2027-2028.
 *
 * @param {string} year A school year, `YYYY-YYYY`.
 * @return {string} The next school year, or '' when unreadable.
 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
 */
export function nextSchoolYear(year) {
	const match = /^(\d{4})-(\d{4})$/.exec(year || '')
	if (!match) {
		return ''
	}
	const start = Number(match[1]) + 1
	return `${start}-${start + 1}`
}

/**
 * A draft copy of a plan for the next intake year, with the same lines and norms.
 *
 * @param {object} plan The hour plan.
 * @return {object} The new plan to POST.
 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
 */
export function copyForNextIntake(plan) {
	const intakeYear = nextSchoolYear(plan.intakeYear)
	const name = plan.name ? plan.name.replace(plan.intakeYear, intakeYear) : null
	return {
		name: name === plan.name ? `${plan.name} (${intakeYear})` : name,
		programmeId: plan.programmeId,
		intakeYear,
		durationYears: plan.durationYears,
		periodsPerYear: (plan.periodsPerYear || []).map((p) => ({ ...p })),
		lines: (plan.lines || []).map((l) => ({ ...l })),
		yearNorms: (plan.yearNorms || []).map((n) => ({ ...n })),
		lifecycle: 'draft',
	}
}

/**
 * The activity list as CSV, one row per activity.
 *
 * @param {Array<object>} activities Rows from `GET /api/hour-plans/activities`.
 * @param {object} headers Column titles by key.
 * @return {string} The CSV text.
 * @spec openspec/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
 */
export function activitiesCsv(activities, headers) {
	const keys = [
		'cohortName',
		'programmeYear',
		'courseName',
		'periodCode',
		'contactHours',
		'otherHours',
		'activityKind',
		'teacherIds',
	]
	const quote = (value) => {
		const text = Array.isArray(value) ? value.join(' ') : String(value ?? '')
		return /[",\n;]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text
	}
	const lines = [keys.map((k) => quote(headers[k] ?? k)).join(',')]
	for (const row of activities || []) {
		lines.push(keys.map((k) => quote(row[k])).join(','))
	}
	return lines.join('\n') + '\n'
}
