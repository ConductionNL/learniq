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
 * The columns of the grid: every year of the programme times every period,
 * or one column per year when the plan has no periods.
 *
 * @param {object} plan The hour plan.
 * @return {Array<{programmeYear: number, periodCode: (string|null), label: string}>} The columns.
 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
 */
export function planColumns(plan) {
	const years = Math.max(1, Number(plan?.durationYears) || 1)
	const periods = Array.isArray(plan?.periodsPerYear) ? plan.periodsPerYear : []
	const columns = []
	for (let year = 1; year <= years; year++) {
		if (periods.length === 0) {
			columns.push({ programmeYear: year, periodCode: null, label: '' })
			continue
		}
		for (const period of periods) {
			columns.push({
				programmeYear: year,
				periodCode: period.periodCode,
				label: period.label || period.periodCode,
			})
		}
	}
	return columns
}

/**
 * The contact hours in one cell of the grid.
 *
 * @param {Array<object>} lines The plan lines.
 * @param {string} courseId The course.
 * @param {number} programmeYear The year of the programme.
 * @param {string|null} periodCode The period, or null for the whole year.
 * @return {number} The contact hours, 0 when there is no line.
 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
 */
export function cellHours(lines, courseId, programmeYear, periodCode) {
	const line = (lines || []).find(
		(l) =>
			l.courseId === courseId
			&& Number(l.programmeYear) === programmeYear
			&& (l.periodCode ?? null) === (periodCode ?? null),
	)
	return line ? Number(line.contactHours) || 0 : 0
}

/**
 * The lines with one cell set. A cell set to 0 drops a line that carries
 * nothing else; a line's other hours, kind and remark are kept.
 *
 * @param {Array<object>} lines The plan lines.
 * @param {string} courseId The course.
 * @param {number} programmeYear The year of the programme.
 * @param {string|null} periodCode The period, or null.
 * @param {number|string} hours The contact hours entered.
 * @return {Array<object>} New lines.
 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
 */
export function setCell(lines, courseId, programmeYear, periodCode, hours) {
	const value = Math.max(0, Number(hours) || 0)
	const matches = (l) =>
		l.courseId === courseId
		&& Number(l.programmeYear) === programmeYear
		&& (l.periodCode ?? null) === (periodCode ?? null)
	const out = []
	let found = false
	for (const line of lines || []) {
		if (!matches(line)) {
			out.push(line)
			continue
		}
		found = true
		if (value === 0 && !(Number(line.otherHours) > 0)) {
			continue
		}
		out.push({ ...line, contactHours: value })
	}
	if (!found && value > 0) {
		out.push({
			courseId,
			programmeYear,
			periodCode: periodCode ?? null,
			contactHours: value,
			otherHours: 0,
			activityKind: 'lesson',
		})
	}
	return out
}

/**
 * Totals per year of the programme, with the norm and the shortfall.
 *
 * @param {object} plan The hour plan.
 * @return {Array<{programmeYear: number, contactHours: number, otherHours: number, norm: (number|null), shortBy: number}>} One row per year.
 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
 */
export function yearTotals(plan) {
	const years = Math.max(1, Number(plan?.durationYears) || 1)
	const norms = Array.isArray(plan?.yearNorms) ? plan.yearNorms : []
	const rows = []
	for (let year = 1; year <= years; year++) {
		let contact = 0
		let other = 0
		for (const line of plan?.lines || []) {
			if (Number(line.programmeYear) === year) {
				contact += Number(line.contactHours) || 0
				other += Number(line.otherHours) || 0
			}
		}
		const normRow = norms.find((n) => Number(n.programmeYear) === year)
		const norm =
			normRow
			&& normRow.contactHours !== undefined
			&& normRow.contactHours !== null
				? Number(normRow.contactHours)
				: null
		rows.push({
			programmeYear: year,
			contactHours: contact,
			otherHours: other,
			norm,
			shortBy: norm !== null && contact < norm ? norm - contact : 0,
		})
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
