// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The regulation detail page: how a slug resolves to a Regulation (the
// OpenRegister matcher first, then the schema's own `slug`), what the page
// shows for it or without it, and the live subscription of the resolved
// object (one per object, dropped when stale, released on failure).
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
//
// @spec openspec/specs/compliance/spec.md

import assert from 'node:assert/strict'
import { afterEach, beforeEach, mock, test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const TYPE = 'learniq-Regulation'
let store
let log
let warnings

/**
 * A store stand-in that logs every call.
 *
 * @param {object} answers What each store method answers.
 * @return {object} The store.
 */
function fakeStore(answers) {
	const s = {}
	for (const [name, answer] of Object.entries(answers)) {
		s[name] = (...args) => {
			log.push([name, ...args])
			return typeof answer === 'function' ? answer(...args) : answer
		}
	}
	return s
}

/**
 * A page on a slug, with $watch recorded.
 *
 * @param {string} slug The route slug.
 * @return {Promise<object>} The page.
 */
async function page(slug) {
	const view = await loadSfc('src/views/RegulationDetailPage.vue', {
		'@conduction/nextcloud-vue': { useObjectStore: () => store, CnDetailPage: {} },
	})
	const vm = instanceOf(view, { $route: { params: { slug } } })
	vm.watchers = []
	vm.$watch = (source, callback) => {
		vm.watchers.push({ source, callback })
		return () => log.push(['unwatch'])
	}
	return vm
}

beforeEach(() => {
	log = []
	warnings = []
	mock.method(console, 'warn', (...args) => warnings.push(args))
})

afterEach(() => mock.restoreAll())

test('a slug the matcher knows shows the regulation with its schema and subscribes to it', async () => {
	store = fakeStore({
		registerObjectType: undefined,
		fetchSchema: Promise.resolve({ title: 'Regulation' }),
		fetchObject: Promise.resolve({ id: 7, '@self': { uuid: 'u7' } }),
		fetchCollection: Promise.resolve([]),
		subscribe: Promise.resolve('h1'),
		unsubscribe: undefined,
		getObject: { id: 7, fresh: true },
	})
	const vm = await page('reg-avg')
	await vm.resolve()
	await new Promise((r) => setImmediate(r))
	assert.deepEqual(log.slice(0, 4), [
		['registerObjectType', TYPE, 'Regulation', 'learniq'],
		['fetchSchema', TYPE],
		['fetchObject', TYPE, 'reg-avg'],
		['subscribe', TYPE, 'u7'],
	])
	assert.equal(vm.objectId, '7')
	assert.equal(vm.loading, false)
	assert.equal(vm.notFound, false)
	assert.deepEqual(vm.detailObjectContext.value, { objectData: { id: 7, '@self': { uuid: 'u7' } }, schema: { title: 'Regulation' }, objectType: TYPE, objectId: '7', register: 'learniq', store })
	assert.equal(vm.liveHandle, 'h1')
	assert.equal(vm.liveKey, `${TYPE}::u7`)
	assert.equal(vm.livePendingKey, '')
	assert.equal(vm.watchers.length, 1)
	assert.deepEqual(vm.watchers[0].source(), { id: 7, fresh: true })
	vm.watchers[0].callback({ id: 7, newer: true })
	assert.deepEqual(vm.detailObjectContext.value.objectData, { id: 7, newer: true })
	vm.watchers[0].callback(null)
	assert.deepEqual(vm.detailObjectContext.value.objectData, { id: 7, newer: true })
	vm.liveKey = 'other'
	vm.watchers[0].callback({ id: 8 })
	assert.deepEqual(vm.detailObjectContext.value.objectData, { id: 7, newer: true })
	vm.liveKey = `${TYPE}::u7`
	await vm.syncLiveSubscription(store, { id: 7, '@self': { uuid: 'u7' } })
	assert.equal(log.filter((l) => l[0] === 'subscribe').length, 1)
})

test('a business-key slug falls back to the slug property, and the schema falls back to the stored one', async () => {
	store = fakeStore({
		fetchObject: Promise.reject(new Error('404')),
		fetchCollection: Promise.resolve([{ '@self': { id: 'abc' }, uuid: 'u2' }]),
		fetchSchema: Promise.reject(new Error('down')),
		getSchema: { title: 'Stored' },
		subscribe: Promise.resolve('h2'),
	})
	const vm = await page('AVG')
	await vm.resolve()
	assert.deepEqual(log.slice(0, 3), [['fetchSchema', TYPE], ['fetchObject', TYPE, 'AVG'], ['fetchCollection', TYPE, { slug: 'AVG', _limit: 1 }]])
	assert.equal(vm.objectId, 'abc')
	assert.deepEqual(vm.detailObjectContext.value.schema, { title: 'Stored' })
	assert.deepEqual(log.find((l) => l[0] === 'subscribe'), ['subscribe', TYPE, 'u2'])
})

test('no slug, or a slug nothing matches, shows not found and releases the subscription', async () => {
	store = fakeStore({ fetchCollection: Promise.resolve('not a list'), unsubscribe: undefined })
	const empty = await page('')
	empty.liveHandle = 'old'
	empty.liveUnwatch = () => log.push(['unwatch'])
	await empty.resolve()
	assert.deepEqual([empty.loading, empty.notFound, empty.liveHandle, empty.liveEpoch], [false, true, null, 1])
	assert.deepEqual(log, [['unwatch'], ['unsubscribe', 'old']])
	log = []
	const unknown = await page('nope')
	await unknown.resolve()
	assert.deepEqual(log, [['fetchCollection', TYPE, { slug: 'nope', _limit: 1 }]])
	assert.deepEqual([unknown.loading, unknown.notFound, unknown.objectId, unknown.detailObjectContext.value], [false, true, '', null])
	store = {}
	const bare = await page('x')
	await bare.resolve()
	assert.equal(bare.notFound, true)
	store = fakeStore({ fetchCollection: Promise.reject(new Error('x')) })
	const failing = await page('x')
	await failing.resolve()
	assert.equal(failing.notFound, true)
})

test('an object without an id or push key shows without a subscription', async () => {
	store = fakeStore({ fetchObject: Promise.resolve({ '@self': {}, title: 'T' }) })
	const vm = await page('t')
	await vm.resolve()
	assert.equal(vm.objectId, '')
	assert.equal(vm.detailObjectContext.value.schema, null)
	assert.equal(vm.liveEpoch, 0)
	store = fakeStore({ fetchObject: Promise.resolve({ '@self': { uuid: '' }, id: 0 }), subscribe: Promise.resolve('h') })
	const noKey = await page('t')
	await noKey.resolve()
	assert.equal(noKey.objectId, '0')
	assert.equal(noKey.liveEpoch, 1)
	assert.equal(log.some((l) => l[0] === 'subscribe'), false)
})

test('a subscription released while it was made is dropped, one in flight is not repeated, a failed one is reset', async () => {
	let release
	store = fakeStore({ subscribe: () => new Promise((r) => (release = r)), unsubscribe: undefined })
	const vm = await page('s')
	const first = vm.syncLiveSubscription(store, { uuid: 'u1' })
	assert.equal(vm.livePendingKey, `${TYPE}::u1`)
	await vm.syncLiveSubscription(store, { uuid: 'u1' })
	assert.equal(log.filter((l) => l[0] === 'subscribe').length, 1)
	vm.releaseLiveSubscription()
	release('stale')
	await first
	assert.deepEqual(log.at(-1), ['unsubscribe', 'stale'])
	assert.equal(vm.liveHandle, null)
	assert.equal(vm.watchers.length, 0)

	store = fakeStore({ subscribe: Promise.reject(new Error('no push')) })
	const failing = await page('s')
	failing.objectId = 'fallback'
	await failing.syncLiveSubscription(store, {})
	assert.deepEqual(log.at(-1), ['subscribe', TYPE, 'fallback'])
	assert.deepEqual([failing.liveHandle, failing.liveKey, failing.livePendingKey], [null, '', ''])
	assert.deepEqual(warnings.at(-1), ['[RegulationDetailPage] live subscription failed:', 'no push'])
	store = {}
	const noPush = await page('s')
	await noPush.syncLiveSubscription(store, { uuid: 'u' })
	assert.equal(noPush.liveEpoch, 0)
})
