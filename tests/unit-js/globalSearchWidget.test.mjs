// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The fast-finder widget's search, pinned through the component's own method
// with a stubbed axios: a blank term searches nothing, a term searches people
// and cohorts, and a slower earlier search never overwrites a later one.
//
// @spec openspec/specs/dashboard/spec.md#requirement-a-fast-finder-query-builder-splits-one-search-term-into-per-kind-openregister-requests

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const http = { calls: [], answers: {} }
const component = await loadSfc('src/views/widgets/GlobalSearchWidget.vue', {
	'@nextcloud/axios': {
		default: {
			get: async (url) => {
				http.calls.push(url)
				const answer = http.answers[url.split('/').slice(-1)[0].split('?')[0]]
				if (typeof answer === 'function') return answer()
				return answer
			},
		},
	},
	'@nextcloud/router': { generateUrl: (path) => `/nc${path}` },
})

/**
 * A fresh widget.
 *
 * @param {string} query The typed term.
 * @return {object}
 */
function widget(query) {
	http.calls = []
	http.answers = {}
	return Object.assign(instanceOf(component), { query })
}

test('a blank term searches nothing and shows nothing', async () => {
	const vm = widget('   ')
	vm.hasSearched = true
	vm.results = { learners: [{ id: 'x' }], staff: [], cohorts: [] }
	await vm.runSearch()
	assert.deepEqual(http.calls, [])
	assert.equal(vm.hasSearched, false)
	assert.deepEqual(vm.results, { learners: [], staff: [], cohorts: [] })
	assert.equal(vm.requestToken, 0)
})

test('a term searches people and cohorts and groups the hits', async () => {
	const vm = widget(' Sem ')
	http.answers['learner-profile'] = { data: { results: [{ id: 'l1', givenName: 'Sem' }, { id: 's1', ncUserId: 'eva', roles: ['mentor'] }] } }
	http.answers.cohort = { data: [{ id: 'c1', name: '3B' }] }
	await vm.runSearch()
	assert.deepEqual(http.calls, [
		'/nc/apps/openregister/api/objects/learniq/learner-profile?_search=Sem&_limit=8',
		'/nc/apps/openregister/api/objects/learniq/cohort?_search=Sem&_limit=8',
	])
	assert.deepEqual(vm.results.learners.map((r) => r.label), ['Sem'])
	assert.deepEqual(vm.results.staff.map((r) => r.label), ['eva'])
	assert.deepEqual(vm.results.cohorts.map((r) => r.label), ['3B'])
	assert.equal(vm.hasSearched, true)
	assert.equal(vm.loading, false)
	assert.equal(vm.requestToken, 1)
})

test('a failed kind reads as no hits', async () => {
	const vm = widget('x')
	http.answers['learner-profile'] = () => {
		throw new Error('offline')
	}
	http.answers.cohort = { data: null }
	await vm.runSearch()
	assert.deepEqual(vm.results, { learners: [], staff: [], cohorts: [] })
	assert.equal(vm.hasSearched, true)
})

test('an earlier search that answers last is dropped and leaves loading to the later one', async () => {
	const vm = widget('first')
	let release
	http.answers['learner-profile'] = () => new Promise((resolve) => {
		release = () => resolve({ data: { results: [{ id: 'old', givenName: 'Old' }] } })
	})
	http.answers.cohort = { data: [] }
	const first = vm.runSearch()
	assert.equal(vm.loading, true)
	vm.requestToken++
	release()
	await first
	assert.equal(vm.hasSearched, false)
	assert.deepEqual(vm.results.learners, [])
	assert.equal(vm.loading, true)
})
