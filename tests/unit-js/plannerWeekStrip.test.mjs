// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The planner's week strip (week-timetable-grid T4): this week's five working
// days, each with its course days and their occupancy; days that passed are
// marked past. Run via `node --test tests/unit-js/` (`test:js-unit`).
//
// @spec openspec/specs/personal-timetable/spec.md#requirement-a-training-planner-sees-this-weeks-course-days-with-their-occupancy

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { weekStrip, workingDaysOf } from '../../src/utils/plannerWeekStrip.js'

const ANSWER = {
	today: '2026-10-07',
	occupancy: {
		until: '2026-10-23',
		days: [
			{ id: 'c1', courseName: 'Warmtepomp installeren', date: '2026-10-07', participants: 9, capacity: 12, trainers: ['sophie'], rooms: ['Hal 1'] },
			{ id: 'c2', name: 'F-gassen', date: '2026-10-09', participants: 4, trainers: [], rooms: [] },
			{ id: 'c3', courseName: 'Later', date: '2026-10-14', participants: 1, capacity: 8, trainers: [], rooms: [] },
			{ id: '', courseName: 'Broken', date: '2026-10-08' },
		],
	},
}

test('workingDaysOf gives Monday to Friday of the week', () => {
	assert.deepEqual(workingDaysOf('2026-10-07'), ['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09'])
	assert.deepEqual(workingDaysOf('2026-10-11'), ['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09'])
	assert.deepEqual(workingDaysOf('2026-10-05')[0], '2026-10-05')
})

test('the strip holds this week\'s five days with today and the past marked', () => {
	const strip = weekStrip(ANSWER, 'en')
	assert.deepEqual(strip.map((day) => day.date), ['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09'])
	assert.deepEqual(strip.map((day) => day.today), [false, false, true, false, false])
	assert.deepEqual(strip.map((day) => day.past), [true, true, false, false, false])
	assert.match(strip[2].label, /Wednesday/)
})

test('each day lists its course days with trainers, rooms and occupancy', () => {
	const strip = weekStrip(ANSWER, 'en')
	assert.deepEqual(strip[2].days, [
		{ id: 'c1', course: 'Warmtepomp installeren', trainers: ['sophie'], rooms: ['Hal 1'], taken: 9, capacity: 12 },
	])
	assert.deepEqual(strip[4].days[0], { id: 'c2', course: 'F-gassen', trainers: [], rooms: [], taken: 4, capacity: null })
	assert.deepEqual(strip[3].days, [])
})

test('a course day of another week stays out', () => {
	const ids = weekStrip(ANSWER, 'en').flatMap((day) => day.days.map((courseDay) => courseDay.id))
	assert.deepEqual(ids, ['c1', 'c2'])
})

test('without a today there is no strip', () => {
	assert.deepEqual(weekStrip(null, 'en'), [])
	assert.deepEqual(weekStrip({ today: 'soon' }, 'en'), [])
})
