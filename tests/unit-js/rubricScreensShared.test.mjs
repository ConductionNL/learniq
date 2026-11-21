// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// What the three rubric marking screens (MarkSubmissionView,
// SelfAssessmentView, PeerReviewMarkingView) do alike, pinned: a rubric is
// complete once every criterion has a level, saved rubric scores come back
// as the selected levels, and the optional "first row of a list" reads leave
// the field alone when refused and set the first row, or null, otherwise.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
//
// @spec openspec/specs/assignments/spec.md#scenario-a-learner-completes-a-self-assessment-before-submitting
// @spec openspec/specs/assignments/spec.md#scenario-marksubmissionview-shows-peer-and-self-assessment-as-read-only-context

import assert from 'node:assert/strict'
import { afterEach, beforeEach, mock, test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const STUBS = {
	'@nextcloud/router': { generateUrl: (url) => `/nc${url}` },
	'@nextcloud/auth': { getCurrentUser: () => ({ uid: 'u1' }), getRequestToken: () => 'tok' },
}
const READ = { 'OCS-APIREQUEST': 'true', Accept: 'application/json' }

let calls
let answer
let errors

beforeEach(() => {
	calls = []
	errors = []
	answer = { status: 200, json: {} }
	globalThis.fetch = async (url, init) => {
		calls.push({ url, headers: init?.headers })
		return { ok: answer.status < 300, status: answer.status, json: async () => answer.json }
	}
	mock.method(console, 'error', (...args) => errors.push(args))
})

afterEach(() => {
	mock.restoreAll()
	delete globalThis.fetch
})

const RUBRIC = { criteria: [{ criterionId: 'c1' }, { criterionId: 'c2' }] }
const SCORES = [{ criterionId: 'c1', levelId: 'l1', points: 3, extra: 'x' }, { criterionId: 'c2', levelId: 'l2', points: 0 }]
const LEVELS = { c1: { levelId: 'l1', points: 3 }, c2: { levelId: 'l2', points: 0 } }

for (const file of ['src/views/SelfAssessmentView.vue', 'src/views/PeerReviewMarkingView.vue']) {
	test(`${file}: a rubric is complete once every criterion has a level`, async () => {
		const vm = instanceOf(await loadSfc(file, STUBS), { assignmentId: 'a1', id: 'x1' })
		for (const [rubric, levels, expected] of [
			[null, {}, true],
			[{}, {}, true],
			[{ criteria: [] }, {}, true],
			[RUBRIC, {}, false],
			[RUBRIC, { c1: { levelId: 'l1', points: 1 } }, false],
			[RUBRIC, { c1: { levelId: 'l1', points: 1 }, c2: null }, false],
			[RUBRIC, { c1: { levelId: 'l1', points: 1 }, c2: { levelId: 'l2', points: 0 } }, true],
		]) {
			vm.rubric = rubric
			vm.selectedLevels = levels
			assert.equal(vm.canSubmit, expected, JSON.stringify([rubric, levels]))
		}
	})
}

test('the self-assessment screen restores the learner\'s saved scores and comments', async () => {
	const vm = instanceOf(await loadSfc('src/views/SelfAssessmentView.vue', STUBS), {
		assignmentId: 'a1',
		id: 's1',
		loadSubmission: async function() { this.submission = { id: 's1' } },
		loadAssignment: async function() { this.assignment = {} },
		loadExistingSelfAssessment: async function() { this.selfAssessment = { rubricScores: SCORES, comments: 'Mine' } },
	})
	vm.selectedLevels = { c9: { levelId: 'l9', points: 9 } }
	await vm.loadData('s1')
	assert.deepEqual(vm.selectedLevels, { c9: { levelId: 'l9', points: 9 }, ...LEVELS })
	assert.equal(vm.comments, 'Mine')
	assert.equal(vm.loading, false)
	assert.equal(vm.error, null)
})

test('the peer review screen restores the saved scores and comments', async () => {
	const vm = instanceOf(await loadSfc('src/views/PeerReviewMarkingView.vue', STUBS), {
		assignmentId: 'a1',
		id: 'pr1',
		loadPeerReview: async function() { this.peerReview = { rubricScores: SCORES } },
		loadAssignment: async function() { this.assignment = {} },
		loadWork: async () => {},
	})
	await vm.loadData('pr1')
	assert.deepEqual(vm.selectedLevels, LEVELS)
	assert.equal(vm.comments, '')
	assert.equal(vm.error, null)
})

test('the marking screen restores the submission\'s saved scores', async () => {
	const vm = instanceOf(await loadSfc('src/views/MarkSubmissionView.vue', STUBS), {
		assignmentId: 'a1',
		id: 's1',
		loadSubmission: async function() { this.submission = { rubricScores: SCORES, feedbackText: 'Good', proposedGrade: 7 } },
		loadAssignment: async function() { this.assignment = {} },
		loadMarks: async () => {},
		loadPeerFeedbackSummary: async () => {},
		loadSelfAssessment: async () => {},
	})
	await vm.loadData('s1')
	assert.deepEqual(vm.selectedLevels, LEVELS)
	assert.deepEqual([vm.feedbackText, vm.manualGrade, vm.error], ['Good', 7, null])
})

const FIRST_ROW_READS = [
	{
		file: 'src/views/SelfAssessmentView.vue',
		method: 'loadExistingSelfAssessment',
		field: 'selfAssessment',
		url: '/nc/apps/openregister/api/objects/learniq/self-assessment?submissionId=s1&learnerId=u1&_limit=1',
	},
	{
		file: 'src/views/MarkSubmissionView.vue',
		method: 'loadPeerFeedbackSummary',
		field: 'peerFeedbackSummary',
		url: '/nc/apps/openregister/api/objects/learniq/peer-feedback-summary?submissionId=s1&_limit=1',
	},
	{
		file: 'src/views/MarkSubmissionView.vue',
		method: 'loadSelfAssessment',
		field: 'selfAssessment',
		url: '/nc/apps/openregister/api/objects/learniq/self-assessment?submissionId=s1&_limit=1',
	},
]

for (const { file, method, field, url } of FIRST_ROW_READS) {
	test(`${file} ${method}: the first row, null when none, untouched when refused`, async () => {
		const vm = instanceOf(await loadSfc(file, STUBS), { assignmentId: 'a1', id: 's1' })
		for (const [json, expected] of [
			[{ results: [{ id: 'r1' }, { id: 'r2' }] }, { id: 'r1' }],
			[{ objects: [{ id: 'o1' }] }, { id: 'o1' }],
			[[{ id: 'a1' }], { id: 'a1' }],
			[{ results: [] }, null],
			[{}, null],
		]) {
			answer = { status: 200, json }
			vm[field] = 'before'
			await vm[method]('s1')
			assert.deepEqual(vm[field], expected, JSON.stringify(json))
		}
		answer = { status: 403, json: null }
		vm[field] = 'before'
		await vm[method]('s1')
		assert.equal(vm[field], 'before')
		assert.ok(calls.every((c) => c.url === url && JSON.stringify(c.headers) === JSON.stringify(READ)))
		assert.equal(calls.length, 6)
	})
}
