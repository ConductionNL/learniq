// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The reads and writes the board dialogs share (dialogRequests.js): which
// OpenRegister url each one calls with which body, how the answer is
// unwrapped, who the caller is, and the refresh signal after a save.
//
// @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component

import assert from 'node:assert/strict'
import { register } from 'node:module'
import { test } from 'node:test'

// The Nextcloud packages need a browser window at import time. Resolve each
// to a stub that forwards to globalThis.__nc, which the tests set; only the
// HTTP client, the session, the event bus and the initial state are stubbed.
const stubs = {
	'@nextcloud/auth': 'export const getCurrentUser = () => globalThis.__nc.user',
	'@nextcloud/axios': 'export default { get: (...a) => globalThis.__nc.http("get", ...a), post: (...a) => globalThis.__nc.http("post", ...a), patch: (...a) => globalThis.__nc.http("patch", ...a) }',
	'@nextcloud/event-bus': 'export const emit = (...a) => globalThis.__nc.emitted.push(a)',
	'@nextcloud/initial-state': 'export const loadState = (...a) => globalThis.__nc.loadState(...a)',
	'@nextcloud/router': 'export const generateUrl = (path) => "/nc" + path',
}
const hooks = `
const stubs = ${JSON.stringify(stubs)}
export async function resolve(specifier, context, next) {
	if (specifier in stubs) {
		return { url: 'data:text/javascript,' + encodeURIComponent(stubs[specifier]), shortCircuit: true }
	}
	return next(specifier, context)
}
`
register('data:text/javascript,' + encodeURIComponent(hooks))

const requests = await import('../../src/dialogs/dialogRequests.js')

/**
 * Reset the stubs; every request answers `data`, or throws `error`.
 *
 * @param {object} [answer] `{ data }` to answer with, or `{ error }` to throw.
 * @return {object} The recording stubs.
 */
function nc(answer = { data: {} }) {
	globalThis.__nc = {
		calls: [],
		emitted: [],
		user: null,
		states: {},
		loadState: (app, key, fallback) => (key in globalThis.__nc.states ? globalThis.__nc.states[key] : fallback),
		http: async (method, url, ...rest) => {
			globalThis.__nc.calls.push([method, url, ...rest])
			if (answer.error) throw answer.error
			return { status: 200, data: answer.data }
		},
	}
	return globalThis.__nc
}

const BASE = '/nc/apps/openregister/api/objects/learniq'

test('readObject reads one object and unwraps it', async () => {
	const stub = nc({ data: { object: { id: 'u1', name: 'Wiskunde' } } })
	assert.deepEqual(await requests.readObject('course', 'u1'), { id: 'u1', name: 'Wiskunde' })
	assert.deepEqual(stub.calls, [['get', `${BASE}/course/u1`]])
})

test('readObject encodes the id and takes a bare body as the object', async () => {
	const stub = nc({ data: { id: 'a/b' } })
	assert.deepEqual(await requests.readObject('course', 'a/b'), { id: 'a/b' })
	assert.equal(stub.calls[0][1], `${BASE}/course/a%2Fb`)
})

test('listObjects sends the filters and returns the results page', async () => {
	const stub = nc({ data: { results: [{ id: 'e1' }, { id: 'e2' }], total: 2 } })
	const params = { learner: 'l1', _limit: 50 }
	assert.deepEqual(await requests.listObjects('enrolment', params), [{ id: 'e1' }, { id: 'e2' }])
	assert.deepEqual(stub.calls, [['get', `${BASE}/enrolment`, { params }]])
})

test('listObjects takes a bare array and sends no filters by default', async () => {
	const stub = nc({ data: [{ id: 'x' }] })
	assert.deepEqual(await requests.listObjects('cohort'), [{ id: 'x' }])
	assert.deepEqual(stub.calls[0][2], { params: {} })
})

test('listObjects reads anything else as no objects', async () => {
	for (const data of [null, {}, { results: 'nope' }, 'text']) {
		nc({ data })
		assert.deepEqual(await requests.listObjects('cohort'), [])
	}
})

