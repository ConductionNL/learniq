// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The course preview's load, pinned through the component's own hook with a
// stubbed fetch: the lessons it shows, and what it says when the server
// refuses or fails.
//
// @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-preview-as-learner/spec.md#scenario-a-learner-cannot-preview

import assert from 'node:assert/strict'
import { afterEach, test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const component = await loadSfc('src/views/CoursePreviewView.vue', {
	'@nextcloud/router': { generateUrl: (path) => `/nc${path}` },
})
let calls = []

afterEach(() => {
	delete globalThis.fetch
})

/**
 * A preview whose fetch answers once.
 *
 * @param {object|Error} answer The response, or an error to throw.
 * @return {object}
 */
function preview(answer) {
	calls = []
	globalThis.fetch = async (url, init) => {
		calls.push([url, init])
		if (answer instanceof Error) throw answer
		return answer
	}
	return instanceOf(component, { courseId: 'c 1' })
}

/**
 * A response stand-in.
 *
 * @param {number} status HTTP status.
 * @param {*} body The JSON body.
 * @return {object}
 */
function answer(status, body) {
	return { ok: status < 400, status, json: async () => body }
}

test('the preview lists the course lessons', async () => {
	const vm = preview(answer(200, { name: 'Biologie', lessons: [{ id: 'l1' }] }))
	await component.mounted.call(vm)
	assert.deepEqual(calls, [['/nc/apps/learniq/api/courses/c%201/preview', { headers: { Accept: 'application/json' } }]])
	assert.deepEqual([vm.courseName, vm.lessons, vm.error, vm.loading], ['Biologie', [{ id: 'l1' }], '', false])
})

test('a course without a name or lesson list shows blanks', async () => {
	const vm = preview(answer(200, { lessons: 'none' }))
	await component.mounted.call(vm)
	assert.deepEqual([vm.courseName, vm.lessons, vm.error], ['', [], ''])
})

test('a refusal, a failure and a thrown read are named, and nothing is shown', async () => {
	for (const [response, error] of [
		[answer(403, {}), 'Only course authors can preview a course.'],
		[answer(500, {}), 'Failed to load course (HTTP 500)'],
		[new Error('offline'), 'offline'],
		[answer(200, Promise.reject(new Error('not json'))), 'not json'],
	]) {
		const vm = preview(response)
		await component.mounted.call(vm)
		assert.equal(vm.error, error)
		assert.deepEqual([vm.courseName, vm.lessons, vm.loading], ['', [], false])
	}
})
