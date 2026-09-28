// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// test-screen-autosave-and-deadline: the timer counts to the server's
// deadline, corrected for a wrong browser clock, and the saved rows carry no
// scores.
//
// @spec openspec/changes/test-screen-autosave-and-deadline/specs/assessment/spec.md#requirement-the-in-app-test-screen-shows-the-servers-deadline-and-saves-answers-as-the-learner-works

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	answersFromResult,
	responsesPayload,
	secondsUntilDeadline,
	serverOffsetMs,
} from '../../src/utils/attemptClock.js'

const NOW = Date.parse('2026-09-28T09:00:00Z')

test('counts down to the server deadline, extra time included', () => {
	// 30 minutes with 50 percent extra time: 45 minutes from a 09:00 start.
	assert.equal(secondsUntilDeadline('2026-09-28T09:45:00Z', NOW), 45 * 60)
})

test('a browser clock that runs ahead does not steal time', () => {
	const offset = serverOffsetMs('Mon, 28 Sep 2026 08:55:00 GMT', NOW)
	assert.equal(offset, -5 * 60 * 1000)
	assert.equal(secondsUntilDeadline('2026-09-28T09:30:00Z', NOW, offset), 35 * 60)
})

test('never below zero, and no deadline means no timer', () => {
	assert.equal(secondsUntilDeadline('2026-09-28T08:00:00Z', NOW), 0)
	assert.equal(secondsUntilDeadline(null, NOW), null)
	assert.equal(secondsUntilDeadline('not a date', NOW), null)
	assert.equal(serverOffsetMs(undefined, NOW), 0)
})

test('saved rows carry the answers and no scores', () => {
	const rows = responsesPayload([{ uuid: 'i1' }, { uuid: 'i2' }], { i1: 'B' })
	assert.deepEqual(rows, [
		{
			itemId: 'i1',
			response: { value: 'B' },
			autoScore: null,
			manualScore: null,
		},
		{
			itemId: 'i2',
			response: { value: null },
			autoScore: null,
			manualScore: null,
		},
	])
})

test('a resumed attempt shows the answers already saved', () => {
	const result = {
		responses: [
			{ itemId: 'i1', response: { value: 'B' } },
			{ itemId: 'i2', response: { value: null } },
			{ itemId: 'i3' },
		],
	}
	assert.deepEqual(answersFromResult(result), { i1: 'B' })
	assert.deepEqual(answersFromResult(null), {})
})
