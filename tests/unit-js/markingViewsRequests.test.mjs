// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The requests the three rubric marking screens send, pinned call by call:
// the teacher's marking (MarkSubmissionView), the learner's self-assessment
// (SelfAssessmentView) and the peer review (PeerReviewMarkingView). Every
// URL, method, header and body, the order they go in, and what the screen
// shows when one fails. The clock is frozen for the grade entry's stamp.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
//
// @spec openspec/specs/assignments/spec.md#scenario-a-learner-completes-a-self-assessment-before-submitting

import assert from 'node:assert/strict'
import { afterEach, beforeEach, mock, test } from 'node:test'
import { transitionUrl } from '../../src/utils/customPages.js'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const STUBS = {
	'@nextcloud/router': { generateUrl: (url) => `/nc${url}` },
	'@nextcloud/auth': { getCurrentUser: () => ({ uid: 'u1' }), getRequestToken: () => 'tok' },
}
const READ = { 'OCS-APIREQUEST': 'true', Accept: 'application/json' }
const WRITE = { 'OCS-APIREQUEST': 'true', Accept: 'application/json', 'Content-Type': 'application/json' }

let calls
let answers
let errors

/**
 * Answer the next fetch with the first answer whose test matches.
 *
 * @param {string} url The URL.
 * @param {object} init The request.
 * @return {Promise<object>} A Response stand-in.
 */
async function fakeFetch(url, init) {
	calls.push({ url, method: init?.method, headers: init?.headers, body: init?.body === undefined ? undefined : JSON.parse(init.body) })
	const answer = answers.find((a) => a.match(url, init)) ?? { status: 200, json: {} }
	return { ok: answer.status >= 200 && answer.status < 300, status: answer.status, json: async () => answer.json }
}

beforeEach(() => {
	calls = []
	answers = []
	errors = []
	globalThis.fetch = fakeFetch
	mock.method(console, 'error', (...args) => errors.push(args))
	mock.timers.enable({ apis: ['Date'], now: new Date('2026-03-04T10:00:00Z') })
})

afterEach(() => {
	mock.restoreAll()
	mock.timers.reset()
	delete globalThis.fetch
})

const when = (part, status, json, method) => ({ match: (url, init) => url.includes(part) && (method === undefined || init?.method === method), status, json })
const LEVELS = { c1: { levelId: 'l1', points: 3 }, c2: { levelId: 'l2', points: 0 } }

test('the marking screen reads its objects and refuses a missing submission or assignment', async () => {
	const view = await loadSfc('src/views/MarkSubmissionView.vue', STUBS)
	const vm = instanceOf(view, { assignmentId: 'a1', id: 's1' })
	answers = [when('Submission/s1', 200, { object: { id: 's1' } }), when('Assignment/a1', 200, { id: 'a1' }), when('Rubric/r1', 500, null)]
	await vm.loadSubmission('s1')
	await vm.loadAssignment('a1')
	await vm.loadRubric('r1')
	assert.deepEqual(vm.submission, { id: 's1' })
	assert.deepEqual(vm.assignment, { id: 'a1' })
	assert.equal(vm.rubric, null)
	assert.deepEqual(calls.map((c) => [c.url, c.method, c.headers]), [
		['/nc/apps/openregister/api/objects/learniq/Submission/s1', undefined, READ],
		['/nc/apps/openregister/api/objects/learniq/Assignment/a1', undefined, READ],
		['/nc/apps/openregister/api/objects/learniq/Rubric/r1', undefined, READ],
	])
	answers = [when('Rubric/r2', 200, { object: { id: 'r2' } }), when('Submission', 404, null), when('Assignment', 403, null)]
	await vm.loadRubric('r2')
	assert.deepEqual(vm.rubric, { id: 'r2' })
	await assert.rejects(() => vm.loadSubmission('x'), { message: 'Submission fetch failed: 404' })
	await assert.rejects(() => vm.loadAssignment('x'), { message: 'Assignment fetch failed: 403' })
})

