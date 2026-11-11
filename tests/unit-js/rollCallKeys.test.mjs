// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The keyboard on a roll-call row (attendance-roll-call): a letter marks the
// pupil, the arrows move between rows, a modified key or one typed into a
// control inside the row is left alone. Pins what RollCallView's onKey decided
// inline before the rule moved into src/utils/rollCallKeys.js.
//
// Run via `npm run test:js-unit`.

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { registerParams, rowKeyAction } from '../../src/utils/rollCallKeys.js'

const row = {}

/**
 * A key event on the row itself, without modifiers unless given.
 *
 * @param {string} key The key.
 * @param {object} extra Fields to override.
 * @return {object} The event.
 */
function key(key, extra = {}) {
	return { key, target: row, currentTarget: row, ctrlKey: false, metaKey: false, altKey: false, ...extra }
}

test('the arrows move one row down or up', () => {
	assert.deepEqual(rowKeyAction(key('ArrowDown')), { step: 1 })
	assert.deepEqual(rowKeyAction(key('ArrowUp')), { step: -1 })
})

test('a letter marks, in either case', () => {
	assert.deepEqual(rowKeyAction(key('p')), { status: 'present' })
	assert.deepEqual(rowKeyAction(key('L')), { status: 'late' })
	assert.deepEqual(rowKeyAction(key('t')), { status: 'absent-excused' })
	assert.deepEqual(rowKeyAction(key('O')), { status: 'absent-unexcused' })
})

test('other keys do nothing', () => {
	assert.equal(rowKeyAction(key('x')), null)
	assert.equal(rowKeyAction(key('Enter')), null)
	assert.equal(rowKeyAction(key('ArrowLeft')), null)
})

test('a modifier or a key inside a control is left to the browser', () => {
	for (const extra of [{ ctrlKey: true }, { metaKey: true }, { altKey: true }, { target: {} }]) {
		assert.equal(rowKeyAction(key('p', extra)), null)
		assert.equal(rowKeyAction(key('ArrowDown', extra)), null)
	}
})

test('registerParams leaves out an empty group, day or lesson', () => {
	assert.deepEqual(registerParams({ cohortId: 'c1', date: '2026-11-11', sessionId: 's1' }), {
		cohortId: 'c1', date: '2026-11-11', sessionId: 's1',
	})
	assert.deepEqual(registerParams({ cohortId: '', date: null }), {
		cohortId: undefined, date: undefined, sessionId: undefined,
	})
})
