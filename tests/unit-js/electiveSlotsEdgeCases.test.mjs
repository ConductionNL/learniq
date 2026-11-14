// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Elective clashes and slot labels given lists that are not lists and slots
// with missing parts.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { clashes, slotLabel } from '../../src/utils/electiveSlots.js'

const MATH = { id: 'm', label: 'Wiskunde D', slots: [{ weekday: 2, start: '10:00', end: '11:00' }] }

test('chosen electives that are not a list give no clashes', () => {
	assert.deepEqual(clashes(null, [{ weekday: 2, start: '10:00', end: '11:00', label: 'Engels' }]), [])
	assert.deepEqual(clashes({ 0: MATH }, []), [])
})

test('an elective without a slot list clashes with nothing', () => {
	assert.deepEqual(clashes([{ id: 'x', label: 'X', slots: null }, MATH], null), [])
})

test('a core lesson clash names the elective slot', () => {
	assert.deepEqual(clashes([MATH], [{ weekday: 2, start: '10:30', end: '11:30', label: 'Engels' }]), [
		{ first: 'Wiskunde D', second: 'Engels', slot: MATH.slots[0] },
	])
})

test('a slot without a weekday is a Monday, and without a locale is in English', () => {
	assert.equal(slotLabel({ start: '09:00', end: '10:00' }), 'Monday 09:00–10:00')
})

test('a missing slot or missing times give empty times', () => {
	assert.equal(slotLabel(null, 'en'), 'Monday –')
	assert.equal(slotLabel({ weekday: 3 }, 'en'), 'Wednesday –')
})
