// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The subject choice picker submits what the learner picked in one call and
// says it was submitted for review; a refusal says why it may have failed.
// The server side (the choice created and its submit transition fired in the
// learner's own name, then validated against the plan) is pinned by
// RequestSubmitControllerTest and SubjectChoiceValidatorTest.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
//
// @spec openspec/changes/capability-rows-say-what-they-do/specs/school-structure/spec.md#requirement-a-learner-picks-and-submits-their-electives-from-the-picker

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { instanceOf, loadSfc, recordingAxios } from './support/loadSfc.mjs'

/**
 * The picker over a recording transport, with Sem's picks filled in.
 *
 * @param {Function} reply The transport's answer.
 * @return {Promise<{vm: object, http: object}>} The instance and its transport.
 */
async function picker(reply) {
	const http = recordingAxios(reply)
	const component = await loadSfc('src/views/SubjectChoicePicker.vue', {
		'@nextcloud/axios': { default: http },
		'@nextcloud/router': { generateUrl: (url) => '/nc' + url },
		'@nextcloud/l10n': { getLanguage: () => 'nl' },
		'@nextcloud/auth': { getCurrentUser: () => ({ uid: 'sem' }) },
	})
	const vm = instanceOf(component)
	vm.plans = [{ id: 'plan-havo', electiveCourseIds: ['c-bio', 'c-eco', 'c-ak'], electiveRules: { minElectives: 2, maxElectives: 2 } }]
	vm.selectedPlanId = 'plan-havo'
	vm.selectedLearnerId = 'sem'
	vm.academicYear = '2026-2027'
	vm.selectedCourseIds = ['c-bio', 'c-eco']
	return { vm, http }
}

test('Scenario "A learner submits a valid choice": one call with the picks, then the review message', async () => {
	const { vm, http } = await picker(() => ({ data: { id: 'choice-1', lifecycle: 'submitted' } }))
	assert.equal(vm.canSubmit, true)

	await vm.submitChoice()

	const posts = http.calls.filter((c) => c.method === 'post')
	assert.equal(posts.length, 1)
	assert.equal(posts[0].url, '/nc/apps/learniq/api/subject-choices')
	assert.deepEqual(posts[0].body, {
		learnerId: 'sem',
		curriculumPlanId: 'plan-havo',
		academicYear: '2026-2027',
		selectedElectiveCourseIds: ['c-bio', 'c-eco'],
		guardianConsentGiven: vm.guardianConsentGiven,
	})
	assert.equal(vm.submitSuccess, true, 'the picker says the choice was submitted for review')
	assert.equal(vm.submitError, '')
	assert.deepEqual(vm.selectedCourseIds, [], 'the picks are cleared for a next choice')
	assert.equal(vm.submitting, false)
})

test('A refused submit says why it may have failed and keeps the picks', async () => {
	const { vm } = await picker(() => {
		throw Object.assign(new Error('Forbidden'), { response: { status: 403 } })
	})
	const original = console.error
	console.error = () => {}
	try {
		await vm.submitChoice()
	} finally {
		console.error = original
	}

	assert.equal(vm.submitSuccess, false)
	assert.match(vm.submitError, /could not submit your subject choice/)
	assert.deepEqual(vm.selectedCourseIds, ['c-bio', 'c-eco'])
	assert.equal(vm.submitting, false)
})

test('Nothing is sent before a plan, a year and an elective are picked', async () => {
	const { vm, http } = await picker(() => ({ data: {} }))
	vm.selectedCourseIds = []
	assert.equal(vm.canSubmit, false)

	await vm.submitChoice()

	assert.equal(http.calls.length, 0)
	assert.equal(vm.submitSuccess, false)
})

test('One elective against a minimum of two is named before submitting', async () => {
	const { vm } = await picker(() => ({ data: {} }))
	vm.selectedCourseIds = ['c-bio']
	assert.deepEqual(vm.feedback, ['Select at least 2 elective(s) (currently 1).'], 'the live feedback names the minimum')
})