test('handing in an own mark patches it, submits it with the grade and reloads the marks', async () => {
	const view = await loadSfc('src/views/MarkSubmissionView.vue', STUBS)
	const reloaded = []
	const vm = instanceOf(view, { assignmentId: 'a1', id: 's1', loadMarks: async (id) => reloaded.push(id) })
	vm.marksView = { ownMark: { '@self': { id: 'm1' } } }
	vm.rubric = { id: 'r1' }
	vm.selectedLevels = LEVELS
	vm.feedbackText = 'Good'
	await vm.saveOwnMark()
	assert.deepEqual(calls, [
		{ url: '/nc/apps/openregister/api/objects/learniq/submission-mark/m1', method: 'PATCH', headers: WRITE, body: { rubricScores: [{ criterionId: 'c1', levelId: 'l1', points: 3 }, { criterionId: 'c2', levelId: 'l2', points: 0 }], proposedGrade: 3, feedbackText: 'Good' } },
		{ url: `/nc${transitionUrl('m1')}`, method: 'POST', headers: WRITE, body: { action: 'submit', data: { proposedGrade: 3 } } },
	])
	assert.equal(vm.markHandedIn, true)
	assert.deepEqual(reloaded, ['s1'])
	assert.equal(vm.saving, false)

	calls = []
	vm.rubric = null
	vm.manualGrade = 6.5
	vm.markHandedIn = false
	vm.marksView = { ownMark: { id: 'm2' } }
	answers = [when('transition', 409, {})]
	await vm.saveOwnMark()
	assert.deepEqual(calls[0].body, { rubricScores: [], proposedGrade: 6.5, feedbackText: 'Good' })
	assert.equal(vm.markHandedIn, false)
	assert.equal(vm.saveError, 'Failed to hand in your mark. Please try again.')
	assert.equal(errors[0][0], '[MarkSubmissionView] saveOwnMark error')
	assert.equal(errors[0][1].message, 'Mark submit failed: 409')

	calls = []
	vm.marksView = { ownMark: { id: 'm3' } }
	vm.manualGrade = undefined
	answers = [when('submission-mark', 500, {})]
	await vm.saveOwnMark()
	assert.equal(calls.length, 1)
	assert.equal(calls[0].body.proposedGrade, null)
	assert.equal(errors[1][1].message, 'Mark update failed: 500')

	calls = []
	vm.marksView = null
	await vm.saveOwnMark()
	assert.deepEqual(calls, [])
})

/**
 * A marking screen with a submission, an assignment on a plan, and a rubric.
 *
 * @param {object} [extra] Fields to set.
 * @return {Promise<object>} The instance.
 */
async function markingScreen(extra = {}) {
	const view = await loadSfc('src/views/MarkSubmissionView.vue', STUBS)
	const fetched = []
	const vm = instanceOf(view, {
		assignmentId: 'a1',
		id: 's1',
		fetchLearniqObject: async (schema, objectId) => {
			fetched.push([schema, objectId])
			return schema === 'course' ? { curriculumPlanId: 'p1' } : { gradeScaleId: 'g1' }
		},
		...extra,
	})
	vm.fetched = fetched
	vm.submission = { id: 's1', learnerIds: ['pupil'], tenant_id: 't1' }
	vm.assignment = { id: 'a1', courseId: 'k1', curriculumPlanComponentId: 'pc1' }
	vm.rubric = { id: 'r1' }
	vm.selectedLevels = LEVELS
	vm.feedbackText = 'Fine'
	return vm
}

