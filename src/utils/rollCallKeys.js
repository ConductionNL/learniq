/**
 * The roll-call page's row keyboard and register query. The keyboard on a
 * roll-call row: a letter marks the pupil, the up and down
 * arrows move to the row above or below. A key with a modifier, or one typed
 * into a control inside the row, is left to the browser.
 *
 * Plain ES module (not a .vue SFC) so it is directly importable from a Node
 * test runner without a build step.
 *
 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/** Keys that mark a pupil while their row has focus. */
export const MARK_KEYS = {
	p: 'present',
	l: 'late',
	t: 'absent-excused',
	o: 'absent-unexcused',
}

/** Arrow keys and the row they move to, relative to the focused one. */
const ARROW_STEPS = {
	ArrowDown: 1,
	ArrowUp: -1,
}

/**
 * Whether the key was pressed on the row itself, without a modifier.
 *
 * @param {KeyboardEvent} event The key event.
 * @return {boolean} Whether the row handles it.
 */
function isPlainRowKey(event) {
	return !(
		event.target !== event.currentTarget
		|| event.ctrlKey
		|| event.metaKey
		|| event.altKey
	)
}

/**
 * What a key on a roll-call row does.
 *
 * @param {KeyboardEvent} event The key event.
 * @return {{step: number}|{status: string}|null} A move by `step` rows, a mark, or nothing.
 */
export function rowKeyAction(event) {
	if (!isPlainRowKey(event)) {
		return null
	}
	if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
		return { step: ARROW_STEPS[event.key] }
	}
	const status = MARK_KEYS[event.key.toLowerCase()]
	return status ? { status } : null
}

/**
 * The register a route query asks for: its group, day and lesson, each left
 * out when empty.
 *
 * @param {{cohortId?: string, date?: string, sessionId?: string}} query The route query.
 * @return {{cohortId: string|undefined, date: string|undefined, sessionId: string|undefined}} The request params.
 */
export function registerParams(query) {
	return {
		cohortId: query.cohortId || undefined,
		date: query.date || undefined,
		sessionId: query.sessionId || undefined,
	}
}