test('createObject posts the body to the schema and unwraps the answer', async () => {
	const stub = nc({ data: { object: { id: 'n1', title: 'Note' } } })
	const body = { title: 'Note', learner: 'l1' }
	assert.deepEqual(await requests.createObject('dossier-note', body), { id: 'n1', title: 'Note' })
	assert.deepEqual(stub.calls, [['post', `${BASE}/dossier-note`, body]])
})

test('updateObject patches only the given properties of that object', async () => {
	const stub = nc({ data: { id: 's1', status: 'reopened' } })
	assert.deepEqual(await requests.updateObject('submission', 's1', { status: 'reopened' }), { id: 's1', status: 'reopened' })
	assert.deepEqual(stub.calls, [['patch', `${BASE}/submission/s1`, { status: 'reopened' }]])
})

test('an empty answer reads as an empty object', async () => {
	nc({ data: null })
	assert.deepEqual(await requests.createObject('cohort', {}), {})
	assert.deepEqual(await requests.updateObject('cohort', 'c1', {}), {})
	assert.deepEqual(await requests.readObject('cohort', 'c1'), {})
})

test('runTransition posts the action and its inputs and returns the raw answer', async () => {
	const stub = nc({ data: { object: { id: 'e1' }, lifecycle: 'withdrawn' } })
	const answer = await requests.runTransition('e1', 'withdraw', { reason: 'moved' })
	assert.deepEqual(answer, { object: { id: 'e1' }, lifecycle: 'withdrawn' })
	assert.deepEqual(stub.calls, [
		['post', '/nc/apps/openregister/api/objects/e1/transition', { action: 'withdraw', data: { reason: 'moved' } }],
	])
})

test('runTransition sends empty inputs by default', async () => {
	const stub = nc()
	await requests.runTransition('e1', 'approve')
	assert.deepEqual(stub.calls[0][2], { action: 'approve', data: {} })
})

test('a refused request rejects with the axios error', async () => {
	const error = Object.assign(new Error('Request failed with status code 403'), { response: { status: 403, data: {} } })
	nc({ error })
	await assert.rejects(requests.readObject('course', 'u1'), error)
	await assert.rejects(requests.listObjects('course'), error)
	await assert.rejects(requests.createObject('course', {}), error)
	await assert.rejects(requests.updateObject('course', 'u1', {}), error)
	await assert.rejects(requests.runTransition('u1', 'approve'), error)
})

test('currentUserId is the signed-in uid, or empty when nobody is', () => {
	const stub = nc()
	stub.user = { uid: 'eva', displayName: 'Eva' }
	assert.equal(requests.currentUserId(), 'eva')
	stub.user = null
	assert.equal(requests.currentUserId(), '')
	stub.user = {}
	assert.equal(requests.currentUserId(), '')
})

test('callerTenant reads learniq\'s callerTenant state, trimmed', () => {
	const stub = nc()
	const asked = []
	const load = stub.loadState
	stub.loadState = (...args) => {
		asked.push(args)
		return load(...args)
	}
	stub.states.callerTenant = '  school-1  '
	assert.equal(requests.callerTenant(), 'school-1')
	assert.deepEqual(asked, [['learniq', 'callerTenant', null]])
})

test('callerTenant is null when the state is missing, blank or not a string', () => {
	const stub = nc()
	assert.equal(requests.callerTenant(), null)
	stub.states.callerTenant = '   '
	assert.equal(requests.callerTenant(), null)
	stub.states.callerTenant = 42
	assert.equal(requests.callerTenant(), null)
})

test('announceSaved refreshes the page first, then speaks on its own channel', () => {
	const stub = nc()
	const payload = { id: 'n1' }
	requests.announceSaved('learniq:dossier-note:saved', payload)
	assert.deepEqual(stub.emitted, [
		['cn:page:refresh', {}],
		['learniq:dossier-note:saved', payload],
	])
})
