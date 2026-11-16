// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The planner's Today: the decision a course day under its minimum asks for,
// the occupancy of the coming days and the tile it adds to the dashboard.
// Run by `npm run test:js-unit`.
//
// @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#scenario-four-of-six

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	dayAndMonth,
	decisionCards,
	occupancyRows,
	toConfirmCount,
	weekdayAndDate,
	withTileBelow,
} from '../../src/utils/plannerToday.js'

const ANSWER = {
	today: '2026-10-05',
	decisions: [{
		id: 'c-22',
		name: 'Hybride 22 okt',
		courseName: 'Hybride warmtepomp',
		date: '2026-10-22',
		participants: 4,
		minimum: 6,
		decideBy: '2026-10-08',
		workingDaysLeft: 3,
		next: { id: 'c-26', name: 'Hybride 26 nov', date: '2026-11-26', free: 9 },
	}],
	toConfirm: { bookings: 3, enrolments: 2 },
	occupancy: {
		until: '2026-10-23',
		days: [
			{ id: 'c-15', name: 'F-gassen 15 okt', courseName: 'F-gassen', date: '2026-10-15', participants: 3, capacity: 10, minimum: 4, status: 'goes-ahead', trainers: ['ans'], rooms: ['Praktijkhal'] },
			{ id: 'c-22', name: 'Hybride 22 okt', courseName: 'Hybride warmtepomp', date: '2026-10-22', participants: 4, capacity: 12, minimum: 6, status: 'open', trainers: [], rooms: ['lokaal-2'] },
		],
	},
}

test('dates read as day and month, and with the weekday, in the reader\'s language', () => {
	assert.equal(dayAndMonth('2026-10-22', 'nl'), '22 oktober')
	assert.equal(dayAndMonth('2026-10-22', 'en-GB'), '22 October')
	assert.equal(weekdayAndDate('2026-10-08', 'nl'), 'donderdag 8 oktober')
	assert.equal(dayAndMonth('2026-02-30', 'nl'), '')
	assert.equal(dayAndMonth(null, 'nl'), '')
	assert.equal(weekdayAndDate('8 oktober', 'nl'), '')
})

test('four of six: the card names the course, the day, the minimum, the decision date and the next day with places', () => {
	assert.deepEqual(decisionCards(ANSWER, 'nl'), [{
		id: 'c-22',
		course: 'Hybride warmtepomp',
		date: '22 oktober',
		participants: 4,
		minimum: 6,
		decideBy: 'donderdag 8 oktober',
		next: { id: 'c-26', date: '26 november', free: 9 },
	}])
})

test('a day without another day with places has no next; rows without an id are dropped', () => {
	const answer = { decisions: [{ ...ANSWER.decisions[0], next: null }, { name: 'no id' }] }
	const cards = decisionCards(answer, 'nl')
	assert.equal(cards.length, 1)
	assert.equal(cards[0].next, null)
	assert.deepEqual(decisionCards(null, 'nl'), [])
})

test('occupancy marks a day short of its minimum unless it goes ahead, and a day without a trainer or room', () => {
	const rows = occupancyRows(ANSWER, 'nl')
	assert.deepEqual(rows.map((row) => [row.id, row.taken, row.capacity, row.short, row.unstaffed]), [
		['c-15', 3, 10, false, false],
		['c-22', 4, 12, true, true],
	])
	assert.equal(rows[1].date, '22 oktober')
	assert.deepEqual(occupancyRows({}, 'nl'), [])
})

test('the bookings and enrolments to confirm add up', () => {
	assert.equal(toConfirmCount(ANSWER), 5)
	assert.equal(toConfirmCount(null), 0)
})

test('the planner tile goes full width under the lowest tile with the next id, leaving the declaration as it was', () => {
	const config = {
		widgets: [{ id: 'a' }, { id: 'b' }],
		layout: [
			{ id: 1, widgetId: 'a', gridX: 0, gridY: 0, gridWidth: 6, gridHeight: 4 },
			{ id: 7, widgetId: 'b', gridX: 0, gridY: 10, gridWidth: 12, gridHeight: 3 },
		],
	}
	const next = withTileBelow(config, { id: 'planner-today', type: 'custom' }, 5)
	assert.deepEqual(next.layout.at(-1), { id: 8, widgetId: 'planner-today', gridX: 0, gridY: 13, gridWidth: 12, gridHeight: 5 })
	assert.deepEqual(next.widgets.map((w) => w.id), ['a', 'b', 'planner-today'])
	assert.equal(config.layout.length, 2)
	assert.equal(config.widgets.length, 2)
})
