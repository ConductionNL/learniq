// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The onboarding panel's import of one confirmed file, pinned through the
// component's own method with a stubbed axios: nothing is sent without a
// course, a draft is reported and its row removed, and a refusal is shown on
// the row.
//
// @spec openspec/specs/course-management/spec.md#requirement-nothing-is-extracted-until-the-teacher-confirms-one-file-on-the-review-page

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const http = { calls: [], answer: null }
const component = await loadSfc('src/components/lesson/LessonOnboardingPanel.vue', {
	'@nextcloud/axios': {
		default: {
			post: async (...args) => {
				http.calls.push(args)
				if (http.answer instanceof Error) throw http.answer
				return http.answer
			},
		},
	},
	'@nextcloud/router': { generateUrl: (path) => `/nc${path}` },
})

/**
 * A panel with two detected rows.
 *
 * @param {object} state Data to set.
 * @return {object}
 */
function panel(state = {}) {
	http.calls = []
	const vm = instanceOf(component)
	return Object.assign(vm, { rows: [{ id: 'f1', fileName: 'Les 1.docx' }, { id: 'f2', fileName: 'Les 2.pptx' }], ...state })
}

test('without a chosen course nothing is sent and the row says so', async () => {
	const vm = panel({ rowErrors: { f1: 'old', f2: 'kept' } })
	await vm.importRow({ id: 'f1' })
	assert.deepEqual(http.calls, [])
	assert.deepEqual(vm.rowErrors, { f1: vm.errorMessage('no-course'), f2: 'kept' })
	assert.equal(vm.busyRow, '')
})

test('an import posts the course, reports the draft and removes the row', async () => {
	const vm = panel({ courseChoice: { f1: 'k1' }, rowErrors: { f1: 'old' }, results: [{ lessonName: 'Earlier' }] })
	http.answer = { data: { lessonName: 'Fotosynthese', lessonId: 'l1' } }
	await vm.importRow({ id: 'f1' })
	assert.deepEqual(http.calls, [['/nc/apps/learniq/api/lesson-onboarding/files/f1/import', { courseId: 'k1' }]])
	assert.deepEqual(vm.results, [{ lessonName: 'Fotosynthese', lessonId: 'l1', notes: [] }, { lessonName: 'Earlier' }])
	assert.deepEqual(vm.rows.map((r) => r.id), ['f2'])
	assert.equal(vm.liveMessage, 'Lesson draft "Fotosynthese" created.')
	assert.deepEqual(vm.rowErrors, { f1: '' })
	assert.equal(vm.busyRow, '')
	http.answer = { data: { lessonName: 'Twee', notes: ['Images left out'] } }
	vm.courseChoice.f2 = 'k2'
	await vm.importRow({ id: 'f2' })
	assert.deepEqual(vm.results[0].notes, ['Images left out'])
})

test('a refused import keeps the row and names the reason', async () => {
	for (const [error, key] of [
		[Object.assign(new Error('410'), { response: { status: 410, data: {} } }), 'file-gone'],
		[Object.assign(new Error('422'), { response: { status: 422, data: { reason: 'course-not-found' } } }), 'course-not-found'],
		[new Error('offline'), 'failed'],
	]) {
		const vm = panel({ courseChoice: { f1: 'k1' } })
		http.answer = error
		await vm.importRow({ id: 'f1' })
		assert.deepEqual(vm.rows.map((r) => r.id), ['f1', 'f2'])
		assert.deepEqual(vm.rowErrors, { f1: vm.errorMessage(key) })
		assert.deepEqual(vm.results, [])
		assert.equal(vm.busyRow, '')
	}
})
