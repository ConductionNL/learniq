import assert from 'node:assert/strict'
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-lessons-within-a-course-and-blocks-within-a-lesson-are-reorderable-by-drag-and-drop-and-by-keyboard
 */
import { test } from 'node:test'
import { moduleHoldingLesson, schemaOfMove } from '../../src/utils/courseTree.js'

test('a move at the root saves chapter order', () => {
	assert.equal(schemaOfMove(null), 'Course')
})

test('a move under a chapter saves lesson order', () => {
	assert.equal(schemaOfMove('chapter-1'), 'Lesson')
})

const modules = [
	{ id: 'm1', lessons: [{ id: 'l1' }] },
	{ id: 'm2', lessons: [{ id: 'l2' }, { id: 'l3' }] },
]

test('the chapter holding a lesson is found by the lesson id', () => {
	assert.equal(moduleHoldingLesson(modules, 'l3').id, 'm2')
})

test('a lesson no chapter holds gives null', () => {
	assert.equal(moduleHoldingLesson(modules, 'gone'), null)
})
