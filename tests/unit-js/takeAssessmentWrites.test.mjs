// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The assessment screen's writes: the attempt is created, proctoring flags and
// transitions are sent, answers are saved and the attempt is handed in, and
// the choices of a choice item are read from its QTI body. Pinned through the
// component's own methods with a recording fetch and a frozen clock.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { afterEach, beforeEach, mock, test } from 'node:test'
import { loadSfcScript } from './sfcScript.mjs'

const NOW = Date.UTC(2026, 10, 11, 9, 0, 0)
const OCS = { 'OCS-APIREQUEST': 'true', Accept: 'application/json' }
const JSON_HEADERS = { ...OCS, 'Content-Type': 'application/json' }

const stubs = {
	'@conduction/nextcloud-vue': {},
	'@nextcloud/auth': { getCurrentUser: () => stubs.user },
	'@nextcloud/router': { generateUrl: (path) => `/index.php${path}` },
}

const component = await loadSfcScript('src/views/TakeAssessmentView.vue', stubs)
const calls = []
let answers = []
let errors = []

/**
 * A fetch response.
 *
 * @param {number} status the HTTP status
 * @param {*} body what json() resolves to; a function throws instead
 * @return {object}
 */
function response(status, body = {}) {
	return {
		ok: status >= 200 && status < 300,
		status,
		headers: { get: () => null },
		json: async () => {
			if (typeof body === 'function') {
				body()
			}
			return body
		},
	}
}

/**
 * A stand-in `this` carrying every method of the component.
 *
 * @param {object} state the data fields the test sets
 * @return {object}
 */
function view(state = {}) {
	const ctx = { t: (app, text) => text, lastFlagAt: {}, ...state }
	for (const [name, fn] of Object.entries(component.methods)) {
		if (!(name in ctx)) {
			ctx[name] = fn.bind(ctx)
		}
	}
	return ctx
}

/**
 * The fetch calls as [url, init] with the body parsed.
 *
 * @return {Array}
 */
function sent() {
	return calls.map(([url, init]) => [url, init && init.body ? { ...init, body: JSON.parse(init.body) } : init])
}

beforeEach(() => {
	mock.timers.enable({ apis: ['Date'], now: NOW })
	calls.length = 0
	answers = []
	errors = []
	stubs.user = { uid: 'pupil-1' }
	globalThis.fetch = async (url, init) => {
		calls.push([url, init])
		const next = answers.shift()
		if (next instanceof Error) {
			throw next
		}
		return next ?? response(200)
	}
	mock.method(console, 'error', (...args) => errors.push(args))
})

afterEach(() => {
	mock.timers.reset()
	mock.restoreAll()
})

test('an attempt is created in progress for the current learner', async () => {
	answers = [response(201, { object: { uuid: 'r-1' } })]
	const ctx = view({ assessment: { tenant_id: 't-1' }, accessCode: '' })
	await ctx.createResult('a-1')
	assert.deepEqual(sent(), [[
		'/index.php/apps/openregister/api/objects/learniq/assessment-result',
		{
			method: 'POST',
			headers: JSON_HEADERS,
			body: {
				assessmentId: 'a-1',
				learnerId: 'pupil-1',
				attemptNumber: 1,
				responses: [],
				startedAt: new Date(NOW).toISOString(),
				lifecycle: 'in-progress',
				tenant_id: 't-1',
			},
		},
	]])
	assert.equal(ctx.resultId, 'r-1')
})

test('an attempt with an access code carries it, and an anonymous learner is named so', async () => {
	stubs.user = null
	answers = [response(201, { id: 'r-2' })]
	const ctx = view({ accessCode: 'XK42' })
	await ctx.createResult('a-1')
	const body = sent()[0][1].body
	assert.equal(body.learnerId, 'anonymous')
	assert.equal(body.tenant_id, '')
	assert.equal(body.accessCode, 'XK42')
	assert.equal(ctx.resultId, 'r-2')
})

test('a refused attempt carries the gate reason; another failure only the status', async () => {
	answers = [response(422, { errors: { reason: 'window-closed', message: 'Closed.' } })]
	await assert.rejects(view().createResult('a-1'), (err) => {
		assert.equal(err.message, 'AssessmentResult create failed: 422')
		assert.equal(err.gateReason, 'window-closed')
		assert.equal(err.gateMessage, 'Closed.')
		return true
	})
	answers = [response(422, { error: 'No.' })]
	await assert.rejects(view().createResult('a-1'), (err) => err.gateReason === null && err.gateMessage === 'No.')
	answers = [response(422, () => { throw new Error('not json') })]
	await assert.rejects(view().createResult('a-1'), (err) => err.gateReason === null && err.gateMessage === null)
	answers = [response(500)]
	await assert.rejects(view().createResult('a-1'), (err) => err.message === 'AssessmentResult create failed: 500' && !('gateReason' in err))
})

