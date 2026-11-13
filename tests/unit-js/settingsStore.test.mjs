// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The settings store: what fetchSettings and saveSettings send to the
// Settings API, what they keep from the answer, and that a refused or
// failed call leaves the state alone, clears the loading flag and answers
// null. Browser globals are stubbed so the pinia store runs under
// `npm run test:js-unit`; only fetch is replaced.

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createPinia, setActivePinia } from 'pinia'

globalThis.window = {
	location: { origin: 'https://cloud.example', pathname: '/index.php/apps/learniq/' },
	_oc_webroot: '',
	addEventListener: () => {},
}
globalThis.location = globalThis.window.location
globalThis.document = {
	head: { dataset: { requesttoken: 'token-1' } },
	addEventListener: () => {},
}

const { useSettingsStore } = await import('../../src/store/modules/settings.js')

/**
 * A fresh store.
 *
 * @return {object} The store.
 */
function freshStore() {
	setActivePinia(createPinia())
	return useSettingsStore()
}

/**
 * Replace fetch; each call records its arguments and the store's loading flag.
 *
 * @param {object} store The store, to observe loading during the call.
 * @param {Function} answer What each call resolves or rejects with.
 * @return {Array<object>} The calls.
 */
function stubFetch(store, answer) {
	const calls = []
	globalThis.fetch = async (url, options) => {
		calls.push({ url, options, loading: store.loading })
		return answer()
	}
	return calls
}

/**
 * A JSON response.
 *
 * @param {boolean} ok Whether the status is 2xx.
 * @param {object} body The body.
 * @return {object} The response.
 */
function response(ok, body) {
	return { ok, json: async () => body }
}

/**
 * Silence and record console.error for the duration of a callback.
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

test('the store starts empty and not admin', () => {
	const store = freshStore()

	assert.deepEqual(store.getSettings, {})
	assert.equal(store.getIsAdmin, false)
	assert.equal(store.hasOpenRegisters, false)
	assert.equal(store.loading, false)
})

test('fetchSettings reads the settings API with the request token and keeps the answer', async () => {
	const store = freshStore()
	const settings = { register: 'learniq', openregisters: true, isAdmin: true }
	const calls = stubFetch(store, () => response(true, settings))

	const answer = await store.fetchSettings()

	assert.deepEqual(answer, settings)
	assert.equal(calls.length, 1)
	assert.match(calls[0].url, /\/apps\/learniq\/api\/settings$/)
	assert.deepEqual(calls[0].options, { headers: { requesttoken: 'token-1' } })
	assert.equal(calls[0].loading, true, 'loading is set while the call runs')
	assert.deepEqual(store.getSettings, settings)
	assert.equal(store.hasOpenRegisters, true)
	assert.equal(store.getIsAdmin, true)
	assert.equal(store.loading, false)
})

test('fetchSettings reads absent flags as false', async () => {
	const store = freshStore()
	stubFetch(store, () => response(true, { register: 'learniq' }))

	await store.fetchSettings()

	assert.equal(store.hasOpenRegisters, false)
	assert.equal(store.getIsAdmin, false)
})

test('a refused fetch keeps the state and answers null', async () => {
	const store = freshStore()
	store.settings = { register: 'kept' }
	stubFetch(store, () => response(false, { error: 'forbidden' }))

	assert.equal(await store.fetchSettings(), null)
	assert.deepEqual(store.getSettings, { register: 'kept' })
	assert.equal(store.loading, false)
})

test('a failed fetch is logged, keeps the state and answers null', async () => {
	const store = freshStore()
	stubFetch(store, () => {
		throw new Error('offline')
	})

	let answer
	const logged = await capturingErrors(async () => {
		answer = await store.fetchSettings()
	})

	assert.equal(answer, null)
	assert.equal(logged.length, 1)
	assert.equal(logged[0][0], 'Failed to fetch settings:')
	assert.equal(logged[0][1].message, 'offline')
	assert.equal(store.loading, false)
})

test('saveSettings posts the payload as JSON and keeps the stored answer', async () => {
	const store = freshStore()
	const calls = stubFetch(store, () => response(true, { register: 'learniq', saved: true }))

	const answer = await store.saveSettings({ register: 'learniq' })

	assert.deepEqual(answer, { register: 'learniq', saved: true })
	assert.match(calls[0].url, /\/apps\/learniq\/api\/settings$/)
	assert.deepEqual(calls[0].options, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', requesttoken: 'token-1' },
		body: '{"register":"learniq"}',
	})
	assert.equal(calls[0].loading, true)
	assert.deepEqual(store.getSettings, { register: 'learniq', saved: true })
	assert.equal(store.loading, false)
})

test('a refused or failed save keeps the state and answers null', async () => {
	const store = freshStore()
	store.settings = { register: 'kept' }
	stubFetch(store, () => response(false, {}))

	assert.equal(await store.saveSettings({ register: 'new' }), null)
	assert.deepEqual(store.getSettings, { register: 'kept' })

	stubFetch(store, () => {
		throw new Error('offline')
	})
	let answer
	const logged = await capturingErrors(async () => {
		answer = await store.saveSettings({ register: 'new' })
	})

	assert.equal(answer, null)
	assert.equal(logged[0][0], 'Failed to save settings:')
	assert.deepEqual(store.getSettings, { register: 'kept' })
	assert.equal(store.loading, false)
})
