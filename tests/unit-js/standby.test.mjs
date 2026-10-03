// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// timetabling-standby-slots: the planning grid, the candidate options in the
// server's order with their reasons, and the school year bounds.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	candidateOptions,
	planningGrid,
	schoolYearBounds,
} from '../../src/utils/standby.js'

test('the grid has a row per time window and the slots in their weekday cell', () => {
	const rows = planningGrid([
		{
			id: 'a',
			teacherId: 'eva',
			weekday: 'tuesday',
			startsAt: '10:15',
			endsAt: '11:05',
		},
		{
			id: 'b',
			teacherId: 'jan',
			weekday: 'tuesday',
			startsAt: '10:15',
			endsAt: '11:05',
		},
		{
			id: 'c',
			teacherId: 'eva',
			weekday: 'monday',
			startsAt: '08:30',
			endsAt: '09:20',
		},
		{
			id: 'd',
			teacherId: 'piet',
			date: '2026-10-01',
			weekday: null,
			startsAt: '08:30',
			endsAt: '09:20',
		},
	])
	assert.equal(rows.length, 2)
	assert.equal(rows[0].startsAt, '08:30')
	assert.deepEqual(
		rows[1].cells.tuesday.map((s) => s.teacherId),
		['eva', 'jan'],
	)
	assert.deepEqual(
		rows[0].cells.monday.map((s) => s.id),
		['c'],
	)
})

test('the options keep the order and name the reason', () => {
	const t = (text, vars = {}) => text.replace(/\{(\w+)\}/g, (_, k) => vars[k])
	const options = candidateOptions(
		[
			{
				userId: 'eva',
				displayName: 'Eva',
				group: 'standby',
				slot: { startsAt: '10:15', endsAt: '11:05' },
			},
			{ userId: 'free1', displayName: 'Free', group: 'free' },
			{ userId: 'busy', displayName: 'Busy', group: 'busy' },
		],
		t,
	)
	assert.deepEqual(
		options.map((o) => o.value),
		['eva', 'free1', 'busy'],
	)
	assert.equal(options[0].label, 'Eva (On standby 10:15 to 11:05)')
	assert.equal(options[2].label, 'Busy (On standby, but has a lesson then)')
})

test('the school year runs from 1 August', () => {
	assert.deepEqual(schoolYearBounds(new Date(2026, 8, 29)), {
		validFrom: '2026-08-01',
		validUntil: '2027-07-31',
	})
	assert.deepEqual(schoolYearBounds(new Date(2027, 2, 1)), {
		validFrom: '2026-08-01',
		validUntil: '2027-07-31',
	})
})
