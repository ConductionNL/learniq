// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The lesson player's start: the order in which it loads the lesson and
// opens what the lesson needs, and the three launches (an LTI block, an LTI
// lesson, a cmi5 package) with every answer they can get.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
//
// @spec openspec/specs/course-management/spec.md

import assert from 'node:assert/strict'
import { afterEach, beforeEach, test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const STUBS = {
	'@nextcloud/router': { generateUrl: (url) => `/nc${url}` },
	'../services/openMaterial.js': { OPEN_MATERIAL_API: '/open-material' },
}
const FORM = { formActionUrl: 'https://tool.example/launch', fields: { id_token: 'x' } }

let calls
let answers

beforeEach(() => {
	calls = []
	answers = []
	globalThis.window = { OC: { requestToken: 'rt' } }
	globalThis.fetch = async (url, init) => {
		calls.push({ url, method: init?.method, headers: init?.headers })
		const answer = answers.find((a) => url.includes(a.part)) ?? { status: 200, json: {} }
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
	delete globalThis.window
})

/**
 * A player whose start steps record that they ran.
 *
 * @param {object} flags The computed flags and state to start from.
 * @return {Promise<{vm: object, ran: string[]}>} The player and its log.
 */
async function player(flags) {
	const ran = []
	const step = (name) => async () => {
		ran.push(name)
	}
	const vm = instanceOf(await loadSfc('src/views/LessonPlayer.vue', STUBS), {
		courseId: 'c1',
		lessonId: 'l1',
		checkReleaseStatus: step('release'),
		loadNextStep: step('next'),
		checkExistingManualCompletion: step('manual'),
		launchLti: step('lti'),
		loadBlockReferences: step('refs'),
		initScorm12: () => ran.push('scorm'),
		launchCmi5: step('cmi5'),
		...flags,
	})
	return { vm, ran }
}

const ALL = { isLocked: false, showManualCompleteAction: true, isLtiLesson: true, isTextLesson: true, isScorm12Lesson: true, isCmi5Lesson: true }

test('a loaded lesson checks its release, then opens everything it needs, in order', async () => {
	answers = [{ part: 'Course/c1', status: 200, json: { id: 'c1' } }, { part: 'Lesson/l1', status: 200, json: { id: 'l1' } }]
	const { vm, ran } = await player({ ...ALL, preview: { active: false } })
	await vm.loadLesson()
	assert.deepEqual(calls.map((c) => c.url), ['/nc/apps/openregister/api/objects/learniq/Course/c1', '/nc/apps/openregister/api/objects/learniq/Lesson/l1'])
	assert.deepEqual([vm.course, vm.lesson, vm.loading], [{ id: 'c1' }, { id: 'l1' }, false])
	assert.deepEqual(ran, ['release', 'next', 'manual', 'lti', 'refs', 'scorm', 'cmi5'])
})

test('a preview skips the live steps, and a locked lesson stops after the release check unless previewed', async () => {
	answers = [{ part: 'Course', status: 200, json: {} }, { part: 'Lesson', status: 200, json: { id: 'l1' } }]
	const preview = await player({ ...ALL, preview: { active: true } })
	await preview.vm.loadLesson()
	assert.deepEqual(preview.ran, ['release', 'next', 'refs', 'scorm'])
	const locked = await player({ ...ALL, isLocked: true, preview: { active: false } })
	await locked.vm.loadLesson()
	assert.deepEqual(locked.ran, ['release'])
	const lockedPreview = await player({ ...ALL, isLocked: true, preview: { active: true } })
	await lockedPreview.vm.loadLesson()
	assert.deepEqual(lockedPreview.ran, ['release', 'next', 'refs', 'scorm'])
	const plain = await player({ isLocked: false, showManualCompleteAction: false, isLtiLesson: false, isTextLesson: false, isScorm12Lesson: false, isCmi5Lesson: false, preview: { active: false } })
	await plain.vm.loadLesson()
	assert.deepEqual(plain.ran, ['release', 'next'])
})

test('a course or lesson that does not load is said, and only the later steps that need no lesson run', async () => {
	answers = [{ part: 'Course', status: 404, json: {} }, { part: 'Lesson', status: 500, json: {} }]
	const failed = await player({ ...ALL, preview: { active: false } })
	await failed.vm.loadLesson()
	assert.equal(failed.vm.error, 'Failed to load course (HTTP 404)')
	assert.equal(failed.vm.loading, false)
	assert.deepEqual(failed.ran, ['manual', 'lti', 'refs', 'scorm', 'cmi5'])
	answers = [{ part: 'Course', status: 200, json: {} }, { part: 'Lesson', status: 403, json: {} }]
	const lessonFails = await player({ ...ALL, showManualCompleteAction: false, isLtiLesson: false, isCmi5Lesson: false, preview: { active: false } })
	await lessonFails.vm.loadLesson()
	assert.equal(lessonFails.vm.error, 'Failed to load lesson (HTTP 403)')
	assert.deepEqual(lessonFails.ran, ['refs', 'scorm'])
	globalThis.fetch = async () => {
		throw 'offline'
	}
	const offline = await player({ ...ALL, isTextLesson: false, isScorm12Lesson: false, showManualCompleteAction: false, isLtiLesson: false, isCmi5Lesson: false, preview: { active: false } })
	await offline.vm.loadLesson()
	assert.equal(offline.vm.error, 'offline')
	assert.deepEqual(offline.ran, [])
})

/**
 * A player with the real launch methods.
 *
 * @return {Promise<object>} The player.
 */
async function launcher() {
	const vm = instanceOf(await loadSfc('src/views/LessonPlayer.vue', STUBS), { courseId: 'c1', lessonId: 'l1' })
	vm.submitted = []
	vm.submitLtiLaunchForm = (form) => vm.submitted.push(form)
	vm.$nextTick = (fn) => fn()
	return vm
}

test('an LTI block launches its placement and posts the launch form', async () => {
	const vm = await launcher()
	answers = [{ part: 'lti-placements/p1/launch', status: 200, json: FORM }]
	await vm.launchLtiForBlock({ blockId: 'b1', ltiToolPlacementId: 'p1' })
	assert.equal(calls[0].url, '/nc/apps/learniq/api/lti-placements/p1/launch')
	assert.equal(calls[0].method, 'POST')
	assert.equal(calls[0].headers.requesttoken, 'rt')
	assert.deepEqual(vm.blockLtiState.b1, { launching: false, error: '' })
	assert.deepEqual(vm.submitted, [FORM])
	await vm.launchLtiForBlock({ blockId: 'b2' })
	assert.deepEqual(vm.blockLtiState.b2, { launching: false, error: 'This block has no LTI tool placement configured.' })
	for (const [answer, error] of [
		[{ status: 502, json: { error: 'Tool down' } }, 'Tool down'],
		[{ status: 500, json: new Error('not json') }, 'Failed to start the tool (HTTP 500)'],
		[{ status: 200, json: { nothing: true } }, 'Integriq returned an unexpected launch response.'],
	]) {
		answers = [{ part: 'launch', ...answer }]
		await vm.launchLtiForBlock({ blockId: 'b3', ltiToolPlacementId: 'p3' })
		assert.deepEqual(vm.blockLtiState.b3, { launching: false, error })
	}
	assert.equal(vm.submitted.length, 1)
})

test('an LTI lesson launches its placement and keeps the form', async () => {
	const vm = await launcher()
	vm.lesson = { contentRef: 'p9' }
	answers = [{ part: 'lti-placements/p9/launch', status: 200, json: FORM }]
	await vm.launchLti()
	assert.equal(calls[0].url, '/nc/apps/learniq/api/lti-placements/p9/launch')
	assert.deepEqual([vm.ltiLaunch, vm.ltiError, vm.ltiLaunching], [FORM, '', false])
	assert.deepEqual(vm.submitted, [FORM])
	for (const [answer, error] of [
		[{ status: 403, json: { error: 'No' } }, 'No'],
		[{ status: 400, json: {} }, 'Failed to start the tool (HTTP 400)'],
		[{ status: 200, json: 'html' }, 'Integriq returned an unexpected launch response.'],
	]) {
		answers = [{ part: 'launch', ...answer }]
		await vm.launchLti()
		assert.deepEqual([vm.ltiLaunch, vm.ltiError, vm.ltiLaunching], [null, error, false])
	}
	vm.lesson = { contentRef: '' }
	calls = []
	await vm.launchLti()
	assert.deepEqual(calls, [])
	assert.equal(vm.ltiError, 'This lesson has no LTI tool placement configured.')
})

test('a cmi5 package launches, is unavailable without a package or service, and reports a refusal', async () => {
	const vm = await launcher()
	vm.lesson = { contentRef: 'https://pkg.example/au.html' }
	answers = [{ part: 'lessons/l1/cmi5-launch', status: 200, json: { endpoint: 'https://lrs.example', fetchUrl: 'https://f', actor: { name: 'a' }, registration: 'r1', activityId: 'act' } }]
	await vm.launchCmi5()
	assert.equal(calls[0].url, '/nc/apps/learniq/api/lessons/l1/cmi5-launch')
	assert.equal(calls[0].headers.requesttoken, 'rt')
	assert.equal(typeof vm.cmi5.launchUrl, 'string')
	assert.ok(vm.cmi5.launchUrl.startsWith('https://pkg.example/au.html'))
	assert.equal(vm.cmi5.launching, false)
	for (const status of [404, 503]) {
		vm.cmi5.available = true
		answers = [{ part: 'cmi5-launch', status, json: {} }]
		await vm.launchCmi5()
		assert.equal(vm.cmi5.available, false)
		assert.equal(vm.cmi5.launching, false)
	}
	for (const [answer, error] of [[{ status: 500, json: { error: 'LRS down' } }, 'LRS down'], [{ status: 401, json: new Error('x') }, 'Failed to start the cmi5 package (HTTP 401)']]) {
		answers = [{ part: 'cmi5-launch', ...answer }]
		await vm.launchCmi5()
		assert.equal(vm.cmi5.error, error)
	}
	vm.lesson = {}
	vm.cmi5.available = true
	calls = []
	await vm.launchCmi5()
	assert.deepEqual(calls, [])
	assert.equal(vm.cmi5.available, false)
})