test('returning a marked submission saves it, files a concept grade, links it and returns it', async () => {
	const vm = await markingScreen()
	answers = [when('grade-entry', 201, { object: { id: 'ge1' } })]
	await vm.saveAndReturn()
	assert.deepEqual(vm.fetched, [['course', 'k1'], ['curriculum-plan', 'p1']])
	assert.deepEqual(calls.map((c) => [c.url, c.method]), [
		['/nc/apps/openregister/api/objects/learniq/Submission/s1', 'PATCH'],
		['/nc/apps/openregister/api/objects/learniq/grade-entry', 'POST'],
		['/nc/apps/openregister/api/objects/learniq/Submission/s1', 'PATCH'],
		[`/nc${transitionUrl('s1')}`, 'POST'],
	])
	assert.ok(calls.every((c) => JSON.stringify(c.headers) === JSON.stringify(WRITE)))
	assert.deepEqual(calls[0].body, { rubricScores: [{ criterionId: 'c1', levelId: 'l1', points: 3 }, { criterionId: 'c2', levelId: 'l2', points: 0 }], proposedGrade: 3, feedbackText: 'Fine' })
	assert.deepEqual(calls[1].body, {
		learnerId: 'pupil',
		curriculumPlanId: 'p1',
		componentId: 'pc1',
		courseId: 'k1',
		sourceKind: 'assignment-submission',
		submissionId: 's1',
		value: 3,
		gradeScaleId: 'g1',
		grader: '',
		gradedAt: '2026-03-04T10:00:00.000Z',
		lifecycle: 'concept',
		tenant_id: 't1',
	})
	assert.deepEqual(calls[2].body, { gradeEntryId: 'ge1' })
	assert.deepEqual(calls[3].body, { action: 'return' })
	assert.equal(vm.savedGrade, 3)
	assert.equal(vm.returned, true)
	assert.equal(vm.saving, false)
	assert.equal(vm.saveError, null)
})

test('a final grade is saved without rubric scores and with who set it and by which rule', async () => {
	const vm = await markingScreen({ mode: 'final', finalGradeRule: 'average' })
	vm.finalGrade = 7
	answers = [when('grade-entry', 201, { id: null })]
	await vm.saveAndReturn()
	assert.deepEqual(calls[0].body, { rubricScores: [], proposedGrade: 7, feedbackText: 'Fine', finalGradeSetBy: 'u1', finalGradeRuleApplied: 'average' })
	assert.deepEqual(calls.map((c) => c.method), ['PATCH', 'POST', 'POST'])
	assert.equal(calls[1].body.value, 7)
	assert.equal(vm.savedGrade, 7)

	const unset = await markingScreen({ mode: 'final', finalGradeRule: null })
	calls = []
	await unset.saveAndReturn()
	assert.equal(calls[0].body.proposedGrade, null)
	assert.deepEqual(unset.fetched, [])
	assert.deepEqual(calls.map((c) => c.method), ['PATCH', 'POST'])
	assert.equal(unset.returned, true)
	assert.equal(unset.savedGrade, null)
})

test('a grade entry that fails, or an assignment off the plan, does not stop the return', async () => {
	const failing = await markingScreen()
	failing.submission = { id: 's1' }
	answers = [when('grade-entry', 500, null)]
	await failing.saveAndReturn()
	assert.deepEqual(calls.map((c) => c.method), ['PATCH', 'POST', 'POST'])
	assert.equal(calls[1].body.learnerId, '')
	assert.equal(calls[1].body.tenant_id, '')
	assert.equal(failing.returned, true)

	const offPlan = await markingScreen()
	offPlan.assignment = { id: 'a1' }
	offPlan.rubric = null
	offPlan.manualGrade = 5
	calls = []
	await offPlan.saveAndReturn()
	assert.deepEqual(calls[0].body.rubricScores, [])
	assert.equal(calls[0].body.proposedGrade, 5)
	assert.deepEqual(calls.map((c) => c.method), ['PATCH', 'POST'])
})

test('a failed save or return is reported, and a screen without a submission sends nothing', async () => {
	const saveFails = await markingScreen()
	answers = [when('Submission/s1', 500, null, 'PATCH')]
	await saveFails.saveAndReturn()
	assert.equal(calls.length, 1)
	assert.equal(saveFails.saveError, 'Failed to save marking. Please try again.')
	assert.equal(errors[0][0], '[MarkSubmissionView] saveAndReturn error')
	assert.equal(errors[0][1].message, 'Submission update failed: 500')
	assert.equal(saveFails.saving, false)

	const returnFails = await markingScreen()
	calls = []
	answers = [when('grade-entry', 201, { object: {} }), when('transition', 400, null)]
	await returnFails.saveAndReturn()
	assert.deepEqual(calls.map((c) => c.method), ['PATCH', 'POST', 'POST'])
	assert.equal(errors[1][1].message, 'Return transition failed: 400')
	assert.equal(returnFails.returned, false)

	const empty = await markingScreen()
	empty.submission = null
	calls = []
	await empty.saveAndReturn()
	assert.deepEqual(calls, [])
	assert.equal(empty.saving, false)
})

