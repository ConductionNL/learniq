// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The trainer's Today names the part of the day that has begun and is not
// registered, with how many were there in the part before it.
// Run by `npm run test:js-unit`.
//
// @spec openspec/specs/attendance/spec.md#scenario-the-afternoon-has-begun

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { partDueNotice, sessionsWithPartsToday } from '../../src/utils/partDue.js'

test('the afternoon has begun: due afternoon, and this morning 8 of 9 with one late', () => {
	const summary = {
		parts: [
			{ key: 'morning', begun: true, registered: true, there: 8, of: 9, late: [{ learnerId: 'mehmet', arrivedAt: '10:15' }] },
			{ key: 'afternoon', begun: true, registered: false, there: 0, of: 9, late: [] },
		],
		due: 'afternoon',
	}

	assert.deepEqual(partDueNotice(summary), {
		due: 'afternoon',
		earlier: { key: 'morning', there: 8, of: 9, late: 1 },
	})
})

test('the morning due has no earlier part; nothing due or an unknown part says nothing', () => {
	const parts = [
		{ key: 'morning', begun: true, registered: false, there: 0, of: 9 },
		{ key: 'afternoon', begun: false, registered: false, there: 0, of: 9 },
	]
	assert.deepEqual(partDueNotice({ parts, due: 'morning' }), { due: 'morning', earlier: null })
	assert.equal(partDueNotice({ parts, due: null }), null)
	assert.equal(partDueNotice({ parts, due: 'evening' }), null)
	assert.equal(partDueNotice(null), null)
})

test('only today\'s sessions split into parts are asked about', () => {
	const now = new Date(2026, 9, 5, 13, 5)
	const sessions = [
		{ id: 's1', startsAt: new Date(2026, 9, 5, 9, 0).toISOString(), parts: [{ key: 'morning' }, { key: 'afternoon' }] },
		{ id: 's2', startsAt: new Date(2026, 9, 5, 9, 0).toISOString(), parts: [] },
		{ id: 's3', startsAt: new Date(2026, 9, 4, 9, 0).toISOString(), parts: [{ key: 'morning' }] },
		{ id: 's4', startsAt: 'not a date', parts: [{ key: 'morning' }] },
	]
	assert.deepEqual(sessionsWithPartsToday(sessions, now).map((s) => s.id), ['s1'])
})