test('the native proctoring session is created and then activated', async () => {
	answers = [response(201, { object: { uuid: 'p-1' } }), response(200)]
	const ctx = view({ resultId: 'r-1', assessment: { tenant_id: 't-1' } })
	await ctx.createProctoringSession()
	assert.deepEqual(sent(), [
		['/index.php/apps/openregister/api/objects/learniq/proctoring-session', {
			method: 'POST',
			headers: JSON_HEADERS,
			body: { assessmentResultId: 'r-1', learnerId: 'pupil-1', provider: 'native-test-mode', status: 'created', tenant_id: 't-1' },
		}],
		['/index.php/apps/openregister/api/objects/p-1/transition', { method: 'POST', headers: JSON_HEADERS, body: { action: 'activate' } }],
	])
	assert.deepEqual(ctx.proctoringSession, { uuid: 'p-1' })
})

test('a failed session create or activation rejects with its status', async () => {
	answers = [response(500)]
	await assert.rejects(view({ resultId: 'r-1' }).createProctoringSession(), { message: 'ProctoringSession create failed: 500' })
	answers = [response(201, { uuid: 'p-2' }), response(409)]
	const ctx = view({ resultId: 'r-1' })
	await assert.rejects(ctx.createProctoringSession(), { message: 'ProctoringSession activate transition failed: 409' })
	assert.deepEqual(ctx.proctoringSession, { uuid: 'p-2' })
})

test('a flag is appended once per kind per five seconds and kept on success', async () => {
	await view().appendFlag('tab-hidden', 'low')
	assert.equal(calls.length, 0)

	const ctx = view({ proctoringSession: { uuid: 'p-1', flags: [{ flagId: 'old' }] }, generateId: () => 'f-1' })
	await ctx.appendFlag('tab-hidden', 'low')
	const flag = { flagId: 'f-1', kind: 'tab-hidden', occurredAt: new Date(NOW).toISOString(), severity: 'low', reviewDecision: 'pending' }
	assert.deepEqual(sent(), [['/index.php/apps/openregister/api/objects/learniq/proctoring-session/p-1', {
		method: 'PATCH',
		headers: JSON_HEADERS,
		body: { flags: [{ flagId: 'old' }, flag] },
	}]])
	assert.deepEqual(ctx.proctoringSession.flags, [{ flagId: 'old' }, flag])
	assert.deepEqual(ctx.lastFlagAt, { 'tab-hidden': NOW })

	await ctx.appendFlag('tab-hidden', 'low')
	assert.equal(calls.length, 1)
	mock.timers.tick(5000)
	answers = [response(500)]
	await ctx.appendFlag('tab-hidden', 'high')
	assert.equal(calls.length, 2)
	assert.equal(ctx.proctoringSession.flags.length, 2)
	assert.equal(errors.length, 1)
	assert.equal(errors[0][0], '[TakeAssessmentView] appendFlag error')
	assert.equal(errors[0][1].message, 'Flag append failed: 500')
})

test('teardown releases the lock, leaves fullscreen and ends the session', async () => {
	const order = []
	globalThis.document = {
		fullscreenElement: {},
		exitFullscreen: async () => { order.push('exit'); throw new Error('denied') },
	}
	const ctx = view({
		proctoringSession: { uuid: 'p-1' },
		detachNativeHardening: () => order.push('detach'),
		releaseTabLock: () => order.push('release'),
	})
	await ctx.teardownNativeTestMode()
	assert.deepEqual(order, ['detach', 'release', 'exit'])
	assert.deepEqual(sent(), [['/index.php/apps/openregister/api/objects/p-1/transition', { method: 'POST', headers: JSON_HEADERS, body: { action: 'end' } }]])
	assert.equal(errors.length, 0)

	globalThis.document = { fullscreenElement: null }
	answers = [response(500)]
	await ctx.teardownNativeTestMode()
	assert.equal(errors[0][0], '[TakeAssessmentView] teardownNativeTestMode error')
	assert.equal(errors[0][1].message, 'ProctoringSession end transition failed: 500')

	calls.length = 0
	ctx.proctoringSession = null
	await ctx.teardownNativeTestMode()
	assert.equal(calls.length, 0)
})