test('a self-assessment is created, or updated, and then submitted', async () => {
	const view = await loadSfc('src/views/SelfAssessmentView.vue', STUBS)
	const vm = instanceOf(view, { assignmentId: 'a1', id: 's1', timing: 'before' })
	vm.submission = { id: 's1', tenant_id: 't1' }
	vm.assignment = { id: 'a9' }
	vm.selectedLevels = LEVELS
	vm.comments = 'Mine'
	answers = [when('self-assessment', 201, { object: { id: 'sa1' } }, 'POST')]
	await vm.saveAndSubmit()
	assert.deepEqual(calls[0], {
		url: '/nc/apps/openregister/api/objects/learniq/self-assessment',
		method: 'POST',
		headers: WRITE,
		body: { assignmentId: 'a9', submissionId: 's1', learnerId: 'u1', timing: 'before', rubricScores: [{ criterionId: 'c1', levelId: 'l1', points: 3 }, { criterionId: 'c2', levelId: 'l2', points: 0 }], totalScore: 3, comments: 'Mine', tenant_id: 't1' },
	})
	assert.equal(calls[1].url, `/nc${transitionUrl('sa1')}`)
	assert.deepEqual([calls[1].method, calls[1].headers, calls[1].body], ['POST', WRITE, { action: 'submit' }])
	assert.equal(vm.submitted, true)

	calls = []
	vm.selfAssessment = { id: 'sa2' }
	vm.submission = { id: 's1' }
	vm.assignment = {}
	answers = [when('self-assessment/sa2', 200, {})]
	await vm.saveAndSubmit()
	assert.equal(calls[0].url, '/nc/apps/openregister/api/objects/learniq/self-assessment/sa2')
	assert.equal(calls[0].method, 'PUT')
	assert.equal(calls[0].body.id, 'sa2')
	assert.equal(calls[0].body.assignmentId, 'a1')
	assert.equal(calls[0].body.tenant_id, '')
	assert.equal(calls[1].url, `/nc${transitionUrl('sa2')}`)
})

test('a self-assessment that is not saved, has no id or is not submitted is reported', async () => {
	const view = await loadSfc('src/views/SelfAssessmentView.vue', STUBS)
	const vm = instanceOf(view, { assignmentId: 'a1', id: 's1', timing: 'after' })
	vm.submission = { id: 's1' }
	for (const [answer, message] of [
		[when('self-assessment', 500, null), 'SelfAssessment save failed: 500'],
		[when('self-assessment', 201, {}), 'SelfAssessment save did not return an id'],
	]) {
		answers = [answer]
		await vm.saveAndSubmit()
		assert.equal(errors.at(-1)[1].message, message)
	}
	answers = [when('self-assessment', 201, { id: 'sa3' }, 'POST'), when('transition', 409, null)]
	calls = []
	await vm.saveAndSubmit()
	assert.equal(errors.at(-1)[0], '[SelfAssessmentView] saveAndSubmit error')
	assert.equal(errors.at(-1)[1].message, 'Submit transition failed: 409')
	assert.equal(vm.saveError, 'Failed to save self-assessment. Please try again.')
	assert.equal(vm.submitted, false)
	assert.equal(vm.saving, false)
	vm.submission = null
	calls = []
	await vm.saveAndSubmit()
	assert.deepEqual(calls, [])
})

