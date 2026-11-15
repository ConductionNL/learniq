// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The mandatory training widget's read, pinned through the component's own
// method with a stubbed axios: only the signed-in learner's open mandatory
// enrolments, soonest due first.
//
// @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-29

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

let user
const http = { calls: [], answer: null }
const component = await loadSfc('src/views/widgets/MyMandatoryTrainingWidget.vue', {
	'@nextcloud/auth': { getCurrentUser: () => user },
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
 * A widget with an answer ready.
 *
 * @param {object|Error} answer The axios answer.
 * @return {object}
 */
function widget(answer) {
	http.calls = []
	http.answer = answer
	const vm = instanceOf(component)
	vm.enrolments = ['stale']
	return vm
}

test('without a signed-in user nothing is read and nothing is shown', async () => {
	user = null
	const vm = widget({ data: [] })
	await vm.fetchEnrolments()
	assert.deepEqual(http.calls, [])
	assert.deepEqual(vm.enrolments, [])
	assert.equal(vm.loading, false)
})

test('the read asks the learner open mandatory enrolments, soonest due first', async () => {
	user = { uid: 'sem' }
	const vm = widget({ data: { results: [{ id: 'e1' }] } })
	await vm.fetchEnrolments()
	assert.deepEqual(http.calls, [
		'/nc/apps/openregister/api/objects/learniq/Enrolment?_limit=20&learnerId=sem&mandatory=true&lifecycle__in=pending%2Cactive&_order=dueDate',
	])
	assert.deepEqual(vm.enrolments, [{ id: 'e1' }])
	assert.equal(vm.loading, false)
})

test('a bare list, an empty answer and a failed read', async () => {
	user = { uid: 'sem' }
	for (const [answer, rows] of [
		[{ data: [{ id: 'e2' }] }, [{ id: 'e2' }]],
		[{ data: null }, []],
		[{ data: { total: 0 } }, []],
		[new Error('offline'), []],
	]) {
		const vm = widget(answer)
		await vm.fetchEnrolments()
		assert.deepEqual(vm.enrolments, rows)
		assert.equal(vm.loading, false)
	}
})
