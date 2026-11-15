// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The timetable API helpers: which query each one sends and how it reads the
// answer. Run via `node --test tests/unit-js/` (package.json's `test:js-unit`).
//
// fetchMyTimetable, fetchCohortTimetable and fetchTimetableOf each built the
// window query and normalised the answer inline; these tests pin the query
// (only the window ends given) and the fallbacks (empty lists, the requested
// window when the server echoes none, `learniq` as the source).

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
 * Answer every GET with one body and record what was asked.
 *
 * @param {*} data The response body.
 * @return {Array<{url: string, config: object}>} The calls.
 */
function answer(data) {
	const calls = []
	axios.get = async (url, config) => {
		calls.push({ url, config })
		return { data }
	}
	return calls
}

test('my timetable sends only the window ends given', async () => {
	let calls = answer({})
	await api.fetchMyTimetable()
	assert.deepEqual(calls, [{ url: '/index.php/apps/learniq/api/timetable/mine', config: { params: {} } }])

	calls = answer({})
	await api.fetchMyTimetable('2026-11-09', '')
	assert.deepEqual(calls[0].config, { params: { from: '2026-11-09' } })

	calls = answer({})
	await api.fetchMyTimetable(undefined, '2026-11-16')
	assert.deepEqual(calls[0].config, { params: { to: '2026-11-16' } })

	calls = answer({})
	await api.fetchMyTimetable('2026-11-09', '2026-11-16')
	assert.deepEqual(calls[0].config, { params: { from: '2026-11-09', to: '2026-11-16' } })
})

test('my timetable reads the answer with its fallbacks', async () => {
	answer({ sessions: [{ id: 's-1' }], from: 'F', to: 'T', changes: [{ id: 'c-1' }], source: 'planninq' })
	assert.deepEqual(await api.fetchMyTimetable('a', 'b'), {
		sessions: [{ id: 's-1' }],
		from: 'F',
		to: 'T',
		changes: [{ id: 'c-1' }],
		source: 'planninq',
	})

	answer({ sessions: 'x', changes: {} })
	assert.deepEqual(await api.fetchMyTimetable('a', 'b'), { sessions: [], from: 'a', to: 'b', changes: [], source: 'learniq' })

	answer(null)
	assert.deepEqual(await api.fetchMyTimetable(), { sessions: [], from: '', to: '', changes: [], source: 'learniq' })
})

test('a cohort timetable names the cohort in the path and reads the same way', async () => {
	const calls = answer({ sessions: [{ id: 's-1' }], source: 'planninq' })
	assert.deepEqual(await api.fetchCohortTimetable('h-1', 'a'), { sessions: [{ id: 's-1' }], from: 'a', to: '', source: 'planninq' })
	assert.deepEqual(calls, [{ url: '/index.php/apps/learniq/api/timetable/cohort/h-1', config: { params: { from: 'a' } } }])

	answer(undefined)
	assert.deepEqual(await api.fetchCohortTimetable('h-1'), { sessions: [], from: '', to: '', source: 'learniq' })
})

test('another timetable sends kind and id first, then the window', async () => {
	const calls = answer({ from: 'F', to: 'T' })
	assert.deepEqual(await api.fetchTimetableOf('room', 'r-1', 'a', 'b'), { sessions: [], from: 'F', to: 'T', source: 'learniq' })
	assert.deepEqual(calls, [{ url: '/index.php/apps/learniq/api/timetable/of', config: { params: { kind: 'room', id: 'r-1', from: 'a', to: 'b' } } }])
	assert.deepEqual(Object.keys(calls[0].config.params), ['kind', 'id', 'from', 'to'])

	const bare = answer({})
	await api.fetchTimetableOf('teacher', 'u-1')
	assert.deepEqual(bare[0].config, { params: { kind: 'teacher', id: 'u-1' } })
})
