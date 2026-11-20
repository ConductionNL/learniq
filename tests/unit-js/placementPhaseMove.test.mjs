// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// A card dropped on the placement board is a manual move: the handler PATCHes
// `phaseOverride: { phase }` on that placement, never the server's `phase`,
// and refuses a column that is not a phase or a card without an id.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
//
// @spec openspec/changes/bpv-coach-today-and-placement-board/specs/bpv/spec.md#requirement-a-placement-has-a-phase-that-follows-its-facts-and-says-when-it-is-late

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { phaseMovePatch, PLACEMENT_PHASES, placementPhaseMover } from '../../src/utils/placementPhaseMove.js'

/**
 * A client that records each PATCH and answers with the body merged in.
 *
 * @return {{http: object, sent: Array}} The client and what it was sent.
 */
function recordingClient() {
	const sent = []
	return {
		sent,
		http: {
			patch: async (url, body) => {
				sent.push({ url, body })
				return { data: { id: 'p-1', phase: body.phaseOverride.phase } }
			},
		},
	}
}

test('the board columns are the six phases, in order', () => {
	assert.deepEqual(PLACEMENT_PHASES, ['searching', 'agreement', 'running', 'assessment', 'rounding-off', 'done'])
})

test('a move is a phaseOverride, never a write of phase', () => {
	assert.deepEqual(phaseMovePatch('running'), { phaseOverride: { phase: 'running' } })
	assert.throws(() => phaseMovePatch('archived'), /Not a placement phase: archived/)
	assert.throws(() => phaseMovePatch(''), /Not a placement phase/)
})

test('a drop PATCHes the placement the card is, through urlOf', async () => {
	const { http, sent } = recordingClient()
	const move = placementPhaseMover(http, (url) => `/index.php${url}`)

	const saved = await move({ card: { id: 'p-1', phase: 'agreement' }, toKey: 'running' })

	assert.deepEqual(sent, [{
		url: '/index.php/apps/openregister/api/objects/learniq/bpv-placement/p-1',
		body: { phaseOverride: { phase: 'running' } },
	}])
	assert.deepEqual(saved, { id: 'p-1', phase: 'running' })
})

test('the id is read from @self, and a resolved value wins over the column key', async () => {
	const { http, sent } = recordingClient()
	await placementPhaseMover(http)({ card: { '@self': { id: 'p 2' } }, toKey: 'x', value: 'done' })

	assert.equal(sent[0].url, '/apps/openregister/api/objects/learniq/bpv-placement/p%202')
	assert.deepEqual(sent[0].body, { phaseOverride: { phase: 'done' } })
})

test('a card without an id or a column that is no phase sends nothing', async () => {
	const { http, sent } = recordingClient()
	const move = placementPhaseMover(http)
	await assert.rejects(move({ card: {}, toKey: 'running' }), /has no id/)
	await assert.rejects(move({ card: { id: 'p-1' }, toKey: 'archived' }), /Not a placement phase/)
	assert.deepEqual(sent, [])
})
