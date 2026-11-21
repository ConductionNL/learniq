// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The scoring view marks one open question across every submitted attempt
// before the next one: it shows question 1 of 2 with every attempt's answer,
// saves that question's scores on each attempt and nothing else, moves to
// question 2 with the same attempts, and refuses a score above the maximum
// before anything is saved.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
//
// @spec openspec/changes/capability-rows-say-what-they-do/specs/assessment/spec.md#requirement-a-teacher-scores-the-same-open-question-across-every-learners-attempt-before-the-next-question

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { instanceOf, loadSfc, recordingAxios } from './support/loadSfc.mjs'

const OBJECTS = '/nc/apps/openregister/api/objects/learniq'
const ATTEMPTS = 25

/**
 * The 25 submitted attempts of a test with two open questions.
 *
 * @return {Array<object>} The attempts as the store answers them.
 */
function attempts() {
	return Array.from({ length: ATTEMPTS }, (_, i) => ({
		id: `attempt-${i + 1}`,
		lifecycle: 'submitted',
		responses: [
			{ itemId: 'q1', response: { value: `Antwoord ${i + 1} op vraag 1` }, manualScore: null },
			{ itemId: 'q2', response: { value: `Antwoord ${i + 1} op vraag 2` }, manualScore: null },
		],
	}))
}

/**
 * The scoring view over a recording transport, loaded.
 *
 * @return {Promise<{vm: object, http: object}>} The instance and its transport.
 */
async function scoringView() {
	const answers = {
		[`${OBJECTS}/exam/toets-1`]: { data: { id: 'toets-1', itemRefs: [{ itemId: 'q1' }, { itemId: 'q2', points: 4 }] } },
		[`${OBJECTS}/item/q1`]: { data: { id: 'q1', title: 'Leg uit', interactionType: 'extendedText', maxScore: 6 } },
		[`${OBJECTS}/item/q2`]: { data: { id: 'q2', title: 'Beschrijf', interactionType: 'extendedText', maxScore: 10 } },
		[`${OBJECTS}/assessment-result`]: { data: { results: attempts() } },
	}
	const http = recordingAxios((call) => {
		if (call.method === 'patch') return { data: {} }
		const answer = answers[call.url]
		if (answer === undefined) throw new Error(`no answer for ${call.url}`)
		return answer
	})
	const component = await loadSfc('src/views/AssessmentScoringView.vue', {
		'@nextcloud/axios': { default: http },
		'@nextcloud/router': { generateUrl: (path) => `/nc${path}` },
	})
	const vm = instanceOf(component, {
		$route: { params: { assessmentId: 'toets-1' } },
		n: (app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count)),
	})
	await vm.load()
	return { vm, http }
}

test('Scenario "A teacher marks question 3 for the whole class": one question, every attempt, saved on each', async () => {
	const { vm, http } = await scoringView()

	assert.equal(vm.items.length, 2, 'Question 1 of 2')
	assert.equal(vm.index, 0)
	assert.equal(vm.current.itemId, 'q1')
	assert.equal(vm.results.length, ATTEMPTS, 'every submitted attempt is in the one list')
	assert.equal(vm.answerText(vm.results[7]), 'Antwoord 8 op vraag 1')

	for (const result of vm.results) vm.drafts[result.id].q1 = '3'
	await vm.saveScores()

	const patches = http.calls.filter((c) => c.method === 'patch')
	assert.equal(patches.length, ATTEMPTS, 'each attempt is saved')
	for (const patch of patches) {
		const [q1, q2] = patch.body.responses
		assert.equal(q1.manualScore, 3, 'question 1 carries its score')
		assert.equal(q2.manualScore, null, 'question 2 is left as it was')
	}
	assert.equal(vm.messageType, 'success')
	assert.equal(vm.message, '25 scores saved.')

	// "Next question" in the page header moves the index on.
	vm.index += 1
	assert.equal(vm.current.itemId, 'q2')
	assert.equal(vm.current.max, 4, 'the points of the test set the maximum')
	assert.equal(vm.results.length, ATTEMPTS, 'the same 25 attempts answer question 2')
	assert.equal(vm.answerText(vm.results[0]), 'Antwoord 1 op vraag 2')
})

test('Scenario "A score above the maximum is refused": the field says so and nothing is saved', async () => {
	const { vm, http } = await scoringView()
	vm.index = 1
	vm.drafts['attempt-1'].q2 = '5'

	assert.equal(vm.rowError(vm.results[0]), 'The score is higher than the maximum.')
	assert.equal(vm.hasErrors, true, 'the save button is disabled while a score is refused')

	vm.drafts['attempt-1'].q2 = '-1'
	assert.equal(vm.rowError(vm.results[0]), 'Enter a number of zero or more.')

	vm.drafts['attempt-1'].q2 = '4'
	assert.equal(vm.rowError(vm.results[0]), '')
	assert.equal(vm.hasErrors, false)
	assert.equal(http.calls.filter((c) => c.method === 'patch').length, 0, 'nothing was saved while it was refused')
})