test('the self-assessment screen reads its objects and the learner\'s own draft', async () => {
	const view = await loadSfc('src/views/SelfAssessmentView.vue', STUBS)
	const vm = instanceOf(view, { assignmentId: 'a1', id: 's1' })
	answers = [when('Submission/s1', 200, { object: { id: 's1' } }), when('Assignment/a1', 200, { id: 'a1' }), when('Rubric/r1', 404, null), when('self-assessment?', 200, { results: [{ id: 'sa' }] })]
	await vm.loadSubmission('s1')
	await vm.loadAssignment('a1')
	await vm.loadRubric('r1')
	await vm.loadExistingSelfAssessment('s1')
	assert.deepEqual([vm.submission, vm.assignment, vm.rubric, vm.selfAssessment], [{ id: 's1' }, { id: 'a1' }, null, { id: 'sa' }])
	assert.equal(calls[3].url, '/nc/apps/openregister/api/objects/learniq/self-assessment?submissionId=s1&learnerId=u1&_limit=1')
	assert.ok(calls.every((c) => JSON.stringify(c.headers) === JSON.stringify(READ)))
	answers = [when('Submission', 500, null), when('Assignment', 401, null)]
	await assert.rejects(() => vm.loadSubmission('x'), { message: 'Submission fetch failed: 500' })
	await assert.rejects(() => vm.loadAssignment('x'), { message: 'Assignment fetch failed: 401' })
})

test('a peer review is patched with its scores and then submitted', async () => {
	const view = await loadSfc('src/views/PeerReviewMarkingView.vue', STUBS)
	const vm = instanceOf(view, { id: 'pr1' })
	vm.peerReview = { id: 'pr1' }
	vm.selectedLevels = LEVELS
	vm.comments = 'Nice'
	await vm.saveAndSubmit()
	assert.deepEqual(calls[0], {
		url: '/nc/apps/openregister/api/objects/learniq/peer-review/pr1',
		method: 'PATCH',
		headers: WRITE,
		body: { rubricScores: [{ criterionId: 'c1', levelId: 'l1', points: 3 }, { criterionId: 'c2', levelId: 'l2', points: 0 }], totalScore: 3, comments: 'Nice' },
	})
	assert.equal(calls[1].url, `/nc${transitionUrl('pr1')}`)
	assert.deepEqual([calls[1].method, calls[1].headers, calls[1].body], ['POST', WRITE, { action: 'submit' }])
	assert.equal(vm.submitted, true)

	for (const [answer, message] of [[when('peer-review', 500, null), 'PeerReview update failed: 500'], [when('transition', 422, null), 'Submit transition failed: 422']]) {
		answers = [answer]
		vm.submitted = false
		await vm.saveAndSubmit()
		assert.equal(errors.at(-1)[0], '[PeerReviewMarkingView] saveAndSubmit error')
		assert.equal(errors.at(-1)[1].message, message)
		assert.equal(vm.saveError, 'Failed to save peer review. Please try again.')
		assert.equal(vm.submitted, false)
	}
	vm.peerReview = null
	calls = []
	await vm.saveAndSubmit()
	assert.deepEqual(calls, [])
})

test('the peer review screen reads its objects', async () => {
	const view = await loadSfc('src/views/PeerReviewMarkingView.vue', STUBS)
	const vm = instanceOf(view, { id: 'pr1' })
	answers = [when('peer-review/pr1', 200, { object: { id: 'pr1' } }), when('Assignment/a1', 200, { id: 'a1' }), when('Rubric/r1', 200, { object: { id: 'r1' } })]
	await vm.loadPeerReview('pr1')
	await vm.loadAssignment('a1')
	await vm.loadRubric('r1')
	assert.deepEqual([vm.peerReview, vm.assignment, vm.rubric], [{ id: 'pr1' }, { id: 'a1' }, { id: 'r1' }])
	assert.ok(calls.every((c) => JSON.stringify(c.headers) === JSON.stringify(READ)))
	answers = [when('peer-review', 404, null), when('Assignment', 500, null), when('Rubric', 500, null)]
	await assert.rejects(() => vm.loadPeerReview('x'), { message: 'PeerReview fetch failed: 404' })
	await assert.rejects(() => vm.loadAssignment('x'), { message: 'Assignment fetch failed: 500' })
	await vm.loadRubric('x')
	assert.deepEqual(vm.rubric, { id: 'r1' })
})
