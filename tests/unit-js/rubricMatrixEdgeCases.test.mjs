// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The rubric matrix helpers given no rubric, criteria without levels and no
// picks.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { levelsFromPicks, rubricPicks, rubricRows } from '../../src/utils/rubricMatrix.js'

test('no rubric gives no rows', () => {
	assert.deepEqual(rubricRows(null), [])
	assert.deepEqual(rubricRows({}), [])
})

test('a criterion without levels gives a row without options', () => {
	assert.deepEqual(rubricRows({ criteria: [{ criterionId: 'c', label: 'C', weight: 2 }] }), [
		{ id: 'c', label: 'C', weight: 2, options: [] },
	])
})

test('no selected levels give no picks', () => {
	assert.deepEqual(rubricPicks(null), {})
	assert.deepEqual(rubricPicks(undefined), {})
})

test('no rubric, no picks or a criterion without levels select nothing', () => {
	const rubric = { criteria: [{ criterionId: 'c' }, { criterionId: 'd', levels: [{ levelId: 'l', points: 3 }] }] }
	assert.deepEqual(levelsFromPicks(null, { c: 'l' }), {})
	assert.deepEqual(levelsFromPicks(rubric, null), {})
	assert.deepEqual(levelsFromPicks(rubric, { c: 'l', d: 'l' }), { d: { levelId: 'l', points: 3 } })
})
