// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The learning record screen's export and share writes, pinned through the
// component's own methods with a stubbed fetch: what is sent, in which order,
// when nothing is sent, and what the screen shows after a refusal.
//
// @spec openspec/specs/portable-learning-record/spec.md#scenario-a-learner-creates-a-share-with-a-mandatory-expiry

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { loadSfcScript } from './sfcScript.mjs'

const stubs = {
	'@conduction/nextcloud-vue': {},
	'@nextcloud/router': { generateUrl: (path) => `/index.php${path}` },
}
const view = await loadSfcScript('src/views/MyLearningRecordView.vue', stubs)

const EXPORTS = '/index.php/apps/openregister/api/objects/learniq/learning-record-export'
const SHARES = '/index.php/apps/openregister/api/objects/learniq/learning-record-share'

/**
 * A fresh `this` for the component's methods.
 *
 * @param {object} state Data to override.
 * @return {object}
 */
function screen(state = {}) {
	const self = { ...view.data(), t: (app, text) => text, ...state }
	for (const [name, method] of Object.entries(view.methods)) {
		self[name] = method.bind(self)
	}
	return self
}

/**
 * A response stand-in.
 *
 * @param {number} status HTTP status.
 * @param {*} body The JSON body.
 * @return {object}
 */
function answer(status, body) {
	return { ok: status < 400, status, json: async () => body }
}

/**
 * Replace fetch: each call takes the next answer and is recorded.
 *
 * @param {Array<object|Error>} answers The answers in call order.
 * @return {Array<object>} The calls as { url, init, body }.
 */
function stubFetch(answers) {
	const calls = []
	globalThis.fetch = async (url, init = {}) => {
		calls.push({ url, init, body: init.body ? JSON.parse(init.body) : undefined })
		const next = answers.shift()
		if (next instanceof Error) {
			throw next
		}
		return next
	}
	return calls
}

/**
 * Run with console.error recorded instead of printed.
 *
 * @param {Function} run The callback.
 * @return {Promise<Array<Array>>} The logged arguments.
 */
async function capturingErrors(run) {
	const original = console.error
	const logged = []
	console.error = (...args) => logged.push(args)
	try {
		await run()
	} finally {
		console.error = original
	}
	return logged
}

const READ = { 'OCS-APIREQUEST': 'true', Accept: 'application/json' }
const WRITE = { ...READ, 'Content-Type': 'application/json' }

test('no record means no export request', async () => {
	const vm = screen()
	const calls = stubFetch([])
	await vm.generateExport()
	assert.equal(calls.length, 0)
	assert.equal(vm.generating, false)
})

test('an export is created for the chosen period, read back, and clears the shares', async () => {
	const vm = screen({ record: {}, periodFrom: '2026-01-01', shares: [{ id: 'old' }], exportError: 'x' })
	const calls = stubFetch([answer(201, { uuid: 'e1' }), answer(200, { object: { id: 'e1', lifecycle: 'generated' } })])
	await vm.generateExport()
	assert.deepEqual(calls, [
		{ url: EXPORTS, init: { method: 'POST', headers: WRITE, body: calls[0].init.body }, body: { periodFrom: '2026-01-01', periodTo: null } },
		{ url: `${EXPORTS}/e1`, init: { headers: READ }, body: undefined },
	])
	assert.deepEqual(vm.latestExport, { id: 'e1', lifecycle: 'generated' })
	assert.deepEqual(vm.shares, [])
	assert.equal(vm.exportError, null)
	assert.equal(vm.generating, false)
})

test('an export the server refused, or did not generate, is reported and kept out', async () => {
	for (const answers of [
		[answer(403, {})],
		[answer(201, { id: 'e2' }), answer(200, { id: 'e2', lifecycle: 'requested' })],
		[answer(201, { id: 'e3' }), answer(404, { lifecycle: 'generated' })],
		[new Error('offline')],
	]) {
		const vm = screen({ record: {}, shares: [{ id: 'keep' }] })
		stubFetch(answers)
		const logged = await capturingErrors(() => vm.generateExport())
		assert.equal(vm.latestExport, null)
		assert.deepEqual(vm.shares, [{ id: 'keep' }])
		assert.equal(vm.exportError, 'Failed to generate the export. Please try again.')
		assert.equal(logged[0][0], '[MyLearningRecordView] generateExport error')
		assert.equal(vm.generating, false)
	}
})

