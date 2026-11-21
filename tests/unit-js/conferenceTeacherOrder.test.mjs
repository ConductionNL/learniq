// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The guardian's booking page asks for teachers in order of preference: it
// offers only the round's own teachers, sends them in the order picked as
// `requestedTeacherIds`, and sends nothing without a chosen teacher.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
//
// @spec openspec/changes/capability-rows-say-what-they-do/specs/parent-conferences/spec.md#requirement-a-guardian-chooses-which-teachers-they-want-to-speak-to-in-a-signup

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { instanceOf, loadSfc, recordingAxios } from './support/loadSfc.mjs'

// The page reads `t` as Nextcloud's page-wide global, not as an import.
globalThis.t = (app, text) => text

const ROUND = {
	id: 'round-1',
	lifecycle: 'booking-open',
	teacherIds: ['mentor-de-vries', 'wiskunde-jansen', 'engels-bakker', 'biologie-smit', 'gym-visser'],
}

/**
 * The booking page over a recording transport, with the round and the child chosen.
 *
 * @param {Function} reply The transport's answer.
 * @return {Promise<{vm: object, http: object}>} The instance and its transport.
 */
async function bookingPage(reply = () => ({ data: { id: 'signup-1', lifecycle: 'submitted' } })) {
	const http = recordingAxios(reply)
	const component = await loadSfc('src/views/BookConferenceSlotsView.vue', {
		'@nextcloud/axios': { default: http },
		'@nextcloud/router': { generateUrl: (url) => '/nc' + url },
		'@nextcloud/l10n': { translate: (app, text) => text },
		'@nextcloud/auth': { getCurrentUser: () => ({ uid: 'ouder-fatima' }) },
	})
	const vm = instanceOf(component)
	vm.rounds = [ROUND]
	vm.selectedRoundId = 'round-1'
	vm.selectedLearnerId = 'vera'
	return { vm, http }
}

test('Scenario "A guardian asks for the mentor and the maths teacher": the order picked is the order sent', async () => {
	const { vm, http } = await bookingPage()
	vm.selectedTeacherIds = ['mentor-de-vries', 'wiskunde-jansen']

	await vm.submitSignup()

	const posts = http.calls.filter((c) => c.method === 'post')
	assert.equal(posts.length, 1)
	assert.equal(posts[0].url, '/nc/apps/learniq/api/conference-signups')
	assert.deepEqual(posts[0].body.requestedTeacherIds, ['mentor-de-vries', 'wiskunde-jansen'], 'the mentor first, the maths teacher second')
	assert.equal(posts[0].body.conferenceRoundId, 'round-1')
	assert.equal(posts[0].body.learnerId, 'vera')
	assert.equal(vm.submitSuccess, true)

	vm.selectedTeacherIds = ['wiskunde-jansen', 'mentor-de-vries']
	await vm.submitSignup()
	assert.deepEqual(
		http.calls.filter((c) => c.method === 'post')[1].body.requestedTeacherIds,
		['wiskunde-jansen', 'mentor-de-vries'],
		'another order is kept as picked, never sorted'
	)
})

test('Scenario "A teacher outside the round cannot be asked for": only the round\'s teachers are offered', async () => {
	const { vm } = await bookingPage()

	assert.deepEqual(vm.teacherOptions.map((o) => o.id), ROUND.teacherIds)
	assert.equal(vm.teacherOptions.some((o) => o.id === 'drama-de-groot'), false)
})

test('A signup without a chosen teacher is not submitted from the page', async () => {
	const { vm, http } = await bookingPage()
	vm.selectedTeacherIds = []
	assert.equal(vm.canSubmit, false)

	await vm.submitSignup()

	assert.equal(http.calls.length, 0)
	assert.equal(vm.submitSuccess, false)
})

test('Another round clears the teachers picked for the earlier one', async () => {
	const { vm } = await bookingPage()
	vm.selectedTeacherIds = ['mentor-de-vries']

	vm.onRoundChange()

	assert.deepEqual(vm.selectedTeacherIds, [])
})
