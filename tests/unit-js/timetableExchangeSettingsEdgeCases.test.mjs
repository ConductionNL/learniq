// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The group-code map rows given no sources, no maps and incomplete rows.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { mapsToRows, rowsToMaps } from '../../src/utils/timetableExchangeSettings.js'

test('no sources give no rows', () => {
	assert.deepEqual(mapsToRows(null, { a: { x: '1' } }), {})
})

test('a source without maps, or without its own map, gets an empty row list', () => {
	assert.deepEqual(mapsToRows(['roster-xedule'], null), { 'roster-xedule': [] })
	assert.deepEqual(mapsToRows(['roster-xedule', 'roster-timeedit'], { 'roster-xedule': { '1A': 'c1' } }), {
		'roster-xedule': [{ code: '1A', cohortId: 'c1' }],
		'roster-timeedit': [],
	})
})

test('no rows give no maps, and a source without a list gets an empty map', () => {
	assert.deepEqual(rowsToMaps(null), {})
	assert.deepEqual(rowsToMaps({ 'roster-xedule': null }), { 'roster-xedule': {} })
})

test('a null row or a row missing its code or cohort is dropped; values are trimmed', () => {
	assert.deepEqual(
		rowsToMaps({ s: [null, { code: '1A' }, { cohortId: 'c1' }, { code: ' 2B ', cohortId: ' c2 ' }] }),
		{ s: { '2B': 'c2' } },
	)
})
