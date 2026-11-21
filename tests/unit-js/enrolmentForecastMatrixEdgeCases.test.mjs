// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The enrolment forecast matrix helpers given no rows and edits that miss.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	applyCellEdit,
	intakeRows,
	rateRows,
	subjectRows,
	yearRows,
} from '../../src/utils/enrolmentForecastMatrix.js'

const nameOf = (id) => `name-${id}`

test('no forecast rows give no matrix rows', () => {
	assert.deepEqual(yearRows(null), [])
	assert.deepEqual(subjectRows(undefined, 'geschat'), [])
	assert.deepEqual(rateRows(null, nameOf), [])
	assert.deepEqual(intakeRows(undefined, nameOf), [])
})

test('a subject row that is not estimated has an empty estimate', () => {
	const [row] = subjectRows(
		[{ programmeId: 'p', programmeYear: 2, courseId: 'k', programmeName: 'Havo', courseName: 'Bio', learners: 30, groupsNeeded: 2 }],
		'geschat',
	)
	assert.equal(row.id, 'p-2-k')
	assert.equal(row.programmeYear, '2')
	assert.equal(row.estimate, '')
})

test('an edit on no list, without an edit or for a missing row changes nothing', () => {
	assert.equal(applyCellEdit(null, { rowId: '0', colKey: 'expected', value: 3 }), false)
	const list = [{ expected: 1 }]
	assert.equal(applyCellEdit(list, null), false)
	assert.equal(applyCellEdit(list, { rowId: '5', colKey: 'expected', value: 3 }), false)
	assert.deepEqual(list, [{ expected: 1 }])
})

test('an edit on an existing row writes the value', () => {
	const list = [{ expected: 1 }]
	assert.equal(applyCellEdit(list, { rowId: '0', colKey: 'expected', value: null }), true)
	assert.deepEqual(list, [{ expected: null }])
})