test('autosave patches the answers only while an attempt is open', async () => {
	for (const state of [{}, { resultId: 'r-1', submitted: true }, { resultId: 'r-1', submitting: true }]) {
		await view(state).autosave()
	}
	assert.equal(calls.length, 0)
	const ctx = view({ resultId: 'r-1', items: [{ uuid: 'i1', interactionType: 'choice' }], responses: { i1: 'B' } })
	await ctx.autosave()
	assert.equal(sent()[0][0], '/index.php/apps/openregister/api/objects/learniq/assessment-result/r-1')
	assert.equal(sent()[0][1].method, 'PATCH')
	assert.deepEqual(sent()[0][1].headers, JSON_HEADERS)
	assert.deepEqual(Object.keys(sent()[0][1].body), ['responses'])
	answers = [response(503)]
	await assert.rejects(ctx.autosave(), { message: 'autosave failed: 503' })
})

test('handing in saves the answers, submits, and tears native mode down', async () => {
	const ctx = view({ resultId: 'r-1', items: [], responses: {}, nativeTestModeActive: true, clearTimer: () => {} })
	let torn = 0
	ctx.teardownNativeTestMode = async () => { torn++ }
	const handing = ctx.submitAssessment()
	assert.equal(ctx.submitting, true)
	await handing
	assert.deepEqual(sent().map(([url, init]) => [url, init.method, init.body]), [
		['/index.php/apps/openregister/api/objects/learniq/assessment-result/r-1', 'PATCH', { responses: [], submittedAt: new Date(NOW).toISOString() }],
		['/index.php/apps/openregister/api/objects/r-1/transition', 'POST', { action: 'submit' }],
	])
	assert.equal(ctx.submitted, true)
	assert.equal(ctx.submitting, false)
	assert.equal(ctx.submitError, null)
	assert.equal(torn, 1)
})

test('a failed save or submit leaves the attempt open with an error', async () => {
	for (const [first, second, message] of [[response(500), null, 'Response save failed: 500'], [response(200), response(409), 'Submit transition failed: 409']]) {
		calls.length = 0
		errors = []
		answers = [first, second].filter(Boolean)
		const ctx = view({ resultId: 'r-1', items: [], responses: {}, nativeTestModeActive: false, clearTimer: () => {} })
		await ctx.submitAssessment()
		assert.equal(ctx.submitted, undefined)
		assert.equal(ctx.submitting, false)
		assert.equal(ctx.submitError, 'Failed to submit assessment. Please try again.')
		assert.equal(errors[0][0], '[TakeAssessmentView] submitAssessment error')
		assert.equal(errors[0][1].message, message)
	}
	calls.length = 0
	await view({}).submitAssessment()
	assert.equal(calls.length, 0)
})

/**
 * A DOMParser stand-in that finds `<simpleChoice>` elements by pattern.
 */
class FakeDomParser {

	parseFromString(xml) {
		const found = [...xml.matchAll(/<simpleChoice(?: identifier="([^"]*)")?>([^<]*)<\/simpleChoice>/g)]
		return {
			getElementsByTagName: () => found.map(([, identifier, text]) => ({
				getAttribute: () => identifier ?? null,
				textContent: text,
			})),
		}
	}

}

test('choices come in the QTI order, the server order, or as two placeholders', () => {
	globalThis.DOMParser = FakeDomParser
	const extract = component.methods.extractChoices
	const qti = '<simpleChoice identifier="A"> Celwand </simpleChoice><simpleChoice identifier="B">Celkern</simpleChoice><simpleChoice> Vacuole </simpleChoice>'
	const declared = [{ id: 'A', label: 'Celwand' }, { id: 'B', label: 'Celkern' }, { id: 'Vacuole', label: 'Vacuole' }]
	assert.deepEqual(extract(''), [])
	assert.deepEqual(extract(null), [])
	assert.deepEqual(extract('<x/>'), [{ id: 'a', label: 'Option A' }, { id: 'b', label: 'Option B' }])
	assert.deepEqual(extract(qti), declared)
	assert.deepEqual(extract(qti, []), declared)
	assert.deepEqual(extract(qti, ['B', 'Vacuole']), [declared[1], declared[2], declared[0]])
	assert.deepEqual(extract(qti, ['Z']), declared)
	assert.deepEqual(extract(qti, ['Z', 'A']), [declared[0], declared[1], declared[2]])
})

test('every method the screen calls on itself is defined', () => {
	const source = readFileSync(new URL('../../src/views/TakeAssessmentView.vue', import.meta.url), 'utf8')
	const script = source.slice(source.indexOf('<script'), source.indexOf('</script>'))
	const called = new Set([...script.matchAll(/this\.([a-zA-Z]+)\(/g)].map((match) => match[1]))
	for (const name of called) {
		if (name === 't' || name === 'n' || name.startsWith('$')) {
			continue
		}
		assert.ok(
			typeof component.methods[name] === 'function' || typeof component.computed?.[name] === 'function',
			`${name} is called but not defined`,
		)
	}
	assert.equal(component.methods.extractPrompt('<p>Two  <b>plus</b> two?</p>'), 'Two plus two?')
})
