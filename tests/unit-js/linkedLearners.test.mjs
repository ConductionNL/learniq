// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The learner picker of the two guardian screens (book a conference,
// pick electives), pinned: the caller's linked children and the caller's own
// profile are read in one go, merged by user id, and a failed read leaves an
// empty list.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
//
// @spec openspec/specs/parent-conferences/spec.md#scenario-a-linked-guardian-can-submit-a-signup-for-their-own-child
// @spec openspec/specs/school-structure/spec.md#requirement-frontend-is-declarative-with-one-named-subject-choice-picker-exception

import assert from 'node:assert/strict'
import { afterEach, beforeEach, mock, test } from 'node:test'
import { instanceOf, loadSfc, recordingAxios } from './support/loadSfc.mjs'

const SCREENS = [
	{ file: 'src/views/BookConferenceSlotsView.vue', label: '[BookConferenceSlotsView] loadLearners failed' },
	{ file: 'src/views/SubjectChoicePicker.vue', label: '[SubjectChoicePicker] loadLearners failed' },
]

const CHILDREN = '/nc/apps/openregister/api/objects/learniq/learner-profile?parentIds=g1&_limit=50'
const SELF = '/nc/apps/openregister/api/objects/learniq/learner-profile?ncUserId=g1&_limit=1'

let errors

beforeEach(() => {
	errors = []
	globalThis.t = (app, text) => text
	mock.method(console, 'error', (...args) => errors.push(args))
})

afterEach(() => {
	mock.restoreAll()
	delete globalThis.t
})

/**
 * A screen instance wired to a recording transport and a signed-in user.
 *
 * @param {string} file The screen.
 * @param {{uid: string|undefined, reply: Function}} setup The user and the answers.
 * @return {Promise<{vm: object, http: object}>} The instance and its transport.
 */
async function screen(file, { uid, reply }) {
	const http = recordingAxios(reply)
	const component = await loadSfc(file, {
		'@nextcloud/axios': { default: http },
		'@nextcloud/auth': { getCurrentUser: () => (uid ? { uid } : null) },
		'@nextcloud/router': {
			generateUrl: (url, vars) => '/nc' + url.replace(/\{(\w+)\}/g, (w, k) => (vars && k in vars ? vars[k] : w)),
		},
	})
	return { vm: instanceOf(component), http }
}

for (const { file, label } of SCREENS) {
	test(`${file}: no signed-in user reads nothing`, async () => {
		const { vm, http } = await screen(file, { uid: undefined, reply: () => ({ data: {} }) })
		vm.learners = ['kept']
		await vm.loadLearners()
		assert.equal(http.calls.length, 0)
		assert.deepEqual(vm.learners, ['kept'])
		assert.equal(vm.loadingLearners, false)
	})

	test(`${file}: children and self are read in order and merged by user id`, async () => {
		const { vm, http } = await screen(file, {
			uid: 'g1',
			reply: ({ url }) =>
				url === CHILDREN
					? { data: { results: [{ ncUserId: 'k1', displayName: 'Kim' }, { ncUserId: 'g1', displayName: 'Old' }] } }
					: { data: { objects: [{ ncUserId: 'g1', displayName: 'Gert' }] } },
		})
		const pending = vm.loadLearners()
		assert.equal(vm.loadingLearners, true)
		await pending
		assert.deepEqual(http.calls.map((c) => [c.method, c.url]), [['get', CHILDREN], ['get', SELF]])
		assert.deepEqual(vm.learners, [{ ncUserId: 'k1', displayName: 'Kim' }, { ncUserId: 'g1', displayName: 'Gert' }])
		assert.deepEqual(vm.learnerOptions, [{ id: 'k1', label: 'Kim' }, { id: 'g1', label: 'Gert' }])
		assert.equal(vm.loadingLearners, false)
		assert.equal(errors.length, 0)
	})

	test(`${file}: a bare list or an empty answer counts as no rows`, async () => {
		const { vm } = await screen(file, {
			uid: 'g1',
			reply: ({ url }) => (url === CHILDREN ? { data: [{ ncUserId: 'k1' }] } : { data: null }),
		})
		await vm.loadLearners()
		assert.deepEqual(vm.learners, [])
	})

	test(`${file}: a failed read leaves no learners and logs once`, async () => {
		const failure = new Error('403')
		const { vm } = await screen(file, {
			uid: 'g1',
			reply: ({ url }) => {
				if (url === SELF) throw failure
				return { data: { results: [{ ncUserId: 'k1' }] } }
			},
		})
		vm.learners = ['stale']
		await vm.loadLearners()
		assert.deepEqual(vm.learners, [])
		assert.deepEqual(errors, [[label, failure]])
		assert.equal(vm.loadingLearners, false)
	})
}
