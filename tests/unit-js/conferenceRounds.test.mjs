// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The conference round picker that the guardian's booking screen and the
// coordinator's schedule board share, pinned: the rounds read for one
// lifecycle, the picker options, the selected round and its teachers.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
//
// @spec openspec/specs/parent-conferences/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views

import assert from 'node:assert/strict'
import { afterEach, beforeEach, mock, test } from 'node:test'
import { instanceOf, loadSfc, recordingAxios } from './support/loadSfc.mjs'

const SCREENS = [
	{
		file: 'src/views/BookConferenceSlotsView.vue',
		lifecycle: 'booking-open',
		loading: 'loading',
		label: '[BookConferenceSlotsView] loadRounds failed',
		teachers: (vm) => vm.teacherOptions,
	},
	{
		file: 'src/views/ConferenceScheduleBoard.vue',
		lifecycle: 'scheduled',
		loading: 'loadingRounds',
		label: '[ConferenceScheduleBoard] loadRounds failed',
		teachers: (vm) => vm.teacherOptions({}),
	},
]

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
 * A screen wired to a recording transport.
 *
 * @param {string} file The screen.
 * @param {Function} reply The transport's answer.
 * @return {Promise<{vm: object, http: object}>} The instance and its transport.
 */
async function screen(file, reply) {
	const http = recordingAxios(reply)
	const component = await loadSfc(file, {
		'@nextcloud/axios': { default: http },
		'@nextcloud/router': { generateUrl: (url) => `/nc${url}` },
	})
	return { vm: instanceOf(component), http }
}

for (const { file, lifecycle, loading, label, teachers } of SCREENS) {
	test(`${file}: the rounds of one lifecycle, from results, objects or a bare list`, async () => {
		for (const [data, expected] of [
			[{ results: [{ id: 'r1' }] }, [{ id: 'r1' }]],
			[{ objects: [{ id: 'r2' }] }, [{ id: 'r2' }]],
			[[{ id: 'r3' }], [{ id: 'r3' }]],
			[{ total: 0 }, { total: 0 }],
			[null, []],
		]) {
			const { vm, http } = await screen(file, () => ({ data }))
			await vm.loadRounds()
			assert.deepEqual(vm.rounds, expected, JSON.stringify(data))
			assert.equal(vm[loading], false)
			assert.deepEqual(http.calls.map((c) => c.url), [
				`/nc/apps/openregister/api/objects/learniq/conference-round?lifecycle=${lifecycle}&_limit=100`,
			])
		}
		const failure = new Error('500')
		const { vm } = await screen(file, () => {
			throw failure
		})
		vm.rounds = ['stale']
		await vm.loadRounds()
		assert.deepEqual(vm.rounds, [])
		assert.deepEqual(errors, [[label, failure]])
		assert.equal(vm[loading], false)
	})

	test(`${file}: the picker options, the selected round and its teachers`, async () => {
		const { vm } = await screen(file, () => ({ data: {} }))
		vm.rounds = [
			{ id: 'r1', name: 'Autumn', teacherIds: ['t1', 't2'] },
			{ uuid: 'r2' },
			{ id: 'r3', uuid: 'x3' },
		]
		assert.deepEqual(vm.roundOptions, [
			{ id: 'r1', label: 'Autumn' },
			{ id: 'r2', label: 'r2' },
			{ id: 'r3', label: 'r3' },
		])
		vm.selectedRoundId = ''
		assert.equal(vm.selectedRound, null)
		assert.deepEqual(teachers(vm), [])
		vm.selectedRoundId = 'r2'
		assert.deepEqual(vm.selectedRound, { uuid: 'r2' })
		assert.deepEqual(teachers(vm), [])
		vm.selectedRoundId = 'r1'
		assert.equal(vm.selectedRound, vm.rounds[0])
		assert.deepEqual(teachers(vm), [{ id: 't1', label: 't1' }, { id: 't2', label: 't2' }])
	})
}
