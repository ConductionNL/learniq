// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The app's own object store: how fetchObjects reads a registered type from
// OpenRegister, and what it keeps when the read fails. Browser globals are
// stubbed so the pinia store runs under `npm run test:js-unit`.

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createPinia, setActivePinia } from 'pinia'

/**
 * A Web Storage stand-in.
 *
 * @return {object} The storage.
 */
function memoryStorage() {
	const items = new Map()
	return {
		getItem: (key) => (items.has(key) ? items.get(key) : null),
		setItem: (key, value) => items.set(key, String(value)),
		removeItem: (key) => items.delete(key),
		clear: () => items.clear(),
		key: (index) => [...items.keys()][index] ?? null,
		get length() {
			return items.size
		},
	}
}

globalThis.window = {
	location: { origin: 'https://cloud.example' },
	localStorage: memoryStorage(),
	sessionStorage: memoryStorage(),
	addEventListener: () => {},
}
globalThis.document = {
	head: { dataset: { requesttoken: 'token-1' } },
	addEventListener: () => {},
}

const { useObjectStore } = await import('../../src/store/modules/object.js')

/**
 * A configured store with one registered type.
 *
 * @return {object} The store.
 */
function freshStore() {
	setActivePinia(createPinia())
	const store = useObjectStore()
	store.configure({ baseUrl: '/apps/openregister/api/objects', schemaBaseUrl: '/schemas' })
	store.registerObjectType('course', 'course', 'learniq')
	return store
}

/**
 * Replace fetch for one test; returns the recorded calls.
 *
 * @param {Function} answer What each call resolves or rejects with.
 * @return {Array<Array>} The calls.
 */
function stubFetch(answer) {
	const calls = []
	globalThis.fetch = async (...args) => {
		calls.push(args)
		return answer()
	}
	return calls
}

/**
 * Silence and record one console method for the duration of a callback.
 *
 * @param {string} method warn or error.
 * @param {Function} run The callback.
 * @return {Promise<Array<Array>>} The logged arguments.
 */
async function capturing(method, run) {
	const original = console[method]
	const logged = []
	console[method] = (...args) => logged.push(args)
	try {
		await run()
	} finally {
		console[method] = original
	}
	return logged
}

test('an unregistered type warns and reads nothing', async () => {
	const store = freshStore()
	const calls = stubFetch(() => ({ ok: true, json: async () => [] }))
	let result
	const warned = await capturing('warn', async () => {
		result = await store.fetchObjects('nothing')
	})
	assert.deepEqual(result, [])
	assert.deepEqual(warned, [['Object type "nothing" is not registered']])
	assert.equal(calls.length, 0)
	assert.equal(store.loading.nothing, undefined)
})

test('a read sends register, schema and the params with the request token, and keeps the results', async () => {
	const store = freshStore()
	const calls = stubFetch(() => ({ ok: true, json: async () => ({ results: [{ id: 'c1' }] }) }))
	const result = await store.fetchObjects('course', { _limit: 5, _search: 'wis' })
	assert.equal(
		calls[0][0],
		'https://cloud.example/apps/openregister/api/objects?register=learniq&schema=course&_limit=5&_search=wis',
	)
	assert.deepEqual(calls[0][1], { headers: { requesttoken: 'token-1' } })
	assert.deepEqual(result, [{ id: 'c1' }])
	assert.deepEqual(store.objects.course, [{ id: 'c1' }])
	assert.equal(store.loading.course, false)
})

test('a bare list answer is kept as it is', async () => {
	const store = freshStore()
	stubFetch(() => ({ ok: true, json: async () => [{ id: 'c2' }] }))
	assert.deepEqual(await store.fetchObjects('course'), [{ id: 'c2' }])
})

test('a refused read keeps the old objects and returns none', async () => {
	const store = freshStore()
	store.objects.course = [{ id: 'old' }]
	stubFetch(() => ({ ok: false, json: async () => ({ results: [{ id: 'new' }] }) }))
	assert.deepEqual(await store.fetchObjects('course'), [])
	assert.deepEqual(store.objects.course, [{ id: 'old' }])
	assert.equal(store.loading.course, false)
})

test('a failed read logs the error, stops loading and returns none', async () => {
	const store = freshStore()
	const failure = new Error('offline')
	stubFetch(() => {
		throw failure
	})
	let result
	const logged = await capturing('error', async () => {
		result = await store.fetchObjects('course')
	})
	assert.deepEqual(result, [])
	assert.deepEqual(logged, [['Failed to fetch course objects:', failure]])
	assert.equal(store.loading.course, false)
})
