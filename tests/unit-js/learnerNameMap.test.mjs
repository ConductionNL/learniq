// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The pupil names the cohort gradebook and the hand-in status section show,
// pinned: one read of the learner profiles, a name per user id from the
// given and family name, and no names when the read fails.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
//
// @spec openspec/specs/assignments/spec.md#requirement-a-teacher-sees-who-has-not-handed-in-an-assignment

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { instanceOf, loadSfc, recordingAxios } from './support/loadSfc.mjs'

const PROFILES = [
	{ ncUserId: 'u1', givenName: 'Kim', familyName: 'de Vries' },
	{ ncUserId: 'u2', givenName: 'Sam' },
	{ ncUserId: 'u3', familyName: 'Jansen' },
	{ ncUserId: 'u4' },
	{ givenName: 'No', familyName: 'User' },
	{ ncUserId: 'u5', givenName: '', familyName: '' },
]

const SCREENS = [
	{ file: 'src/views/CohortGradebookView.vue', extra: {} },
	{ file: 'src/components/sections/AssignmentHandInStatus.vue', extra: { missing: ['u1'] } },
]

/**
 * A screen wired to a recording transport.
 *
 * @param {{file: string, extra: object}} screen The screen and its fields.
 * @param {Function} reply The transport's answer.
 * @return {Promise<{vm: object, http: object}>} The instance and its transport.
 */
async function load({ file, extra }, reply) {
	const http = recordingAxios(reply)
	const component = await loadSfc(file, {
		'@nextcloud/axios': { default: http },
		'@nextcloud/router': { generateUrl: (url) => `/nc${url}` },
		'@nextcloud/initial-state': { loadState: (app, key, fallback) => fallback },
	})
	return { vm: instanceOf(component, extra), http }
}

for (const screen of SCREENS) {
	test(`${screen.file}: names per user id from one profile read`, async () => {
		const { vm, http } = await load(screen, () => ({ data: { results: PROFILES } }))
		await vm.loadNames()
		assert.deepEqual(vm.names, { u1: 'Kim de Vries', u2: 'Sam', u3: 'Jansen' })
		assert.deepEqual(http.calls, [{
			method: 'get',
			url: '/nc/apps/openregister/api/objects/learniq/learner-profile',
			body: undefined,
			config: { params: { _limit: 1000 } },
		}])
	})

	test(`${screen.file}: a bare list is read too, and a failure leaves no names`, async () => {
		const { vm } = await load(screen, () => ({ data: [PROFILES[0]] }))
		await vm.loadNames()
		assert.deepEqual(vm.names, { u1: 'Kim de Vries' })
		const failing = await load(screen, () => {
			throw new Error('500')
		})
		failing.vm.names = { stale: 'x' }
		await failing.vm.loadNames()
		assert.deepEqual(failing.vm.names, {})
	})
}

test('the hand-in status reads no names when nobody is missing', async () => {
	const { vm, http } = await load({ file: SCREENS[1].file, extra: { missing: [] } }, () => ({ data: { results: PROFILES } }))
	vm.names = { kept: 'y' }
	await vm.loadNames()
	assert.deepEqual(http.calls, [])
	assert.deepEqual(vm.names, { kept: 'y' })
})
