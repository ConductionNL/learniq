// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The next-step editor in the lesson editor (LessonNextStepEditor), through
// the component's own methods: rules are edited on a local copy, every
// change emits only the rules that can be saved, and the default next lesson
// is emitted as null when cleared.
//
// @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const component = await loadSfc('src/components/lesson/LessonNextStepEditor.vue')

/**
 * A fresh editor on these rules.
 *
 * @param {Array} rules The stored rules.
 * @param {object} [props] Other props.
 * @return {object} The instance, with `emitted` events.
 */
function editor(rules, props = {}) {
	const emitted = []
	return instanceOf(component, { rules, lessons: [], assessments: [], ...props, emitted, $emit: (...args) => emitted.push(args) })
}

const STORED = [{ when: { kind: 'score-below', assessmentId: 'a1', belowScore: 5.5 }, goToLessonId: 'l2' }]

test('the editor works on a filled-in copy of the stored rules', () => {
	const vm = editor(STORED)
	assert.deepEqual(vm.local, [{
		when: { kind: 'score-below', assessmentId: 'a1', belowScore: 5.5, lessonId: null, minScore: null },
		goToLessonId: 'l2',
	}])
	vm.local[0].goToLessonId = 'l3'
	assert.equal(STORED[0].goToLessonId, 'l2')
	assert.deepEqual(editor(null).local, [])
})

test('a lesson and an assessment are offered by name, else by id', () => {
	const vm = editor([], {
		lessons: [{ id: 'l1', name: 'Intro' }, { id: 'l2' }],
		assessments: [{ id: 'a1', title: 'Quiz' }, { id: 'a2' }],
	})
	assert.deepEqual(vm.lessonOptions, [{ id: 'l1', label: 'Intro' }, { id: 'l2', label: 'l2' }])
	assert.deepEqual(vm.assessmentOptions, [{ id: 'a1', label: 'Quiz' }, { id: 'a2', label: 'a2' }])
})

test('a score rule edits the below or the minimum score', () => {
	const vm = editor([])
	assert.equal(vm.scoreField({ when: { kind: 'score-below' } }), 'belowScore')
	assert.equal(vm.scoreField({ when: { kind: 'assessment-min-score' } }), 'minScore')
})

test('adding a rule emits only the rules that can be saved', () => {
	const vm = editor(STORED)
	vm.addRule()
	assert.equal(vm.local.length, 2)
	assert.deepEqual(vm.local[1], { when: { kind: 'score-below', assessmentId: null, belowScore: null }, goToLessonId: null })
	assert.deepEqual(vm.emitted, [['update:rules', [
		{ when: { kind: 'score-below', assessmentId: 'a1', belowScore: 5.5 }, goToLessonId: 'l2' },
	]]])
})

test('a changed rule is emitted with its score as a number', () => {
	const vm = editor(STORED)
	vm.local[0].when.belowScore = '6'
	vm.changed()
	assert.deepEqual(vm.emitted.at(-1), ['update:rules', [
		{ when: { kind: 'score-below', assessmentId: 'a1', belowScore: 6 }, goToLessonId: 'l2' },
	]])
})

test('removing a rule emits what is left', () => {
	const vm = editor([...STORED, { when: { kind: 'lesson-completed', lessonId: 'l1' }, goToLessonId: 'l4' }])
	vm.removeRule(0)
	assert.deepEqual(vm.emitted, [['update:rules', [{ when: { kind: 'lesson-completed', lessonId: 'l1' }, goToLessonId: 'l4' }]]])
})

test('the default next lesson is emitted, cleared as null', () => {
	const vm = editor([])
	vm.setDefault('l2')
	vm.setDefault(undefined)
	vm.setDefault(null)
	assert.deepEqual(vm.emitted, [
		['update:defaultNextLessonId', 'l2'],
		['update:defaultNextLessonId', null],
		['update:defaultNextLessonId', null],
	])
})
