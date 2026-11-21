// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The open material search modal's search and facet labels, pinned through
// the component's own methods with a stubbed axios.
//
// @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const http = { calls: [], answer: null }
const component = await loadSfc('src/modals/OpenMaterialSearchModal.vue', {
	'@nextcloud/axios': {
		default: {
			get: async (url, options) => {
				http.calls.push([url, options])
				if (http.answer instanceof Error) throw http.answer
				return http.answer
			},
		},
	},
	'@nextcloud/router': { generateUrl: (path) => `/nc${path}` },
})

/**
 * A modal with a typed query.
 *
 * @param {object} state Data to set.
 * @return {object}
 */
function modal(state = {}) {
	http.calls = []
	const vm = Object.assign(instanceOf(component), state)
	vm.$data = vm
	return vm
}

test('a first search asks the counts and shows results or empty', async () => {
	const vm = modal({ query: ' betoog ', refusal: 'old' })
	http.answer = { data: { total: 2, records: [{}, {}], facets: { lom: [] } } }
	await vm.search()
	assert.equal(http.calls[0][1].params.query, 'betoog')
	assert.equal(http.calls[0][1].params.facets, true)
	assert.equal(http.calls[0][1].params.page, 1)
	assert.deepEqual(vm.result, { total: 2, records: [{}, {}], facets: { lom: [] } })
	assert.deepEqual([vm.page, vm.searched, vm.state, vm.refusal, vm.loading], [1, 'betoog', 'results', '', false])

	http.answer = { data: { total: 0 } }
	await vm.search(1)
	assert.equal(vm.state, 'empty')
})

test('turning a page of the same search keeps its counts; a new term asks them again', async () => {
	const vm = modal({ query: 'betoog', searched: 'betoog', result: { total: 30, facets: { kept: true } } })
	http.answer = { data: { total: 30, records: [{ id: 'p2' }], facets: { fresh: true } } }
	await vm.search(2)
	assert.equal(http.calls[0][1].params.facets, false)
	assert.deepEqual(vm.result, { total: 30, records: [{ id: 'p2' }], facets: { kept: true } })
	assert.equal(vm.page, 2)

	const other = modal({ query: 'essay', searched: 'betoog', result: { facets: { old: true } } })
	http.answer = { data: { total: 1, facets: { fresh: true } } }
	await other.search(2)
	assert.equal(http.calls[0][1].params.facets, true)
	assert.deepEqual(other.result.facets, { fresh: true })
})

test('a failed search shows the service as down', async () => {
	const vm = modal({ query: 'x', result: { total: 3 } })
	http.answer = new Error('503')
	await vm.search(1)
	assert.equal(vm.state, 'down')
	assert.deepEqual(vm.result, { total: 3 })
	assert.equal(vm.loading, false)
})

test('facet rows are labelled per filter', () => {
	const vm = modal()
	assert.equal(vm.facetLabel('levels', 'VO'), vm.levelLabel('VO'))
	assert.equal(vm.facetLabel('licenses', 'CC-BY-4.0'), 'CC BY 4.0')
	assert.equal(vm.facetLabel('licenses', 'nonsense'), 'No open licence')
	assert.equal(vm.facetLabel('collections', 'KlasCement'), 'KlasCement (Flanders)')
	assert.equal(vm.facetLabel('collections', 'Wikiwijs'), 'Wikiwijs')
	assert.equal(vm.facetLabel('formats', 'text/html'), 'Webpage')
	assert.equal(vm.facetLabel('formats', 'video/mp4'), 'Video')
	assert.equal(vm.facetLabel('formats', 'application/pdf'), 'pdf')
	assert.equal(vm.facetLabel('anything', 'application/pdf'), 'pdf')
})
