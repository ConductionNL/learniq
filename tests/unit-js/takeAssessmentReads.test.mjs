// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Two reads of the assessment screen, pinned call by call: the assessment
// itself (a refusal throws with its status, and marks a 404 as not found)
// and the drawn items (each read on its own; a refused or empty one is
// left out, the order of the draw is kept).
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
//
// @spec openspec/specs/assessment/spec.md#requirement-every-assessmentresult-persists-a-frozen-server-resolved-snapshot-of-what-was-presented

import assert from 'node:assert/strict'
import { afterEach, beforeEach, test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const STUBS = { '@nextcloud/router': { generateUrl: (url) => `/nc${url}` } }
const READ = { 'OCS-APIREQUEST': 'true', Accept: 'application/json' }
const OBJECTS = '/nc/apps/openregister/api/objects/learniq'

let calls
let reply

beforeEach(() => {
	calls = []
	reply = () => ({ status: 200, json: {} })
	globalThis.window = { OC: {}, addEventListener: () => {}, removeEventListener: () => {} }
	globalThis.fetch = async (url, init) => {
		calls.push({ url, init })
		const answer = reply(url)
		return { ok: answer.status < 300, status: answer.status, json: async () => answer.json }
	}
})

afterEach(() => {
	delete globalThis.fetch
	delete globalThis.window
})

/**
 * An assessment screen instance.
 *
 * @return {Promise<object>} The instance.
 */
async function screen() {
	return instanceOf(await loadSfc('src/views/TakeAssessmentView.vue', STUBS), { assessmentId: 'a1' })
}

test('the assessment is read, wrapped or bare, and a refusal throws with its status', async () => {
	const vm = await screen()
	reply = () => ({ status: 200, json: { object: { id: 'a1' } } })
	await vm.loadAssessment('a1')
	assert.deepEqual(vm.assessment, { id: 'a1' })
	reply = () => ({ status: 200, json: { id: 'bare' } })
	await vm.loadAssessment('a1')
	assert.deepEqual(vm.assessment, { id: 'bare' })
	assert.deepEqual(calls[0], { url: `${OBJECTS}/exam/a1`, init: { headers: READ } })
	for (const [status, notFound] of [[404, true], [500, false]]) {
		reply = () => ({ status, json: null })
		await assert.rejects(() => vm.loadAssessment('x'), (err) => {
			assert.equal(err.message, `Assessment fetch failed: ${status}`)
			assert.equal(err.status, status)
			assert.equal(err.notFound, notFound)
			return true
		})
	}
})

test('the drawn items are read one by one, in draw order, without the refused ones', async () => {
	const vm = await screen()
	vm.result = { drawnItemRefs: [] }
	vm.items = ['stale']
	await vm.loadItems()
	assert.deepEqual(vm.items, [])
	assert.equal(calls.length, 0)

	vm.result = { drawnItemRefs: [{ itemId: 'i1' }, { itemId: '' }, { itemId: 'i2' }, { itemId: 'i3' }, { itemId: 'i4' }] }
	reply = (url) => {
		if (url.endsWith('/i1')) return { status: 200, json: { object: { id: 'i1' } } }
		if (url.endsWith('/i2')) return { status: 403, json: null }
		if (url.endsWith('/i3')) return { status: 200, json: { id: 'i3' } }
		return { status: 200, json: { object: null } }
	}
	await vm.loadItems()
	assert.deepEqual(vm.items, [{ id: 'i1' }, { id: 'i3' }, { object: null }])
	assert.deepEqual(calls.map((c) => [c.url, c.init]), [
		[`${OBJECTS}/Item/i1`, { headers: READ }],
		[`${OBJECTS}/Item/i2`, { headers: READ }],
		[`${OBJECTS}/Item/i3`, { headers: READ }],
		[`${OBJECTS}/Item/i4`, { headers: READ }],
	])
})
