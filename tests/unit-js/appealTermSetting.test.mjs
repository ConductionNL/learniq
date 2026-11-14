// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The exam board's appeal term on the admin settings page: 42 days unless the
// school set another term, and a save that the server did not keep is an error.
//
// @spec openspec/specs/exam-board/spec.md#scenario-the-school-sets-its-own-appeal-term

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	appealUntil,
	DEFAULT_APPEAL_DAYS,
	loadAppealDays,
	parseAppealDays,
	saveAppealDays,
} from '../../src/services/appealTermSetting.js'

/**
 * A settings endpoint that answers with what it was given.
 *
 * @param {object} stored The settings the server holds.
 * @return {{http: object, url: string, sent: Array}} The endpoint.
 */
function endpointHolding(stored) {
	const sent = []
	const http = {
		get: async () => ({ data: stored }),
		put: async (url, body) => {
			sent.push(body)
			return { data: { config: { ...stored, ...body } } }
		},
	}
	return { http, url: '/apps/learniq/api/settings', sent }
}

test('a term is a whole number of days from 1 to 365', () => {
	assert.equal(parseAppealDays('28'), 28)
	assert.equal(parseAppealDays(' 365 '), 365)
	for (const raw of ['', '0', '366', '-1', '28.5', 'six weeks', null, undefined]) {
		assert.equal(parseAppealDays(raw), null, String(raw))
	}
})

test('nothing stored reads as 42 days', async () => {
	assert.equal(DEFAULT_APPEAL_DAYS, 42)
	assert.equal(await loadAppealDays(endpointHolding({})), 42)
	assert.equal(await loadAppealDays(endpointHolding({ examBoardAppealDays: '28' })), 28)
})

test('a decision of 3 October 2026 can be appealed until 14 November 2026', () => {
	const until = appealUntil(new Date(Date.UTC(2026, 9, 3)), 42)
	assert.equal(until.toISOString().slice(0, 10), '2026-11-14')
})

test('a save sends the term and refuses an answer that did not keep it', async () => {
	const endpoint = endpointHolding({})
	assert.equal(await saveAppealDays(endpoint, 28), 28)
	assert.deepEqual(endpoint.sent, [{ examBoardAppealDays: '28' }])

	const dropped = { http: { put: async () => ({ data: { config: {} } }) }, url: '' }
	await assert.rejects(saveAppealDays(dropped, 28))
})
