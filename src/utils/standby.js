/**
 * Standby hours (timetabling-standby-slots): the planning grid and the school
 * year bounds of a new slot.
 *
 * Pure functions, tested in tests/unit-js/standby.test.mjs.
 *
 * @spec openspec/specs/timetabling/spec.md#requirement-a-coordinator-plans-standby-hours
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

export const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday']

/**
 * The planning grid: one row per time window that has a weekly slot, one
 * column per weekday, the slots in each cell.
 *
 * @param {Array<object>} slots Weekly and one-off standby slots.
 * @return {Array<{startsAt: string, endsAt: string, cells: object}>} Rows by start time; cells keyed by weekday.
 * @spec openspec/specs/timetabling/spec.md#requirement-a-coordinator-plans-standby-hours
 */
export function planningGrid(slots) {
	const rows = new Map()
	for (const slot of slots || []) {
		if (!slot.weekday || slot.date) {
			continue
		}
		const key = `${slot.startsAt}-${slot.endsAt}`
		if (!rows.has(key)) {
			rows.set(key, {
				startsAt: slot.startsAt,
				endsAt: slot.endsAt,
				cells: Object.fromEntries(WEEKDAYS.map((d) => [d, []])),
			})
		}
		if (rows.get(key).cells[slot.weekday]) {
			rows.get(key).cells[slot.weekday].push(slot)
		}
	}
	return [...rows.values()].sort((a, b) =>
		(a.startsAt + a.endsAt).localeCompare(b.startsAt + b.endsAt),
	)
}

/**
 * The school year around a date, 1 August to 31 July, as `Y-m-d` bounds.
 *
 * @param {Date} date Any date.
 * @return {{validFrom: string, validUntil: string}} The bounds.
 * @spec openspec/specs/timetabling/spec.md#requirement-a-coordinator-plans-standby-hours
 */
export function schoolYearBounds(date) {
	const start = date.getMonth() >= 7 ? date.getFullYear() : date.getFullYear() - 1
	return { validFrom: `${start}-08-01`, validUntil: `${start + 1}-07-31` }
}
