// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// timetabling-student-choice-placement: the subject choice picker shows each
// elective's weekly slots and warns when two chosen electives, or an elective
// and a core lesson, meet at the same time. Run via `npm run test:js-unit`.

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { clashes, slotLabel, slotsOverlap } from '../../src/utils/electiveSlots.js'

const TUE_10 = { weekday: 2, start: '10:00', end: '11:00' }

test('two slots on the same day overlap when their times cross', () => {
	assert.equal(
		slotsOverlap(TUE_10, { weekday: 2, start: '10:30', end: '11:30' }),
		true,
	)
	assert.equal(
		slotsOverlap(TUE_10, { weekday: 2, start: '09:00', end: '10:01' }),
		true,
	)
})

test('back-to-back slots, or another day, do not overlap', () => {
	assert.equal(
		slotsOverlap(TUE_10, { weekday: 2, start: '11:00', end: '12:00' }),
		false,
	)
	assert.equal(
		slotsOverlap(TUE_10, { weekday: 2, start: '09:00', end: '10:00' }),
		false,
	)
	assert.equal(
		slotsOverlap(TUE_10, { weekday: 3, start: '10:00', end: '11:00' }),
		false,
	)
})

test('Drama and Robotics both on Tuesday at 10:00 clash, and the slot is named', () => {
	const found = clashes(
		[
			{ id: 'drama', label: 'Drama', slots: [TUE_10] },
			{
				id: 'robotics',
				label: 'Robotics',
				slots: [{ weekday: 2, start: '10:00', end: '10:50' }],
			},
			{
				id: 'art',
				label: 'Art',
				slots: [{ weekday: 4, start: '10:00', end: '11:00' }],
			},
		],
		[],
	)
	assert.deepEqual(found, [{ first: 'Drama', second: 'Robotics', slot: TUE_10 }])
})

test('an elective that meets during a core lesson clashes with that lesson', () => {
	const found = clashes(
		[{ id: 'drama', label: 'Drama', slots: [TUE_10] }],
		[{ weekday: 2, start: '10:15', end: '11:15', label: 'Maths' }],
	)
	assert.deepEqual(found, [{ first: 'Drama', second: 'Maths', slot: TUE_10 }])
})

test('one clash per pair even when they meet twice a week', () => {
	const wed = { weekday: 3, start: '13:00', end: '14:00' }
	const found = clashes(
		[
			{ id: 'a', label: 'A', slots: [TUE_10, wed] },
			{ id: 'b', label: 'B', slots: [TUE_10, wed] },
		],
		[],
	)
	assert.equal(found.length, 1)
})

test('missing or malformed slots never throw and never clash', () => {
	assert.deepEqual(
		clashes(
			[
				{ id: 'a', label: 'A' },
				{ id: 'b', label: 'B', slots: null },
			],
			null,
		),
		[],
	)
	assert.equal(slotsOverlap({ weekday: 2, start: 'x', end: 'y' }, TUE_10), false)
})

test('a slot reads as the day and its times, in the reader language', () => {
	assert.equal(slotLabel(TUE_10, 'en'), 'Tuesday 10:00–11:00')
	assert.equal(slotLabel(TUE_10, 'nl'), 'dinsdag 10:00–11:00')
})
