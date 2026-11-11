// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The reads of the grade impact page, pinned call by call: the grade entry
// (a refusal throws), its curriculum plan, the other published entries of
// the period and the final grade (a refusal leaves each untouched).
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
//
// @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-30

import assert from 'node:assert/strict'
import { afterEach, beforeEach, test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const STUBS = { '@nextcloud/router': { generateUrl: (url) => `/nc${url}` } }
const READ = { 'OCS-APIREQUEST': 'true', Accept: 'application/json' }
const OBJECTS = '/nc/apps/openregister/api/objects/learniq'
const ENTRY = { learnerId: 'p 1', curriculumPlanId: 'c1', period: 'P1' }

let calls
let answer

beforeEach(() => {
	calls = []
	answer = { status: 200, json: {} }
	globalThis.fetch = async (url, init) => {
		calls.push({ url, init })
		return { ok: answer.status < 300, status: answer.status, json: async () => answer.json }
	}
})

afterEach(() => {
	delete globalThis.fetch
})

/**
 * A grade impact page instance.
 *
 * @return {Promise<object>} The instance.
 */
async function page() {
	return instanceOf(await loadSfc('src/views/GradeImpactDetail.vue', STUBS), { id: 'g1' })
}

test('the grade entry is read, wrapped or bare, and a refusal throws', async () => {
	const vm = await page()
	answer = { status: 200, json: { object: { id: 'g1' } } }
	await vm.loadEntry('g1')
	assert.deepEqual(vm.entry, { id: 'g1' })
	answer = { status: 200, json: { id: 'bare' } }
	await vm.loadEntry('g1')
	assert.deepEqual(vm.entry, { id: 'bare' })
	assert.deepEqual(calls[0], { url: `${OBJECTS}/grade-entry/g1`, init: { headers: READ } })
	answer = { status: 404, json: null }
	await assert.rejects(() => vm.loadEntry('x'), { message: 'GradeEntry fetch failed: 404' })
})

test('the plan is read when there is one, and a refusal leaves it alone', async () => {
	const vm = await page()
	await vm.loadPlan('')
	assert.equal(calls.length, 0)
	answer = { status: 200, json: { object: { id: 'c1' } } }
	await vm.loadPlan('c1')
	assert.deepEqual(vm.plan, { id: 'c1' })
	assert.deepEqual(calls[0], { url: `${OBJECTS}/curriculum-plan/c1`, init: { headers: READ } })
	answer = { status: 403, json: null }
	await vm.loadPlan('c1')
	assert.deepEqual(vm.plan, { id: 'c1' })
})

test('the period entries and the final grade are read for the entry', async () => {
	const vm = await page()
	await vm.loadPeriodEntries()
	await vm.loadFinalGrade()
	vm.entry = { learnerId: 'p1' }
	await vm.loadPeriodEntries()
	await vm.loadFinalGrade()
	assert.equal(calls.length, 0)

	vm.entry = ENTRY
	for (const [json, rows, first] of [
		[{ results: [{ id: 'a' }, { id: 'b' }] }, [{ id: 'a' }, { id: 'b' }], { id: 'a' }],
		[{ objects: [{ id: 'o' }] }, [{ id: 'o' }], { id: 'o' }],
		[[{ id: 'bare' }], [], null],
		[{}, [], null],
	]) {
		answer = { status: 200, json }
		await vm.loadPeriodEntries()
		await vm.loadFinalGrade()
		assert.deepEqual([vm.periodEntries, vm.finalGrade], [rows, first], JSON.stringify(json))
	}
	assert.deepEqual(calls.slice(0, 2), [
		{ url: `${OBJECTS}/grade-entry?learnerId=p%201&curriculumPlanId=c1&period=P1&lifecycle=published&_limit=100`, init: { headers: READ } },
		{ url: `${OBJECTS}/final-grade?learnerId=p%201&curriculumPlanId=c1&_limit=1`, init: { headers: READ } },
	])

	answer = { status: 500, json: null }
	vm.periodEntries = ['kept']
	vm.finalGrade = 'kept'
	await vm.loadPeriodEntries()
	await vm.loadFinalGrade()
	assert.deepEqual([vm.periodEntries, vm.finalGrade], [['kept'], 'kept'])
})
