// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The list reads that a screen cannot do without, pinned call by call: the
// item analysis statistics and reliability, and the per-schema reads of the
// subgroup learner context and the pupil dossier timeline. Each sends the
// read headers, throws with its own message and the status on a refusal,
// and takes the rows from results, objects or the bare answer.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
//
// @spec openspec/specs/assessment/spec.md#requirement-per-item-statistics-are-computed-from-graded-results-gated-by-a-minimum-sample-size
// @spec openspec/specs/pupil-dossier/spec.md#scenario-the-timeline-view-merges-notes-incidents-check-ins-and-the-care-chain

import assert from 'node:assert/strict'
import { afterEach, beforeEach, test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const STUBS = { '@nextcloud/router': { generateUrl: (url) => `/nc${url}` } }
const READ = { 'OCS-APIREQUEST': 'true', Accept: 'application/json' }

let calls
let answer

beforeEach(() => {
	calls = []
	answer = { status: 200, json: {} }
	globalThis.window = { OC: {} }
	globalThis.fetch = async (url, init) => {
		calls.push({ url, init })
		return { ok: answer.status < 300, status: answer.status, json: async () => answer.json }
	}
})

afterEach(() => {
	delete globalThis.fetch
	delete globalThis.window
})

const SHAPES = [
	[{ results: [{ id: 'r' }] }, [{ id: 'r' }]],
	[{ objects: [{ id: 'o' }] }, [{ id: 'o' }]],
	[[{ id: 'a' }], [{ id: 'a' }]],
	[{ total: 0 }, { total: 0 }],
]

for (const file of ['src/views/GroupPlanSubgroupLearnerContext.vue', 'src/views/PupilDossierTimelineView.vue']) {
	test(`${file}: fetchSchema reads one schema's rows and throws on a refusal`, async () => {
		const vm = instanceOf(await loadSfc(file, STUBS), { $route: { query: {}, params: {} } })
		for (const [json, expected] of SHAPES) {
			answer = { status: 200, json }
			assert.deepEqual(await vm.fetchSchema('dossier-note', 'learnerId=p1&_limit=200'), expected)
		}
		assert.deepEqual(calls[0], {
			url: '/nc/apps/openregister/api/objects/learniq/dossier-note?learnerId=p1&_limit=200',
			init: { headers: READ },
		})
		answer = { status: 404, json: null }
		await assert.rejects(() => vm.fetchSchema('incident', 'x=1'), { message: 'incident fetch failed: 404' })
	})
}

test('ItemAnalysisView reads the item statistics and the reliability, and throws on a refusal', async () => {
	const vm = instanceOf(await loadSfc('src/views/ItemAnalysisView.vue', STUBS), { itemId: 'i1', assessmentId: 'a1' })
	answer = { status: 200, json: { results: [{ itemId: 'i1', n: 1 }, { itemId: 'i2' }, { itemId: 'i1', n: 2 }] } }
	await vm.loadItemStatistics()
	assert.deepEqual(vm.itemStatisticsList, [{ itemId: 'i1', n: 1 }, { itemId: 'i1', n: 2 }])
	answer = { status: 200, json: [{ assessmentId: 'a0' }, { assessmentId: 'a1', alpha: 0.8 }] }
	await vm.loadReliability()
	assert.deepEqual(vm.reliability, { assessmentId: 'a1', alpha: 0.8 })
	answer = { status: 200, json: { objects: [] } }
	await vm.loadReliability()
	assert.equal(vm.reliability, null)
	assert.deepEqual(calls.map((c) => [c.url, c.init]), [
		['/nc/apps/openregister/api/objects/learniq/item-statistics?_limit=200', { headers: READ }],
		['/nc/apps/openregister/api/objects/learniq/assessment-reliability?_limit=200', { headers: READ }],
		['/nc/apps/openregister/api/objects/learniq/assessment-reliability?_limit=200', { headers: READ }],
	])
	answer = { status: 500, json: null }
	await assert.rejects(() => vm.loadItemStatistics(), { message: 'ItemStatistics fetch failed: 500' })
	await assert.rejects(() => vm.loadReliability(), { message: 'AssessmentReliability fetch failed: 500' })
})
