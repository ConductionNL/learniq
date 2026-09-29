/**
 * Room use report (timetabling-room-utilisation): percentages, the default
 * window and the CSV export.
 *
 * Pure functions, tested in tests/unit-js/roomUse.test.mjs.
 *
 * @spec openspec/changes/timetabling-room-utilisation/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/**
 * A ratio as a whole percentage, or '' when unknown.
 *
 * @param {number|null} ratio A ratio between 0 and 1 (or more).
 * @return {string} For example `92%`.
 * @spec openspec/changes/timetabling-room-utilisation/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
 */
export function percent(ratio) {
	if (ratio === null || ratio === undefined || Number.isNaN(Number(ratio))) {
		return ''
	}
	return `${Math.round(Number(ratio) * 100)}%`
}

/**
 * Monday to Friday of the week containing `date`, as `Y-m-d`.
 *
 * @param {Date} date Any day of the week.
 * @return {{from: string, to: string}} The window, both days inclusive.
 * @spec openspec/changes/timetabling-room-utilisation/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
 */
export function workWeekOf(date) {
	const monday = new Date(date.getFullYear(), date.getMonth(), date.getDate())
	monday.setDate(monday.getDate() - ((monday.getDay() + 6) % 7))
	const friday = new Date(monday)
	friday.setDate(monday.getDate() + 4)
	const iso = (d) =>
		`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
	return { from: iso(monday), to: iso(friday) }
}

/**
 * The heat level of a grid cell, for its colour: 0 (empty) to 4 (all taken).
 *
 * @param {number} share The share of rooms in use.
 * @return {number} The level.
 * @spec openspec/changes/timetabling-room-utilisation/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
 */
export function heatLevel(share) {
	const value = Number(share) || 0
	if (value >= 0.95) return 4
	if (value >= 0.7) return 3
	if (value >= 0.4) return 2
	if (value > 0) return 1
	return 0
}

/**
 * The room table as CSV.
 *
 * @param {Array<object>} rooms Rows from `GET /api/reports/room-use`.
 * @param {object} headers Column titles by key.
 * @return {string} The CSV text.
 * @spec openspec/changes/timetabling-room-utilisation/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
 */
export function roomsCsv(rooms, headers) {
	const keys = [
		'name',
		'kind',
		'buildingCode',
		'capacity',
		'hoursInUse',
		'hoursOpen',
		'occupancy',
		'fill',
	]
	const quote = (value) => {
		const text = String(value ?? '')
		return /[",\n;]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text
	}
	const lines = [keys.map((k) => quote(headers[k] ?? k)).join(',')]
	for (const row of rooms || []) {
		lines.push(
			keys
				.map((k) =>
					k === 'occupancy' || k === 'fill' ? percent(row[k]) : row[k],
				)
				.map(quote)
				.join(','),
		)
	}
	return lines.join('\n') + '\n'
}
