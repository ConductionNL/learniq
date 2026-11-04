/**
 * Helpers for the CourseBuilder tree: chapters (child Courses) at the root,
 * their lessons one level down.
 *
 * Plain ES module so it is importable from the Node test runner.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-lessons-within-a-course-and-blocks-within-a-lesson-are-reorderable-by-drag-and-drop-and-by-keyboard
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/**
 * The OpenRegister schema a tree move saves: a root move reorders chapters,
 * any other move reorders one chapter's lessons.
 *
 * @param {string|number|null} parentId The moved row's parent, null at the root.
 * @return {string} 'Course' or 'Lesson'.
 */
export function schemaOfMove(parentId) {
	return parentId === null ? 'Course' : 'Lesson'
}

/**
 * The chapter that holds a lesson.
 *
 * @param {Array<{lessons: Array<{id: string}>}>} modules The chapters.
 * @param {string} lessonId The lesson's id.
 * @return {object|null} The chapter, or null when no chapter holds it.
 */
export function moduleHoldingLesson(modules, lessonId) {
	return modules.find((module) => module.lessons.some((lesson) => lesson.id === lessonId)) ?? null
}
