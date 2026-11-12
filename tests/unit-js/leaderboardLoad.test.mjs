// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The leaderboard screen's first load, pinned through the component's own
// method with a stubbed axios: the active leaderboards become the cohort
// picker, and the first one's rankings are read.
//
// @spec openspec/specs/engagement/spec.md#scenario-a-cohort-member-opens-an-active-leaderboard-and-can-opt-out-from-within-it

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const http = { calls: [], answers: {} }
const component = await loadSfc('src/views/LeaderboardView.vue', {
	'@nextcloud/axios': {
		default: {
			get: async (url) => {
				http.calls.push(url)
				const answer = http.answers[url.split('?')[0]]
				if (answer instanceof Error || answer === undefined) throw answer ?? new Error('404')
				return answer
			},
		},
	},
	'@nextcloud/router': {
		generateUrl: (path, params = {}) => `/nc${path.replace(/\{(\w+)\}/g, (_, key) => params[key])}`,
	},
})
const BOARDS = '/nc/apps/openregister/api/objects/learniq/Leaderboard'

/**
 * A fresh leaderboard screen.
 *
 * @param {object} answers URL path to axios answer.
 * @return {object}
 */
function screen(answers) {
	http.calls = []
	http.answers = answers
	return instanceOf(component)
}

test('the active leaderboards with a cohort become the picker, and the first one is opened', async () => {
	const vm = screen({
		[BOARDS]: { data: { results: [{ cohortId: 'c1', name: 'Klas 1A' }, { name: 'No cohort' }, { cohortId: 'c2' }] } },
		'/nc/apps/learniq/api/leaderboard/c1': { data: { results: [{ rank: 1 }] } },
	})
	await vm.loadLeaderboards()
	assert.deepEqual(http.calls, [`${BOARDS}?lifecycle=active&_limit=100`, '/nc/apps/learniq/api/leaderboard/c1'])
	assert.deepEqual(vm.leaderboardOptions, [
		{ cohortId: 'c1', name: 'Klas 1A' },
		{ cohortId: 'c2', name: 'c2' },
	])
	assert.equal(vm.selectedCohortId, 'c1')
	assert.deepEqual(vm.rankings, [{ rank: 1 }])
	assert.equal(vm.loadingLeaderboards, false)
})

test('a bare list is read too; no leaderboard opens nothing', async () => {
	const bare = screen({ [BOARDS]: { data: [{ cohortId: 'c3', name: '' }] }, '/nc/apps/learniq/api/leaderboard/c3': { data: {} } })
	await bare.loadLeaderboards()
	assert.deepEqual(bare.leaderboardOptions, [{ cohortId: 'c3', name: 'c3' }])
	assert.deepEqual(bare.rankings, [])

	for (const data of [null, { total: 0 }, []]) {
		const vm = screen({ [BOARDS]: { data } })
		const before = vm.selectedCohortId
		await vm.loadLeaderboards()
		assert.deepEqual(vm.leaderboardOptions, [])
		assert.equal(vm.selectedCohortId, before)
		assert.equal(http.calls.length, 1)
	}
})

test('a failed read leaves an empty picker', async () => {
	const vm = screen({})
	vm.leaderboardOptions = [{ cohortId: 'old' }]
	await vm.loadLeaderboards()
	assert.deepEqual(vm.leaderboardOptions, [])
	assert.equal(vm.loadingLeaderboards, false)
})
