// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// What the learner's optional-lessons screen (MyElectives) and the
// coordinator's roster (ElectiveRosterView) do alike, pinned: a lesson's
// date and time, the server's reason for a refusal, and which lesson a
// sign-up or placement names (the session, else the timetable reference).
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
//
// @spec openspec/specs/enrolment/spec.md#requirement-a-learner-signs-up-for-an-optional-lesson-inside-the-window
// @spec openspec/specs/enrolment/spec.md#requirement-a-coordinator-places-learners-who-missed-the-window

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { instanceOf, loadSfc, recordingAxios } from './support/loadSfc.mjs'

/**
 * A screen wired to a recording transport.
 *
 * @param {string} file The screen.
 * @param {Function} reply The transport's answer.
 * @return {Promise<{vm: object, http: object}>} The instance and its transport.
 */
async function screen(file, reply = () => ({ data: {} })) {
	const http = recordingAxios(reply)
	const component = await loadSfc(file, {
		'@nextcloud/axios': { default: http },
		'@nextcloud/l10n': { translate: (app, text) => `T:${text}`, translatePlural: () => '' },
		'@nextcloud/router': {
			generateUrl: (url, vars) => '/nc' + url.replace(/\{(\w+)\}/g, (w, k) => (vars && k in vars ? vars[k] : w)),
		},
	})
	return { vm: instanceOf(component, { $route: { params: { id: 'o1' } } }), http }
}

const SCREENS = ['src/views/MyElectives.vue', 'src/views/ElectiveRosterView.vue']
const OPTIONS = { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }

for (const file of SCREENS) {
	test(`${file}: a lesson's date and time, or nothing when it has none`, async () => {
		const { vm } = await screen(file)
		const iso = '2026-03-04T10:15:00Z'
		assert.equal(vm.formatDate(iso), new Date(iso).toLocaleString(undefined, OPTIONS))
		assert.equal(vm.formatDate(''), '')
		assert.equal(vm.formatDate(undefined), '')
		assert.equal(vm.formatDate('not a date'), '')
	})

	test(`${file}: the server's reason, else a general one`, async () => {
		const { vm } = await screen(file)
		assert.equal(vm.reason({ response: { data: { error: 'Full' } } }), 'Full')
		assert.equal(vm.reason({ response: { data: { error: '' } } }), 'T:Something went wrong. Try again.')
		assert.equal(vm.reason(new Error('x')), 'T:Something went wrong. Try again.')
		assert.equal(vm.reason(undefined), 'T:Something went wrong. Try again.')
	})
}

test('MyElectives signs up for the session, else the timetable reference', async () => {
	const { vm, http } = await screen('src/views/MyElectives.vue')
	await vm.signUp({ id: 'o1' }, { sessionId: 's1', timetableSessionRef: 'r1' })
	await vm.signUp({ id: 'o2' }, { sessionId: '', timetableSessionRef: 'r2' })
	const posts = http.calls.filter((c) => c.method === 'post').map((c) => [c.url, c.body])
	assert.deepEqual(posts, [
		['/nc/apps/learniq/api/electives/o1/sign-up', { sessionId: 's1' }],
		['/nc/apps/learniq/api/electives/o2/sign-up', { timetableSessionRef: 'r2' }],
	])
})

test('ElectiveRosterView places on the session, else the timetable reference', async () => {
	const { vm, http } = await screen('src/views/ElectiveRosterView.vue')
	await vm.place({ sessionId: 's1', timetableSessionRef: 'r1' }, 'p1')
	await vm.place({ timetableSessionRef: 'r2' }, 'p2')
	const posts = http.calls.filter((c) => c.method === 'post').map((c) => [c.url, c.body])
	assert.deepEqual(posts, [
		['/nc/apps/learniq/api/electives/o1/place', { learnerId: 'p1', sessionId: 's1' }],
		['/nc/apps/learniq/api/electives/o1/place', { learnerId: 'p2', timetableSessionRef: 'r2' }],
	])
	assert.equal(vm.busy, false)
})
