/**
 * Standby hours (timetabling-standby-slots): the substitution candidates as
 * select options. The planning grid is CnSlotGrid, configured in
 * manifest.d/learning.json.
 *
 * Pure functions, tested in tests/unit-js/standby.test.mjs.
 *
 * @spec openspec/specs/timetabling/spec.md#requirement-a-coordinator-plans-standby-hours
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

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
