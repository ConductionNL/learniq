// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The lesson player's completion check, its manual completion and the block
// reference reads, pinned through the component's own methods with a stubbed
// fetch: what is read and sent, in which order, and what the screen keeps
// when a read or write fails.
//
// @spec openspec/specs/progress-tracking/spec.md#scenario-learner-marks-a-text-lesson-complete

import assert from 'node:assert/strict'
import { afterEach, beforeEach, test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

let user
const STUBS = {
	'@nextcloud/auth': { getCurrentUser: () => user },
	'@nextcloud/router': { generateUrl: (url) => `/nc${url}` },
	'../services/openMaterial.js': { OPEN_MATERIAL_API: '/open-material' },
}
const READ = { 'OCS-APIREQUEST': 'true', Accept: 'application/json' }
const COMPLETIONS = '/nc/apps/openregister/api/objects/learniq/lesson-completion'

let calls
let answers

beforeEach(() => {
	user = { uid: 'sem' }
	calls = []
	answers = []
	globalThis.fetch = async (url, init) => {
		calls.push({ url, init })
		const answer = answers.find((a) => url.includes(a.part)) ?? { status: 200, json: {} }
		if (answer.json instanceof Error && answer.throws) throw answer.json
		return {
			ok: answer.status >= 200 && answer.status < 300,
			status: answer.status,
			json: async () => {
				if (answer.json instanceof Error) throw answer.json
				return answer.json
			},
		}
	}
})

afterEach(() => {
	delete globalThis.fetch
})

/**
 * A player for lesson l1 of course c1.
 *
 * @param {object} extra Fields to set.
 * @return {Promise<object>} The player.
 */
async function player(extra = {}) {
	return instanceOf(await loadSfc('src/views/LessonPlayer.vue', STUBS), {
		courseId: 'c1',
		lessonId: 'l1',
		preview: { active: false, score: null },
		...extra,
	})
}

test('without a signed-in user the check reads nothing and is done', async () => {
	user = null
	const vm = await player()
	await vm.checkExistingManualCompletion()
	assert.deepEqual(calls, [])
	assert.deepEqual(vm.manualCompletion, { checked: true, completed: false, saving: false, error: '' })
})

test('the check reads the enrolment, then this learner\'s completions of this lesson', async () => {
	const vm = await player()
	answers = [
		{ part: 'objects/learniq/enrolment', status: 200, json: { results: [{ id: 'e2', lifecycle: 'active', created: '2026-09-01' }] } },
		{ part: 'lesson-completion', status: 200, json: { results: [{ learnerId: 'sem', lessonId: 'l1', enrolmentId: 'e2' }] } },
	]
	await vm.checkExistingManualCompletion()
	assert.deepEqual(calls.map((c) => c.url), [
		'/nc/apps/openregister/api/objects/learniq/enrolment?learnerId=sem&courseId=c1&_limit=50',
		`${COMPLETIONS}?learnerId=sem&lessonId=l1&_limit=100`,
	])
	assert.deepEqual(calls[1].init, { headers: READ })
	assert.equal(vm.currentEnrolment.id, 'e2')
	assert.equal(vm.manualCompletion.completed, true)
	assert.equal(vm.manualCompletion.checked, true)
})

test('a completion of another learner, lesson or enrolment does not count; the list may be bare', async () => {
	for (const json of [
		{ results: [{ learnerId: 'eva', lessonId: 'l1' }] },
		{ objects: [{ learnerId: 'sem', lessonId: 'l2' }] },
		[{ learnerId: 'sem', lessonId: 'l1', enrolmentId: 'old' }],
	]) {
		const vm = await player({ courseId: '' })
		answers = [{ part: 'lesson-completion', status: 200, json }]
		await vm.checkExistingManualCompletion()
		assert.equal(calls.length, 1)
		assert.equal(vm.manualCompletion.completed, false)
		assert.equal(vm.manualCompletion.checked, true)
		calls = []
	}
	const vm = await player({ courseId: '' })
	answers = [{ part: 'lesson-completion', status: 200, json: [{ learnerId: 'sem', lessonId: 'l1' }] }]
	await vm.checkExistingManualCompletion()
	assert.equal(vm.manualCompletion.completed, true)
})

test('a refused or failing check leaves the lesson open and still marks it checked', async () => {
	for (const answer of [{ status: 403, json: {} }, { status: 200, json: new Error('not json') }]) {
		const vm = await player({ courseId: '' })
		vm.manualCompletion.completed = 'unchanged'
		answers = [{ part: 'lesson-completion', ...answer }]
		await vm.checkExistingManualCompletion()
		assert.equal(vm.manualCompletion.completed, 'unchanged')
		assert.equal(vm.manualCompletion.checked, true)
	}
})

test('a manual completion posts once, with the preview headers, the current enrolment and the tenant', async () => {
	const vm = await player({
		currentEnrolmentId: 'e2',
		lesson: { tenant_id: '' },
		course: { tenant_id: 't-course' },
	})
	answers = [{ part: 'lesson-completion', status: 201, json: {} }]
	const before = Date.now()
	await vm.markLessonComplete()
	assert.equal(calls.length, 1)
	assert.equal(calls[0].url, COMPLETIONS)
	assert.equal(calls[0].init.method, 'POST')
	assert.deepEqual(calls[0].init.headers, { ...READ, 'Content-Type': 'application/json' })
	const body = JSON.parse(calls[0].init.body)
	assert.ok(Date.parse(body.completedAt) >= before - 1000)
	assert.deepEqual({ ...body, completedAt: 'at' }, {
		learnerId: 'sem',
		lessonId: 'l1',
		courseId: 'c1',
		enrolmentId: 'e2',
		source: 'manual',
		completedAt: 'at',
		tenant_id: '',
	})
	assert.deepEqual(Object.keys(body), ['learnerId', 'lessonId', 'courseId', 'enrolmentId', 'source', 'completedAt', 'tenant_id'])
	assert.deepEqual(vm.manualCompletion, { checked: false, completed: true, saving: false, error: '' })
	await vm.markLessonComplete()
	assert.equal(calls.length, 1)
})

test('a completion while saving, in a preview, or refused behaves as before', async () => {
	const saving = await player()
	saving.manualCompletion.saving = true
	await saving.markLessonComplete()
	assert.deepEqual(calls, [])

	const preview = await player({ preview: { active: true, score: null } })
	await preview.markLessonComplete()
	assert.deepEqual(calls, [])
	assert.equal(preview.manualCompletion.completed, true)
	assert.equal(preview.manualCompletion.saving, false)

	user = null
	const refused = await player({ currentEnrolmentId: null })
	refused.manualCompletion.error = 'old'
	answers = [{ part: 'lesson-completion', status: 423, json: {} }]
	await refused.markLessonComplete()
	assert.equal(JSON.parse(calls[0].init.body).learnerId, '')
	assert.equal(JSON.parse(calls[0].init.body).tenant_id, '')
	assert.deepEqual(refused.manualCompletion, {
		checked: false,
		completed: false,
		saving: false,
		error: 'Failed to mark lesson complete (HTTP 423)',
	})

	calls = []
	const offline = await player()
	answers = [{ part: 'lesson-completion', status: 0, json: new Error('offline'), throws: true }]
	await offline.markLessonComplete()
	assert.equal(offline.manualCompletion.error, 'offline')
	assert.equal(offline.manualCompletion.saving, false)
})

test('block references are read once per id, materials then assessments then assignments', async () => {
	const vm = await player({
		sortedBlocks: [
			{ type: 'quiz', assessmentId: 'q1' },
			{ type: 'media', materialId: 'm1' },
			{ type: 'media', materialId: 'm1' },
			{ type: 'media' },
			{ type: 'assignment', assignmentId: 'a1' },
			{ type: 'quiz', assessmentId: 'q2' },
			{ type: 'richText', materialId: 'm9' },
		],
	})
	answers = [
		{ part: 'Material/m1', status: 200, json: { object: { id: 'm1', title: 'Video' } } },
		{ part: 'exam/q1', status: 200, json: { id: 'q1' } },
		{ part: 'exam/q2', status: 404, json: {} },
		{ part: 'Assignment/a1', status: 200, json: new Error('not json') },
	]
	await vm.loadBlockReferences()
	assert.deepEqual(calls.map((c) => c.url), [
		'/nc/apps/openregister/api/objects/learniq/Material/m1',
		'/nc/apps/openregister/api/objects/learniq/exam/q1',
		'/nc/apps/openregister/api/objects/learniq/exam/q2',
		'/nc/apps/openregister/api/objects/learniq/Assignment/a1',
	])
	assert.deepEqual(calls[0].init, { headers: READ })
	assert.deepEqual(vm.materialsById, { m1: { id: 'm1', title: 'Video' } })
	assert.deepEqual(vm.assessmentsById, { q1: { id: 'q1' } })
	assert.deepEqual(vm.assignmentsById, {})
})

test('a reference answer of null is dropped like a failed read', async () => {
	const vm = await player({ sortedBlocks: [{ type: 'media', materialId: 'm2' }] })
	answers = [{ part: 'Material/m2', status: 200, json: null }]
	await vm.loadBlockReferences()
	assert.deepEqual(vm.materialsById, {})
})
