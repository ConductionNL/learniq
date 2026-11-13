// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The timetable API helpers for standby duty, the personal calendar feed and
// the learniq-session check: which call each one makes and how it reads the
// answer. Run via `node --test tests/unit-js/` (package.json's `test:js-unit`).

import assert from 'node:assert/strict'
import { test } from 'node:test'

/**
 * A storage double for @nextcloud/auth, which builds one when it loads.
 *
 * @return {object} The storage.
 */
function memoryStorage() {
	const items = new Map()
	return {
		getItem: (key) => items.get(key) ?? null,
		setItem: (key, value) => items.set(key, String(value)),
		removeItem: (key) => items.delete(key),
		key: (index) => [...items.keys()][index] ?? null,
		clear: () => items.clear(),
		get length() {
			return items.size
		},
	}
}

const page = { protocol: 'http:', host: 'cloud.test', pathname: '/index.php/apps/learniq/' }
globalThis.window = {
	localStorage: memoryStorage(),
	sessionStorage: memoryStorage(),
	location: page,
	_oc_webroot: '',
	addEventListener() {},
}
globalThis.location = page
globalThis.document = {
	head: { dataset: {} },
	getElementsByTagName: () => [],
	querySelector: () => null,
	addEventListener() {},
}


const { default: axios } = await import('@nextcloud/axios')
const api = await import('../../src/api/timetable.js')

/**
 * Replace one axios verb, recording each call and answering with `reply`.
 *
 * @param {string} verb The axios method name.
 * @param {Function} reply Returns (or throws) the response for a call.
 * @return {Array<Array<*>>} The calls' arguments.
 */
function stub(verb, reply) {
	const calls = []
	axios[verb] = async (...args) => {
		calls.push(args)
		return reply(...args)
	}
	return calls
}

test('standby duty asks for the window and reads the standby list', async () => {
	const calls = stub('get', () => ({ data: { standby: [{ id: 's-1' }] } }))

	assert.deepEqual(await api.fetchMyStandby('2026-11-09', '2026-11-16'), [{ id: 's-1' }])
	assert.deepEqual(calls, [['/index.php/apps/learniq/api/standby/mine', { params: { from: '2026-11-09', to: '2026-11-16' } }]])
})

test('standby duty is empty when the answer has no list or the call fails', async () => {
	stub('get', () => ({ data: { standby: 'none' } }))
	assert.deepEqual(await api.fetchMyStandby('a', 'b'), [])

	stub('get', () => ({ data: null }))
	assert.deepEqual(await api.fetchMyStandby('a', 'b'), [])

	stub('get', () => {
		throw new Error('offline')
	})
	assert.deepEqual(await api.fetchMyStandby('a', 'b'), [])
})

test('the feed exists only when the server says exactly true', async () => {
	const calls = stub('get', () => ({ data: { exists: true } }))
	assert.equal(await api.fetchCalendarFeedStatus(), true)
	assert.deepEqual(calls, [['/index.php/apps/learniq/api/timetable/feed']])

	stub('get', () => ({ data: { exists: 'yes' } }))
	assert.equal(await api.fetchCalendarFeedStatus(), false)

	stub('get', () => ({ data: {} }))
	assert.equal(await api.fetchCalendarFeedStatus(), false)
})

test('creating the feed posts and returns both addresses as strings', async () => {
	const calls = stub('post', () => ({ data: { url: 'https://cloud.test/feed.ics', webcalUrl: 'webcal://cloud.test/feed.ics' } }))

	assert.deepEqual(await api.createCalendarFeed(), { url: 'https://cloud.test/feed.ics', webcalUrl: 'webcal://cloud.test/feed.ics' })
	assert.deepEqual(calls, [['/index.php/apps/learniq/api/timetable/feed']])

	stub('post', () => ({ data: null }))
	assert.deepEqual(await api.createCalendarFeed(), { url: '', webcalUrl: '' })
})

test('removing the feed deletes it', async () => {
	const calls = stub('delete', () => ({ data: null }))

	assert.equal(await api.removeCalendarFeed(), undefined)
	assert.deepEqual(calls, [['/index.php/apps/learniq/api/timetable/feed']])
})

test('a session is learniq\'s own when it has an id and no other source', () => {
	assert.equal(api.isLearniqSession({ id: 'x' }), true)
	assert.equal(api.isLearniqSession({ id: 'x', source: 'learniq' }), true)
	assert.equal(api.isLearniqSession({ id: 'x', source: 'planninq' }), false)
	assert.equal(api.isLearniqSession({ source: 'learniq' }), false)
	assert.equal(api.isLearniqSession(null), false)
})
