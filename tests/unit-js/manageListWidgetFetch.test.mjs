// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The dashboard list widget's read, pinned through the component's own
// method with a stubbed axios: it waits while the scope is pending, asks
// nothing when the scope matches no row, and otherwise asks OpenRegister for
// the top rows inside the scope.
//
// @spec openspec/changes/teacher-dashboard-own-groups/specs/dashboard/spec.md#requirement-the-teacher-dashboard-of-a-group-teacher-lists-only-their-own-groups

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const http = { calls: [], answer: null }
const component = await loadSfc('src/views/widgets/ManageListWidget.vue', {
	'@nextcloud/axios': {
		default: {
			get: async (url) => {
				http.calls.push(url)
				if (http.answer instanceof Error) throw http.answer
				return http.answer
			},
		},
	},
	'@nextcloud/router': { generateUrl: (path) => `/nc${path}` },
})

/**
 * A widget for the cohort schema.
 *
 * @param {object} props The props to set.
 * @return {object}
 */
function widget(props = {}) {
	http.calls = []
	return instanceOf(component, { schema: 'cohort', limit: 5, filter: {}, extend: [], pending: false, ...props })
}

test('a pending scope asks nothing and keeps loading', async () => {
	const vm = widget({ pending: true })
	vm.items = ['kept']
	await vm.fetchItems()
	assert.deepEqual(http.calls, [])
	assert.equal(vm.loading, true)
	assert.deepEqual(vm.items, ['kept'])
})

test('a scope that matches no row asks nothing and shows no rows', async () => {
	const vm = widget({ filter: { cohortId: [] } })
	vm.items = ['stale']
	await vm.fetchItems()
	assert.deepEqual(http.calls, [])
	assert.deepEqual(vm.items, [])
	assert.equal(vm.loading, false)
})

test('the read asks the top rows inside the scope, with the extends', async () => {
	const vm = widget({ filter: { cohortId: ['c1', 'c2'], lifecycle: 'active', skip: null }, extend: ['learners', 'course'], limit: 3 })
	http.answer = { data: { results: [{ id: 'c1' }] } }
	await vm.fetchItems()
	assert.deepEqual(http.calls, [
		'/nc/apps/openregister/api/objects/learniq/cohort?_limit=3&cohortId%5B%5D=c1&cohortId%5B%5D=c2&lifecycle=active&_extend=learners%2Ccourse',
	])
	assert.deepEqual(vm.items, [{ id: 'c1' }])
	assert.equal(vm.loading, false)
})

test('a bare list, an empty answer and a failed read', async () => {
	for (const [answer, items] of [
		[{ data: [{ id: 'a' }] }, [{ id: 'a' }]],
		[{ data: null }, []],
		[{ data: { total: 0 } }, []],
		[new Error('offline'), []],
	]) {
		const vm = widget()
		vm.items = ['stale']
		http.answer = answer
		await vm.fetchItems()
		assert.equal(http.calls[0], '/nc/apps/openregister/api/objects/learniq/cohort?_limit=5')
		assert.deepEqual(vm.items, items)
		assert.equal(vm.loading, false)
	}
})
