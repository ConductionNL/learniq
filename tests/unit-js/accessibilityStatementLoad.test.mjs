// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The accessibility statement page reads the published statement, its
// limitations and the conformance table. Pinned through the component's own
// methods with a stand-in object store.

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { loadSfcScript } from './sfcScript.mjs'

const stubs = {
	'@conduction/nextcloud-vue': { useObjectStore: () => stubs.store },
	'@nextcloud/axios': { default: { get: (url) => stubs.get(url) } },
	'@nextcloud/initial-state': { loadState: () => [] },
	'@nextcloud/router': { generateUrl: (path, params) => `${path}|${JSON.stringify(params)}` },
	'@nextcloud/vue': {},
}

/**
 * A store that answers fetchCollection from `answers` in order and logs every call.
 *
 * @param {Array<Function>} answers one function per fetchCollection call
 * @return {object}
 */
function recordingStore(answers) {
	const calls = []
	return {
		calls,
		registerObjectType: (...args) => calls.push(['register', ...args]),
		fetchCollection: (...args) => {
			calls.push(['fetch', ...args])
			return answers.shift()()
		},
	}
}

/**
 * A fresh `this` for the component's methods.
 *
 * @param {object} methods the component's methods
 * @return {object}
 */
function context(methods) {
	const ctx = { loading: false, statement: undefined, limitations: undefined, conformanceCalls: 0 }
	for (const [name, fn] of Object.entries(methods)) {
		ctx[name] = fn.bind(ctx)
	}
	ctx.loadConformance = async () => { ctx.conformanceCalls++ }
	return ctx
}

const component = await loadSfcScript('src/views/LearniqAccessibilityStatement.vue', stubs)

/**
 * Bind every component method onto `ctx`.
 *
 * @param {object} ctx the stand-in `this`
 * @return {void}
 */
function bindMethods(ctx) {
	for (const [name, fn] of Object.entries(component.methods)) {
		ctx[name] = fn.bind(ctx)
	}
}

test('the published statement and its limitations are read in order', async () => {
	stubs.store = recordingStore([
		async () => [{ id: 's1' }, { id: 's2' }],
		async () => [{ id: 'l1' }],
	])
	const ctx = context(component.methods)
	const loading = ctx.loadStatement()
	assert.equal(ctx.loading, true)
	await loading
	assert.deepEqual(stubs.store.calls, [
		['register', 'learniq-accessibility-statement', 'accessibility-statement', 'learniq'],
		['register', 'learniq-accessibility-limitation', 'accessibility-limitation', 'learniq'],
		['fetch', 'learniq-accessibility-statement', { lifecycle: 'published', _limit: 1 }],
		['fetch', 'learniq-accessibility-limitation', { accessibilityStatementId: 's1' }],
	])
	assert.deepEqual(ctx.statement, { id: 's1' })
	assert.deepEqual(ctx.limitations, [{ id: 'l1' }])
	assert.equal(ctx.conformanceCalls, 1)
	assert.equal(ctx.loading, false)
})

test('a statement known only by its @self id is linked by that id', async () => {
	stubs.store = recordingStore([
		async () => [{ '@self': { id: 'self-1' } }],
		async () => ({ not: 'a list' }),
	])
	const ctx = context(component.methods)
	await ctx.loadStatement()
	assert.deepEqual(stubs.store.calls[3], ['fetch', 'learniq-accessibility-limitation', { accessibilityStatementId: 'self-1' }])
	assert.deepEqual(ctx.limitations, [])
	assert.equal(ctx.conformanceCalls, 1)
})

test('no published statement leaves the limitations empty and skips the conformance table', async () => {
	for (const answer of [async () => [], async () => { throw new Error('down') }, async () => null]) {
		stubs.store = recordingStore([answer])
		const ctx = context(component.methods)
		await ctx.loadStatement()
		assert.equal(ctx.statement, null)
		assert.deepEqual(ctx.limitations, [])
		assert.equal(ctx.conformanceCalls, 0)
		assert.equal(ctx.loading, false)
		assert.equal(stubs.store.calls.length, 3)
	}
})

test('a store without collection support yields no statement', async () => {
	stubs.store = {}
	const ctx = context(component.methods)
	await ctx.loadStatement()
	assert.equal(ctx.statement, null)
	assert.deepEqual(ctx.limitations, [])
	assert.equal(ctx.loading, false)
})

test('the conformance table is read for everyone and the records only for an editor', async () => {
	for (const canEdit of [false, true]) {
		stubs.get = async (url) => ({ data: { criteria: [{ url }] } })
		stubs.store = recordingStore([async () => [{ id: 'r1' }]])
		const ctx = { canEdit, statementId: 's1', records: [], criteria: [] }
		bindMethods(ctx)
		await ctx.loadConformance()
		assert.equal(ctx.criteria.length, 1)
		assert.match(ctx.criteria[0].url, /format=\{format\}/)
		if (canEdit) {
			assert.deepEqual(stubs.store.calls, [
				['register', 'learniq-accessibility-criterion-result', 'accessibility-criterion-result', 'learniq'],
				['fetch', 'learniq-accessibility-criterion-result', { accessibilityStatementId: 's1' }],
			])
			assert.deepEqual(ctx.records, [{ id: 'r1' }])
		} else {
			assert.deepEqual(stubs.store.calls, [])
			assert.deepEqual(ctx.records, [])
		}
	}
})

test('a failed evidence read or a broken record list leaves empty tables', async () => {
	stubs.get = async () => { throw new Error('down') }
	stubs.store = recordingStore([async () => { throw new Error('down') }])
	const ctx = { canEdit: true, statementId: 's1', records: ['old'], criteria: ['old'] }
	bindMethods(ctx)
	await ctx.loadConformance()
	assert.deepEqual(ctx.criteria, [])
	assert.deepEqual(ctx.records, [])
	stubs.get = async () => ({ data: { criteria: 'none' } })
	stubs.store = { fetchCollection: undefined }
	await ctx.loadConformance()
	assert.deepEqual(ctx.criteria, [])
	assert.deepEqual(ctx.records, [])
})
