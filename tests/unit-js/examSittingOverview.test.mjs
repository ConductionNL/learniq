// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The extra time and invigilators section on an exam sitting, pinned: the two
// exam schedule routes it reads, the accommodated learners by name, the places
// line, the empty lists, a refusal hiding the section, and "Ask" writing a
// pending request through OpenRegister and reading both routes again.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
//
// @spec openspec/changes/exam-sitting-overview-section/specs/exam-schedule/spec.md#requirement-an-exam-sitting-shows-each-accommodated-learners-end-time
// @spec openspec/changes/exam-sitting-overview-section/specs/exam-schedule/spec.md#requirement-an-exam-sitting-shows-its-invigilator-places-and-who-is-free

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { instanceOf, loadSfc, recordingAxios } from './support/loadSfc.mjs'

const FILE = 'src/components/sections/ExamSittingOverview.vue'

const OVERVIEW = {
	sitting: { id: 's-1' },
	accommodations: [{ learnerId: 'kim', endsAt: '2026-11-16T11:30:00', extraTimePercent: 25, separateRoom: true }],
	invigilators: { needed: 3, confirmed: ['anna'], pending: [], open: 2 },
}

/**
 * The section for sitting s-1, wired to a recording transport.
 *
 * @param {Function} reply The transport's answer per call.
 * @return {Promise<{vm: object, http: object, emitted: string[]}>} The instance, its transport and its events.
 */
async function section(reply) {
	const http = recordingAxios(reply)
	const component = await loadSfc(FILE, {
		'@nextcloud/axios': { default: http },
		'@nextcloud/router': {
			generateUrl: (url) => `/nc${url}`,
			generateOcsUrl: (url) => `/ocs/${url}`,
		},
	})
	const emitted = []
	const vm = instanceOf(component, { objectId: 's-1', $emit: (name) => emitted.push(name) })
	return { vm, http, emitted }
}

/**
 * The routes answering as for a sitting with one accommodated learner and one free colleague.
 *
 * @param {object} call The recorded call.
 * @return {object} The response.
 */
function routes(call) {
	if (call.url.endsWith('/overview')) return { data: OVERVIEW }
	if (call.url.endsWith('/available-invigilators')) return { data: { invigilators: ['bram'] } }
	if (call.url.includes('learner-profile')) return { data: { results: [{ ncUserId: 'kim', givenName: 'Kim', familyName: 'de Vries' }] } }
	if (call.url.includes('core/autocomplete')) return { data: { ocs: { data: [{ id: 'bram', label: 'Bram Jansen' }, { id: 'bramx', label: 'Other' }] } } }
	return { data: {} }
}

test('reads the overview and the free colleagues, with their names', async () => {
	const { vm, http } = await section(routes)
	await vm.load()

	assert.deepEqual(http.calls.slice(0, 2).map((c) => [c.method, c.url]), [
		['get', '/nc/apps/learniq/api/exam-sittings/s-1/overview'],
		['get', '/nc/apps/learniq/api/exam-sittings/s-1/available-invigilators'],
	])
	assert.equal(vm.hidden, false)
	assert.equal(vm.loading, false)
	assert.equal(vm.accommodations.length, 1)
	assert.equal(vm.learnerName('kim'), 'Kim de Vries')
	assert.deepEqual(vm.free, ['bram'])
	assert.equal(vm.personName('bram'), 'Bram Jansen')
	assert.equal(vm.placesLine, '1 of 3 confirmed, 0 asked, 2 open')
})

test('a learner whose profile the caller cannot read is "A learner", and an end time reads as a clock time', async () => {
	const { vm } = await section(routes)
	assert.equal(vm.learnerName('unknown'), 'A learner')
	assert.match(vm.clockTime('2026-11-16T11:30:00'), /11.30/)
	assert.equal(vm.clockTime('not a date'), 'not a date')
})

test('nobody with extra time and nobody free leave both lists empty', async () => {
	const { vm, http } = await section((call) => {
		if (call.url.endsWith('/overview')) return { data: { accommodations: [], invigilators: { needed: 1, confirmed: [], pending: [], open: 1 } } }
		return { data: { invigilators: [] } }
	})
	await vm.load()

	assert.deepEqual(vm.accommodations, [])
	assert.deepEqual(vm.free, [])
	assert.equal(vm.placesLine, '0 of 1 confirmed, 0 asked, 1 open')
	assert.equal(http.calls.some((c) => c.url.includes('learner-profile')), false, 'no names read for nobody')
})

test('a sitting the caller may not read, or a non-planner, hides the section', async () => {
	for (const status of [404, 403]) {
		const { vm } = await section(() => {
			throw Object.assign(new Error('refused'), { response: { status } })
		})
		await vm.load()
		assert.equal(vm.hidden, true, `status ${status}`)
		assert.equal(vm.overview, null)
		assert.equal(vm.loadError, '')
	}

	const { vm } = await section(() => {
		throw Object.assign(new Error('down'), { response: { status: 500 } })
	})
	await vm.load()
	assert.equal(vm.hidden, false)
	assert.equal(vm.loadError, 'The extra time and invigilators could not be loaded.')
})

test('"Ask" writes a pending request through OpenRegister, reads both routes again and refreshes the list', async () => {
	let asked = false
	const { vm, http, emitted } = await section((call) => {
		if (call.method === 'post') {
			asked = true
			return { data: { id: 'ia-1' } }
		}
		if (call.url.endsWith('/overview')) {
			return { data: { ...OVERVIEW, invigilators: { needed: 3, confirmed: ['anna'], pending: asked ? ['bram'] : [], open: asked ? 1 : 2 } } }
		}
		if (call.url.endsWith('/available-invigilators')) return { data: { invigilators: asked ? [] : ['bram'] } }
		return routes(call)
	})
	await vm.load()
	http.calls.length = 0

	await vm.ask('bram')

	assert.deepEqual(http.calls[0], {
		method: 'post',
		url: '/nc/apps/openregister/api/objects/learniq/invigilator-assignment',
		body: { examSittingId: 's-1', invigilatorId: 'bram' },
		config: undefined,
	})
	assert.deepEqual(http.calls.slice(1, 3).map((c) => c.url), [
		'/nc/apps/learniq/api/exam-sittings/s-1/overview',
		'/nc/apps/learniq/api/exam-sittings/s-1/available-invigilators',
	])
	assert.equal(vm.placesLine, '1 of 3 confirmed, 1 asked, 1 open')
	assert.deepEqual(vm.free, [])
	assert.deepEqual(emitted, ['refresh'])
	assert.equal(vm.asking, '')
})

test('a refused request shows the server\'s message and changes nothing', async () => {
	const { vm, emitted } = await section((call) => {
		if (call.method === 'post') {
			throw Object.assign(new Error('422'), { response: { status: 422, data: { message: 'Bram is not available then.' } } })
		}
		return routes(call)
	})
	await vm.load()

	await vm.ask('bram')

	assert.equal(vm.askError, 'Bram is not available then.')
	assert.deepEqual(emitted, [])
	assert.deepEqual(vm.free, ['bram'])
})
