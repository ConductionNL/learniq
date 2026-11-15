// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The rollover wizard's proposal and plan save, pinned through the
// component's own methods with a stubbed axios: what is read and written,
// and what the screen keeps and shows when the server refuses.
//
// @spec openspec/specs/school-year-rollover/spec.md#requirement-rolloverplan-must-be-an-openregister-object-with-a-preview-gated-lifecycle

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const http = { calls: [], answers: [] }
/**
 * Record an axios call and give the next answer.
 *
 * @param {string} method The axios method.
 * @param {Array} args Its arguments.
 * @return {Promise<object>}
 */
async function reply(method, args) {
	http.calls.push([method, ...args])
	const next = http.answers.shift()
	if (next instanceof Error) {
		throw next
	}
	return next ?? { data: {} }
}
const STUBS = {
	'@nextcloud/axios': {
		default: {
			get: (...args) => reply('get', args),
			post: (...args) => reply('post', args),
			patch: (...args) => reply('patch', args),
		},
	},
	'@nextcloud/router': {
		generateUrl: (path, params = {}) => path.replace(/\{(\w+)\}/g, (_, key) => params[key]),
	},
}
const component = await loadSfc('src/views/RolloverWizard.vue', STUBS)

/**
 * A fresh wizard.
 *
 * @param {object} state Data to set.
 * @return {object}
 */
function wizard(state = {}) {
	http.calls = []
	http.answers = []
	const vm = instanceOf(component)
	Object.assign(vm, { fromAcademicYear: '2025-2026', toAcademicYear: '2026-2027' }, state)
	return vm
}

/**
 * An axios-style refusal.
 *
 * @param {object} data The response body.
 * @return {Error}
 */
function refused(data) {
	return Object.assign(new Error('refused'), { response: { data } })
}

test('a proposal reads the from-year mapping and clears an earlier result and report', async () => {
	const vm = wizard({ result: { status: 'done' }, report: { blocked: false } })
	http.answers.push({ data: { mappings: [{ cohortId: 'c1' }], cohortNames: { c1: '1A' } } })
	await vm.propose()
	assert.deepEqual(http.calls, [['get', '/apps/learniq/api/rollover/propose?fromAcademicYear=2025-2026']])
	assert.deepEqual(vm.mappings, [{ cohortId: 'c1' }])
	assert.deepEqual(vm.cohortNames, { c1: '1A' })
	assert.equal(vm.report, null)
	assert.equal(vm.result, null)
	assert.equal(vm.loading, false)
})

test('a proposal without mappings or names reads as empty; a refused one says why', async () => {
	const empty = wizard({ cohortNames: { old: 'x' } })
	http.answers.push({ data: null })
	await empty.propose()
	assert.deepEqual([empty.mappings, empty.cohortNames], [[], {}])

	const vm = wizard({ mappings: [{ cohortId: 'keep' }], cohortNames: { keep: 'K' }, report: { blocked: true } })
	http.answers.push(refused({ message: ' No cohorts in 2025-2026. ' }))
	await vm.propose()
	assert.deepEqual(vm.mappings, [])
	assert.deepEqual(vm.cohortNames, { keep: 'K' })
	assert.deepEqual(vm.report, { blocked: true })
	assert.deepEqual(vm.result, { status: 'failed', message: 'No cohorts in 2025-2026.' })
	assert.equal(vm.loading, false)

	const broken = wizard()
	http.answers.push(new Error('offline'))
	await broken.propose()
	assert.deepEqual(broken.result, { status: 'failed', message: 'The mapping could not be proposed.' })
})

test('a new plan is created and its id kept; an existing plan is patched', async () => {
	const fresh = wizard({ mappings: [{ cohortId: 'c1', action: 'promote' }] })
	http.answers.push({ data: { uuid: 'p1' } })
	assert.equal(await fresh.savePlan(), 'p1')
	assert.deepEqual(http.calls, [
		[
			'post',
			'/apps/openregister/api/objects/learniq/rollover-plan',
			{ fromAcademicYear: '2025-2026', toAcademicYear: '2026-2027', mappings: [{ cohortId: 'c1', action: 'promote' }] },
		],
	])
	assert.deepEqual(Object.keys(http.calls[0][2]), ['fromAcademicYear', 'toAcademicYear', 'mappings'])

	const stored = wizard({ planId: 'p1' })
	http.answers.push({ data: { id: 'p1-new' } })
	assert.equal(await stored.savePlan(), 'p1-new')
	assert.deepEqual(http.calls[0].slice(0, 2), ['patch', '/apps/openregister/api/objects/learniq/rollover-plan/p1'])

	const unnamed = wizard({ planId: 'p2' })
	http.answers.push({ data: {} })
	assert.equal(await unnamed.savePlan(), 'p2')
	const nameless = wizard()
	http.answers.push({ data: null })
	assert.equal(await nameless.savePlan(), '')
	assert.equal(nameless.result, null)
})

test('a refused save names the reason and gives no plan id', async () => {
	for (const [answer, message] of [
		[refused({ error: 'Mappings must name an action.' }), 'Mappings must name an action.'],
		[refused({ message: '   ' }), 'The plan could not be saved.'],
		[new Error('offline'), 'The plan could not be saved.'],
	]) {
		const vm = wizard({ planId: 'p3' })
		http.answers.push(answer)
		assert.equal(await vm.savePlan(), '')
		assert.deepEqual(vm.result, { status: 'failed', message })
	}
})
