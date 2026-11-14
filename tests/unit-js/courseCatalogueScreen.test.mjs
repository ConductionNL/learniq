// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The course catalogue's load and sign-up, pinned through the component's own
// methods with a stubbed axios: the query it sends, the filter choices it
// collects, and the note a sign-up leaves on its card.
//
// @spec openspec/specs/enrolment/spec.md#scenario-a-learner-signs-up-for-an-open-course

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const http = { calls: [], answers: [] }

/**
 * Record an axios call and give the next answer.
 *
 * @param {string} method The axios method.
 * @param {Array} args Its arguments.
 * @return {Promise<object>}
 */
async function reply(method, args) {
	http.calls.push([method, ...args])
	const next = http.answers.shift()
	if (next instanceof Error) {
		throw next
	}
	return next ?? { data: {} }
}

const STUBS = {
	'@nextcloud/axios': {
		default: {
			get: (...args) => reply('get', args),
			post: (...args) => reply('post', args),
		},
	},
	'@nextcloud/router': { generateUrl: (path) => `/nc${path}` },
}
const component = await loadSfc('src/views/CourseCatalogue.vue', STUBS)

/**
 * A fresh catalogue.
 *
 * @param {object} state Data to set.
 * @return {object}
 */
function catalogue(state = {}) {
	http.calls = []
	http.answers = []
	return Object.assign(instanceOf(component), state)
}

const COURSES = [
	{ id: 'c1', provider: 'ROC', language: 'nl' },
	{ id: 'c2', provider: '', language: 'en' },
	{ id: 'c3', provider: 'ROC', language: 'nl' },
	{ id: 'c4', provider: 'TU' },
]

test('an unfiltered load collects the providers and languages', async () => {
	const vm = catalogue()
	http.answers.push({ data: { courses: COURSES, programmes: [{ id: 'p1' }] } })
	await vm.load()
	assert.deepEqual(http.calls, [
		['get', '/nc/apps/learniq/api/catalogue', { params: { search: undefined, provider: undefined, language: undefined } }],
	])
	assert.deepEqual(vm.courses, COURSES)
	assert.deepEqual(vm.programmes, [{ id: 'p1' }])
	assert.deepEqual(vm.providers, ['ROC', 'TU'])
	assert.deepEqual(vm.languages, ['nl', 'en'])
	assert.equal(vm.loading, false)
})

test('a filtered load sends the filters and keeps the earlier choices', async () => {
	for (const filter of [{ search: 'las' }, { provider: 'ROC' }, { language: 'en' }]) {
		const vm = catalogue({ ...filter, providers: ['kept'], languages: ['kept'] })
		http.answers.push({ data: { courses: [{ id: 'c9', provider: 'X', language: 'de' }] } })
		await vm.load()
		assert.deepEqual(http.calls[0][2].params, { search: undefined, provider: undefined, language: undefined, ...filter })
		assert.deepEqual(vm.programmes, [])
		assert.deepEqual([vm.providers, vm.languages], [['kept'], ['kept']])
	}
})

test('a failed load empties the cards but keeps the choices', async () => {
	const vm = catalogue({ courses: COURSES, programmes: [{ id: 'p1' }], providers: ['ROC'] })
	http.answers.push(new Error('offline'))
	await vm.load()
	assert.deepEqual([vm.courses, vm.programmes, vm.providers], [[], [], ['ROC']])
	assert.equal(vm.loading, false)
})

test('a sign-up posts to the course or programme and notes done or the refusals', async () => {
	const vm = catalogue({ notes: { other: { type: 'success', text: 'x' } } })
	http.answers.push({ data: {} }, { data: { courses: [] } })
	await vm.signUp({ id: 'c1', kind: 'course' })
	assert.deepEqual(http.calls.map((c) => c.slice(0, 2)), [
		['post', '/nc/apps/learniq/api/catalogue/courses/c1/sign-up'],
		['get', '/nc/apps/learniq/api/catalogue'],
	])
	assert.deepEqual(vm.notes, { other: { type: 'success', text: 'x' }, c1: { type: 'success', text: 'Done.' } })
	assert.equal(vm.busy, '')

	http.calls = []
	http.answers.push({ data: { refused: [{ message: 'Full.' }, { message: 'Closed.' }] } }, { data: {} })
	await vm.signUp({ id: 'p1', kind: 'programme' })
	assert.equal(http.calls[0][1], '/nc/apps/learniq/api/catalogue/programmes/p1/sign-up')
	assert.deepEqual(vm.notes.p1, { type: 'warning', text: 'Full. Closed.' })
})

test('a refused sign-up notes the server message or ours and does not reload', async () => {
	for (const [error, text] of [
		[Object.assign(new Error('409'), { response: { data: { message: 'Already signed up.' } } }), 'Already signed up.'],
		[new Error('offline'), 'Signing up did not work.'],
	]) {
		const vm = catalogue()
		http.answers.push(error)
		await vm.signUp({ id: 'c2' })
		assert.equal(http.calls.length, 1)
		assert.deepEqual(vm.notes, { c2: { type: 'error', text } })
		assert.equal(vm.busy, '')
	}
})
