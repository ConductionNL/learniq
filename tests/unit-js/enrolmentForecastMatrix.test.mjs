// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The forecast tables are read-only CnDataMatrix grids over the figures the
// server computed: no formula columns, numbers totalled, estimates marked.

import assert from 'node:assert/strict'
import test from 'node:test'
import { applyCellEdit, editableColumn, intakeRows, matrixColumns, rateRows, subjectRows, yearRows } from '../../src/utils/enrolmentForecastMatrix.js'

test('a programme year becomes one row with the server figures', () => {
	const [row] = yearRows([{ programmeId: 'p', programmeName: 'havo', programmeYear: 4, learners: 104, groupsNeeded: 4 }])
	assert.deepEqual(row, { id: 'p-4', label: 'havo', programmeYear: '4', learners: 104, groupsNeeded: 4 })
	assert.deepEqual(yearRows(undefined), [])
})

test('an estimated subject figure says so, a counted one stays blank', () => {
	const rows = subjectRows([
		{ programmeId: 'p', programmeName: 'havo', programmeYear: 4, courseId: 'e', courseName: 'Economie', learners: 104, groupsNeeded: 4, isEstimated: true },
		{ programmeId: 'p', programmeName: 'havo', programmeYear: 4, courseId: 'b', courseName: 'Biologie', learners: 60, groupsNeeded: 2, isEstimated: false },
	], 'geschat')
	assert.deepEqual(rows.map((row) => row.estimate), ['geschat', ''])
	assert.equal(rows[0].id, 'p-4-e')
})

test('the columns are read only, without formulas, and only numbers add up', () => {
	const columns = matrixColumns([['programmeYear', 'Year', 'string'], ['learners', 'Learners', 'number']])
	assert.ok(columns.every((column) => column.readOnly === true && column.formula === undefined))
	assert.deepEqual(columns.map((column) => column.aggregate), ['none', 'sum'])
})

test('the rates and the intake are editable matrix rows that write back into the scenario', () => {
	const rates = [{ programmeId: 'p', programmeYear: 5, upRate: 0.78, repeatRate: 0.12, leaveRate: 0.05 }]
	const [row] = rateRows(rates, () => 'vwo')
	assert.deepEqual(row, { id: '0', label: 'vwo', programmeYear: '5', upRate: 0.78, repeatRate: 0.12, leaveRate: 0.05 })
	assert.equal(applyCellEdit(rates, { rowId: '0', colKey: 'upRate', value: 0.83 }), true)
	assert.equal(rates[0].upRate, 0.83)
	assert.equal(applyCellEdit(rates, { rowId: '4', colKey: 'upRate', value: 1 }), false)
	const intake = [{ programmeId: 'p', expected: 120 }]
	assert.deepEqual(intakeRows(intake, () => 'havo'), [{ id: '0', label: 'havo', expected: 120 }])
	const column = editableColumn('upRate', 'Over')
	assert.equal(column.readOnly, undefined)
	assert.equal(column.type, 'number')
})
