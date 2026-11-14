// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The scoring view's load, pinned through the component's own method with a
// stubbed axios: the assessment, each of its items once, the submitted
// attempts, and the drafts seeded from them.
//
// @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const http = { calls: [], answers: {} }
const STUBS = {
	'@nextcloud/axios': {
		default: {
			get: async (url, options) => {
				http.calls.push([url, options])
				const answer = http.answers[url]
				if (answer instanceof Error || answer === undefined) {
					throw answer ?? new Error(`no answer for ${url}`)
				}
				return answer
			},
		},
	},
	'@nextcloud/router': { generateUrl: (path) => `/nc${path}` },
}
const component = await loadSfc('src/views/AssessmentScoringView.vue', STUBS)
const OBJECTS = '/nc/apps/openregister/api/objects/learniq'

/**
 * A scoring view for one assessment.
 *
 * @param {object} answers URL to axios answer.
 * @return {object}
 */
function view(answers) {
	http.calls = []
	http.answers = answers
	return instanceOf(component, { $route: { params: { assessmentId: 'a 1' } } })
}

test('the load reads the assessment, each item once, then the submitted attempts', async () => {
	const vm = view({
		[`${OBJECTS}/exam/a%201`]: { data: { object: { itemRefs: [{ itemId: 'i1' }, { itemId: 'i2' }, { itemId: 'i1' }, {}] } } },
		[`${OBJECTS}/item/i1`]: { data: { id: 'i1', kind: 'essay', prompt: 'Leg uit' } },
		[`${OBJECTS}/item/i2`]: new Error('gone'),
		[`${OBJECTS}/assessment-result`]: {
			data: {
				objects: [
					{ uuid: 'r1', lifecycle: 'submitted', responses: [] },
					{ id: 'r2', lifecycle: 'graded' },
				],
			},
		},
	})
	await vm.load()
	assert.deepEqual(http.calls.map(([url]) => url), [
		`${OBJECTS}/exam/a%201`,
		`${OBJECTS}/item/i1`,
		`${OBJECTS}/item/i2`,
		`${OBJECTS}/assessment-result`,
	])
	assert.deepEqual(http.calls[3][1], { params: { assessmentId: 'a 1', _limit: 500 } })
	assert.deepEqual(vm.results.map((r) => [r.id, r.lifecycle]), [['r1', 'submitted']])
	assert.deepEqual(Object.keys(vm.drafts), ['r1'])
	assert.deepEqual(vm.drafts.r1, vm.draftsFor(vm.results[0]))
	assert.equal(vm.loadError, '')
	assert.equal(vm.loading, false)
})

test('the items found are the ones the open questions are built from', async () => {
	const answers = {
		[`${OBJECTS}/exam/a%201`]: { data: { itemRefs: [{ itemId: 'i1' }] } },
		[`${OBJECTS}/item/i1`]: { data: { object: { id: 'i1', kind: 'essay' } } },
		[`${OBJECTS}/assessment-result`]: { data: { results: [] } },
	}
	const vm = view(answers)
	await vm.load()
	const { manualItems } = await import('../../src/utils/manualScoring.js')
	assert.deepEqual(vm.items, manualItems({ itemRefs: [{ itemId: 'i1' }] }, { i1: { id: 'i1', kind: 'essay' } }))
	assert.deepEqual(vm.results, [])
	assert.deepEqual(vm.drafts, {})
})

test('an assessment without items reads no item; a failed read shows the error', async () => {
	const vm = view({
		[`${OBJECTS}/exam/a%201`]: { data: null },
		[`${OBJECTS}/assessment-result`]: { data: null },
	})
	await vm.load()
	assert.equal(http.calls.length, 2)
	assert.deepEqual(vm.results, [])

	const broken = view({ [`${OBJECTS}/exam/a%201`]: { data: {} } })
	await broken.load()
	assert.equal(broken.loadError, 'The attempts could not be loaded.')
	assert.equal(broken.loading, false)
})
