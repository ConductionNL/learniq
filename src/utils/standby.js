/**
 * Standby hours (timetabling-standby-slots): the planning grid and the
 * substitution candidates as select options.
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
		if (isWeeklySlot(slot)) {
			placeSlot(gridRowFor(rows, slot), slot)
		}
	}
	return [...rows.values()].sort((a, b) =>
		(a.startsAt + a.endsAt).localeCompare(b.startsAt + b.endsAt),
	)
}

/**
 * Whether a slot repeats weekly: it has a weekday and no date.
 *
 * @param {object} slot A standby slot.
 * @return {boolean}
 */
function isWeeklySlot(slot) {
	return !(!slot.weekday || slot.date)
}

/**
 * The grid row of a slot's time window, added with an empty cell per weekday
 * when it is the first slot in that window.
 *
 * @param {Map<string, object>} rows The rows so far, by window.
 * @param {object} slot A weekly slot.
 * @return {{startsAt: string, endsAt: string, cells: object}} The row.
 */
function gridRowFor(rows, slot) {
	const key = `${slot.startsAt}-${slot.endsAt}`
	if (!rows.has(key)) {
		rows.set(key, {
			startsAt: slot.startsAt,
			endsAt: slot.endsAt,
			cells: Object.fromEntries(WEEKDAYS.map((d) => [d, []])),
		})
	}
	return rows.get(key)
}

/**
 * Put a slot in its weekday cell; a weekday without a column is left out.
 *
 * @param {{cells: object}} row The slot's grid row.
 * @param {object} slot A weekly slot.
 */
function placeSlot(row, slot) {
	if (row.cells[slot.weekday]) {
		row.cells[slot.weekday].push(slot)
	}
}

/**
 * The substitution candidates as options for the select, in the server's
 * order (standby, free, busy standby), each labelled with its reason.
 *
 * @param {Array<object>} candidates Rows from `GET /api/substitution/candidates`.
 * @param {(text: string, vars?: object) => string} translate `t` bound to learniq, for the reason texts.
 * @return {Array<{value: string, label: string, group: string}>} The options.
 * @spec openspec/specs/timetabling/spec.md#requirement-the-substitution-dialog-offers-standby-teachers-first
 */
export function candidateOptions(candidates, translate) {
	return (candidates || []).map((c) => {
		let reason = translate('Free then')
		if (c.group === 'standby') {
			reason = translate('On standby {from} to {to}', {
				from: c.slot?.startsAt ?? '',
				to: c.slot?.endsAt ?? '',
			})
		} else if (c.group === 'busy') {
			reason = translate('On standby, but has a lesson then')
		}
		return {
			value: c.userId,
			label: `${c.displayName} (${reason})`,
			group: c.group,
		}
	})
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