test('a share needs a recipient, an expiry and an export before anything is sent', async () => {
	const complete = { recipientName: 'Werkgever', recipientEmail: '', expiresAt: '2026-12-31' }
	for (const state of [
		{ newShare: { ...complete, recipientName: '' }, latestExport: { id: 'e1' } },
		{ newShare: { ...complete, expiresAt: '' }, latestExport: { id: 'e1' } },
		{ newShare: complete, latestExport: null },
	]) {
		const vm = screen(state)
		const calls = stubFetch([])
		await vm.createShare()
		assert.equal(calls.length, 0)
		assert.equal(vm.shareTouched, true)
		assert.equal(vm.creatingShare, false)
	}
})

test('a share is created for the export, read back as active, and the form resets', async () => {
	const vm = screen({
		latestExport: { uuid: 'e1' },
		newShare: { recipientName: 'Werkgever', recipientEmail: 'hr@example.org', expiresAt: '2026-12-31T00:00:00Z' },
		shareError: 'x',
	})
	const calls = stubFetch([
		answer(201, { id: 's1' }),
		answer(200, { results: [{ uuid: 's1', lifecycle: 'active' }] }),
	])
	await vm.createShare()
	assert.deepEqual(calls.map((c) => c.url), [SHARES, `${SHARES}?learningRecordExportId=e1&_limit=100`])
	assert.deepEqual(calls[0].init.headers, WRITE)
	assert.equal(calls[0].init.method, 'POST')
	assert.deepEqual(calls[0].body, {
		learningRecordExportId: 'e1',
		recipientName: 'Werkgever',
		recipientEmail: 'hr@example.org',
		expiresAt: '2026-12-31T00:00:00.000Z',
	})
	assert.deepEqual(calls[1].init, { headers: READ })
	assert.deepEqual(vm.shares, [{ uuid: 's1', lifecycle: 'active' }])
	assert.deepEqual(vm.newShare, { recipientName: '', recipientEmail: '', expiresAt: '' })
	assert.equal(vm.shareTouched, false)
	assert.equal(vm.shareError, null)
	assert.equal(vm.creatingShare, false)
})

test('a refused or ungranted share is reported and the form is kept', async () => {
	const form = { recipientName: 'W', recipientEmail: '', expiresAt: '2026-12-31' }
	for (const answers of [
		[answer(403, {})],
		[answer(201, { id: 's2' }), answer(200, [{ id: 's2', lifecycle: 'requested' }])],
		[answer(201, { id: 's3' }), answer(200, { objects: [] })],
		[answer(201, { id: 's4' }), answer(500, {})],
	]) {
		const vm = screen({ latestExport: { id: 'e1' }, newShare: { ...form } })
		const calls = stubFetch(answers)
		const logged = await capturingErrors(() => vm.createShare())
		assert.equal(calls[0].body.recipientEmail, null)
		assert.deepEqual(vm.newShare, form)
		assert.equal(vm.shareTouched, true)
		assert.equal(vm.shareError, 'Failed to create the share. Please try again.')
		assert.equal(logged[0][0], '[MyLearningRecordView] createShare error')
		assert.equal(vm.creatingShare, false)
	}
})

test('shares load only for an export and read results, objects or a bare list', async () => {
	const none = screen()
	const calls = stubFetch([])
	await none.loadShares()
	assert.equal(calls.length, 0)
	for (const [body, rows] of [
		[{ results: [{ id: 'a' }], objects: [{ id: 'b' }] }, [{ id: 'a' }]],
		[{ objects: [{ id: 'b' }] }, [{ id: 'b' }]],
		[[{ id: 'c' }], [{ id: 'c' }]],
		[{ total: 0 }, []],
	]) {
		const vm = screen({ latestExport: { id: 'e9' }, shares: ['stale'] })
		stubFetch([answer(200, body)])
		await vm.loadShares()
		assert.deepEqual(vm.shares, rows)
	}
	const refused = screen({ latestExport: { id: 'e9' }, shares: ['stale'] })
	stubFetch([answer(403, {})])
	await refused.loadShares()
	assert.deepEqual(refused.shares, [])
})
