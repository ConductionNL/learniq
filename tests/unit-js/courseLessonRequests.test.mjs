// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The object requests the course builder and the lesson composer send,
// pinned call by call: the list read (a refusal is an empty list), the
// object create (a refusal throws with the schema and status) and the
// lesson composer's single-object read.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
//
// @spec openspec/specs/course-management/spec.md#requirement-course-module-lesson-hierarchy-in-openregister

import assert from 'node:assert/strict'
import { afterEach, beforeEach, test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const STUBS = { '@nextcloud/router': { generateUrl: (url) => `/nc${url}` } }
const READ = { 'OCS-APIREQUEST': 'true', Accept: 'application/json' }
const WRITE = { 'OCS-APIREQUEST': 'true', Accept: 'application/json', 'Content-Type': 'application/json' }

let calls
let answer

beforeEach(() => {
	calls = []
	answer = { status: 200, json: {} }
	globalThis.window = { OC: {} }
	globalThis.fetch = async (url, init) => {
		calls.push({ url, init })
		return { ok: answer.status < 300, status: answer.status, json: async () => answer.json }
	}
})

afterEach(() => {
	delete globalThis.fetch
	delete globalThis.window
})

const SCREENS = [
	{ file: 'src/views/CourseBuilder.vue', props: { courseId: 'c1' } },
	{ file: 'src/views/LessonComposer.vue', props: { courseId: 'c1', lessonId: 'l1' } },
]

for (const { file, props } of SCREENS) {
	test(`${file}: fetchList reads the rows, and a refusal is an empty list`, async () => {
		const vm = instanceOf(await loadSfc(file, STUBS), props)
		for (const [json, expected] of [
			[{ results: [{ id: 'r' }] }, [{ id: 'r' }]],
			[{ objects: [{ id: 'o' }] }, [{ id: 'o' }]],
			[[{ id: 'a' }], [{ id: 'a' }]],
			[{}, []],
		]) {
			answer = { status: 200, json }
			assert.deepEqual(await vm.fetchList('lesson', 'courseId=c1&_limit=5'), expected)
		}
		answer = { status: 404, json: null }
		assert.deepEqual(await vm.fetchList('lesson', 'x=1'), [])
		assert.deepEqual(calls[0], {
			url: '/nc/apps/openregister/api/objects/learniq/lesson?courseId=c1&_limit=5',
			init: { headers: READ },
		})
		assert.equal(calls.length, 5)
	})

	test(`${file}: createObject posts the body and returns the created object`, async () => {
		const vm = instanceOf(await loadSfc(file, STUBS), props)
		answer = { status: 201, json: { object: { id: 'n1' } } }
		assert.deepEqual(await vm.createObject('lesson', { name: 'A' }), { id: 'n1' })
		assert.deepEqual(calls[0], {
			url: '/nc/apps/openregister/api/objects/learniq/lesson',
			init: { method: 'POST', headers: WRITE, body: JSON.stringify({ name: 'A' }) },
		})
		answer = { status: 200, json: { id: 'bare' } }
		assert.deepEqual(await vm.createObject('lesson', {}), { id: 'bare' })
		answer = { status: 200, json: null }
		await assert.rejects(() => vm.createObject('lesson', {}))
		answer = { status: 422, json: null }
		await assert.rejects(() => vm.createObject('course', {}), { message: 'course create failed: 422' })
	})
}

test('LessonComposer fetchObject reads one object and throws on a refusal', async () => {
	const vm = instanceOf(await loadSfc('src/views/LessonComposer.vue', STUBS), { courseId: 'c1', lessonId: 'l1' })
	answer = { status: 200, json: { object: { id: 'l1' } } }
	assert.deepEqual(await vm.fetchObject('Lesson', 'l1'), { id: 'l1' })
	assert.deepEqual(calls[0], { url: '/nc/apps/openregister/api/objects/learniq/Lesson/l1', init: { headers: READ } })
	answer = { status: 200, json: { id: 'bare' } }
	assert.deepEqual(await vm.fetchObject('Lesson', 'l1'), { id: 'bare' })
	answer = { status: 403, json: null }
	await assert.rejects(() => vm.fetchObject('Lesson', 'l1'), { message: 'Lesson fetch failed: 403' })
})
