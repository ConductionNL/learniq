// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// timetabling-room-utilisation: percentages, the default work week, the heat
// levels of the grid and the CSV export of the room table.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { heatLevel, percent, roomsCsv, workWeekOf } from '../../src/utils/roomUse.js'

test('a ratio reads as a whole percentage, unknown as empty', () => {
	assert.equal(percent(0.923), '92%')
	assert.equal(percent(null), '')
	assert.equal(percent(1.2), '120%')
})

test('the default window is Monday to Friday of this week', () => {
	assert.deepEqual(workWeekOf(new Date(2026, 3, 23)), {
		from: '2026-04-20',
		to: '2026-04-24',
	})
	assert.deepEqual(workWeekOf(new Date(2026, 3, 26)), {
		from: '2026-04-20',
		to: '2026-04-24',
	})
})

test('the grid colours from empty to all taken', () => {
	assert.deepEqual([0, 0.1, 0.5, 0.8, 1].map(heatLevel), [0, 1, 2, 3, 4])
})

test('the CSV shows ratios as percentages', () => {
	const csv = roomsCsv(
		[
			{
				name: 'Gymzaal',
				kind: 'gym',
				buildingCode: '00X1',
				capacity: 35,
				hoursInUse: 32,
				hoursOpen: 35,
				occupancy: 0.914,
				fill: 0.8,
			},
		],
		{ name: 'Room' },
	)
	assert.equal(csv.split('\n')[1], 'Gymzaal,gym,00X1,35,32,35,91%,80%')
})
