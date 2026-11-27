// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// A media block of the lesson composer takes a new file from the designer's
// Nextcloud files: the picked file becomes a Material of the lesson (its
// name as title, a kind that fits its type, its path as fileRef) and the
// block points at it. Closing the picker creates nothing and shows nothing.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
//
// @spec openspec/changes/capability-rows-say-what-they-do/specs/course-management/spec.md#requirement-a-designer-attaches-a-new-file-to-a-lesson-as-a-material

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

/**
 * The picker's next answer: a node, or an error to throw.
 */
let picked = null

/**
 * The closed picker's error, named as @nextcloud/dialogs names it.
 */
class FilePickerClosed extends Error {}

const builder = {
	setMultiSelect: () => builder,
	build: () => ({
		pickNodes: async () => {
			if (picked instanceof Error) throw picked
			return [picked]
		},
	}),
}

// data() reads which apps are installed from the page's OC global.
globalThis.window ??= { OC: { appswebroots: {} } }

const component = await loadSfc('src/views/LessonComposer.vue', {
	'@nextcloud/dialogs': { getFilePickerBuilder: () => builder },
	'@nextcloud/router': { generateUrl: (path) => `/nc${path}` },
})

/**
 * The composer on a lesson, with its Material writes recorded.
 *
 * @return {{vm: object, created: Array<object>, updates: Array<object>}} The instance and what it wrote.
 */
function composer() {
	const created = []
	const vm = instanceOf(component, {
		$route: { params: { id: 'lesson-7' } },
		createObject: async (schema, body) => {
			created.push({ schema, body })
			return { id: 'material-1', ...body }
		},
	})
	vm.lessonId = 'lesson-7'
	vm.lesson = { id: 'lesson-7', tenant_id: 'school-a' }
	vm.materials = []
	return { vm, created, updates: [] }
}

test('Scenario "A designer adds a video to a lesson": the picked file becomes the lesson\'s Material and the block points at it', async () => {
	const { vm, created, updates } = composer()
	picked = { basename: 'intro.mp4', path: '/Lessen/intro.mp4', mime: 'video/mp4' }

	await vm.pickAndCreateMaterial((patch) => updates.push(patch))

	assert.deepEqual(created, [{
		schema: 'Material',
		body: { title: 'intro.mp4', kind: 'video', fileRef: '/Lessen/intro.mp4', lessonId: 'lesson-7', tenant_id: 'school-a' },
	}])
	assert.deepEqual(updates, [{ materialId: 'material-1' }], 'the media block now points at that Material')
	assert.equal(vm.materials.length, 1, 'and the block\'s choice list offers it')
	assert.equal(vm.pickingFile, false)
})

test('Scenario "The designer closes the picker": no Material is created and no error is shown', async () => {
	const { vm, created, updates } = composer()
	vm.error = ''
	picked = new FilePickerClosed('closed')

	await vm.pickAndCreateMaterial((patch) => updates.push(patch))

	assert.deepEqual(created, [])
	assert.deepEqual(updates, [])
	assert.equal(vm.error, '', 'no error is shown')
	assert.equal(vm.pickingFile, false)
})
