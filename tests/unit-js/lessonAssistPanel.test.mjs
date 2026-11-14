// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The AI help panel in the lesson editor (LessonAssistPanel), through the
// component's own methods with a stubbed axios: the notice must be confirmed
// before the first run, each action posts its body to hermiq, an answer
// becomes a draft or a goal list, and a refusal reads as a sentence or
// switches the panel off for the rest of the session.
//
// @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const http = { calls: [], answers: [] }
const storage = new Map()

globalThis.window = {
	localStorage: {
		getItem: (key) => (storage.has(key) ? storage.get(key) : null),
		setItem: (key, value) => storage.set(key, String(value)),
	},
}

const stubs = {
	'@nextcloud/axios': {
		default: {
			post: async (url, body) => {
				http.calls.push([url, body])
				const next = http.answers.shift() ?? { status: 200, data: {} }
				if (next instanceof Error) throw next
				return next
			},
		},
	},
	'@nextcloud/router': { generateUrl: (path, params) => `/nc${path.replace('{action}', params.action)}` },
}

const NOTICE_KEY = 'learniq:lesson-assist-notice-confirmed'
const BLOCKS = [
	{ blockId: 'b1', type: 'richText', text: ' Photosynthesis turns light into sugar. ' },
	{ blockId: 'b2', type: 'richText', text: 'Old draft', draft: true },
	{ blockId: 'b3', type: 'image', text: 'ignored' },
]
const GOALS = [
	{ id: 'g1', title: 'Explain photosynthesis' },
	{ id: 'g2', title: 'Name the parts of a leaf' },
	{ id: 'g3', title: 'Read a graph' },
]

/**
 * A fresh panel, with its own session state, the notice confirmed unless asked.
 *
 * @param {object} [options] `props`, `confirmed`.
 * @return {Promise<object>} The instance, with `emitted` events.
 */
async function panel({ props = {}, confirmed = true } = {}) {
	const component = await loadSfc('src/components/lesson/LessonAssistPanel.vue', stubs)
	http.calls = []
	http.answers = []
	storage.clear()
	if (confirmed) storage.set(NOTICE_KEY, '1')
	const emitted = []
	const vm = instanceOf(component, {
		blocks: BLOCKS,
		goals: GOALS,
		linkedGoalIds: [],
		language: 'nl',
		...props,
		emitted,
		$emit: (...args) => emitted.push(args),
	})
	component.created.call(vm)
	component.mounted.call(vm)
	vm.component = component
	return vm
}

const ok = (data) => ({ status: 200, data: { available: true, provider: 'local', ...data } })

test('the panel reads the lesson text from its finished text blocks only', async () => {
	const vm = await panel()
	assert.equal(vm.lessonText, 'Photosynthesis turns light into sugar.')
	assert.deepEqual(vm.goalOptions, [
		{ id: 'g1', label: 'Explain photosynthesis' },
		{ id: 'g2', label: 'Name the parts of a leaf' },
		{ id: 'g3', label: 'Read a graph' },
	])
})

test('an outline posts the picked goals plus the typed one, and becomes a draft', async () => {
	const vm = await panel({ props: { language: 'en' } })
	vm.selectedGoalIds = ['g2']
	vm.freeGoal = '  Draw a leaf  '
	http.answers = [ok({ draftText: '  1. Intro\n2. Leaf  ' })]
	await vm.runOutline()
	assert.deepEqual(http.calls, [['/nc/apps/hermiq/api/lesson-authoring/outline', {
		goalTitles: ['Name the parts of a leaf', 'Draw a leaf'],
		language: 'en',
		lessonText: 'Photosynthesis turns light into sugar.',
	}]])
	assert.deepEqual(vm.emitted, [['draft', { text: '1. Intro\n2. Leaf', action: 'outline', provider: 'local', afterBlockId: null }]])
	assert.equal(vm.message, 'An AI draft was added. Check it, then keep or discard it.')
	assert.equal(vm.busyAction, '')
})

test('questions post the count and picked goals, and number the draft', async () => {
	const vm = await panel()
	vm.selectedGoalIds = ['g1']
	vm.questionCount = 3
	http.answers = [ok({ questions: ['What is light?', ' ', 'Where is sugar made?'] })]
	await vm.runQuestions()
	assert.deepEqual(http.calls[0], ['/nc/apps/hermiq/api/lesson-authoring/questions', {
		lessonText: 'Photosynthesis turns light into sugar.',
		questionCount: 3,
		language: 'nl',
		goalTitles: ['Explain photosynthesis'],
	}])
	assert.equal(vm.emitted[0][1].text, '1. What is light?\n2. Where is sugar made?')
})

test('simplifying a block asks for the reading level and drafts after that block', async () => {
	const vm = await panel()
	vm.readingLevel = 'A2'
	http.answers = [ok({ draftText: 'Plants make food from light.' })]
	await vm.simplifyBlock({ blockId: 'b1', text: 'Photosynthesis turns light into sugar.' })
	assert.deepEqual(http.calls[0], ['/nc/apps/hermiq/api/lesson-authoring/simplify', {
		lessonText: 'Photosynthesis turns light into sugar.',
		readingLevel: 'A2',
	}])
	assert.deepEqual(vm.emitted, [['draft', { text: 'Plants make food from light.', action: 'simplify', provider: 'local', afterBlockId: 'b1' }]])
})

test('goal suggestions list the suggested goals, marking those already linked', async () => {
	const vm = await panel({ props: { linkedGoalIds: ['g3'] } })
	http.answers = [ok({ suggestedGoals: [{ index: 2 }, { index: 0 }, { index: 2 }, { index: 9 }] })]
	await vm.runGoalSuggestions()
	assert.deepEqual(http.calls[0], ['/nc/apps/hermiq/api/lesson-authoring/goal-suggestions', {
		lessonText: 'Photosynthesis turns light into sugar.',
		goalTitles: ['Explain photosynthesis', 'Name the parts of a leaf', 'Read a graph'],
	}])
	assert.deepEqual(vm.suggestionRows, [
		{ id: 'g3', title: 'Read a graph', linked: true },
		{ id: 'g1', title: 'Explain photosynthesis', linked: false },
	])
	assert.equal(vm.message, 'Suggested goals are listed below. Add the ones that fit.')
	assert.deepEqual(vm.emitted, [])
})

test('no fitting goal says so', async () => {
	const vm = await panel()
	http.answers = [ok({ suggestedGoals: [] })]
	await vm.runGoalSuggestions()
	assert.deepEqual(vm.suggestionRows, [])
	assert.equal(vm.message, 'No goal from the list fits this lesson.')
})

test('the first run waits for the notice, and runs once it is confirmed', async () => {
	const vm = await panel({ confirmed: false })
	vm.selectedGoalIds = ['g1']
	await vm.runOutline()
	assert.deepEqual(http.calls, [])
	assert.equal(vm.showNotice, true)
	http.answers = [ok({ draftText: 'Outline' })]
	vm.onNoticeConfirm()
	await new Promise((resolve) => setImmediate(resolve))
	assert.equal(storage.get(NOTICE_KEY), '1')
	assert.equal(vm.showNotice, false)
	assert.equal(vm.pendingRun, null)
	assert.equal(http.calls.length, 1)
	assert.equal(vm.emitted[0][1].text, 'Outline')
})

test('a cancelled notice runs nothing', async () => {
	const vm = await panel({ confirmed: false })
	await vm.runQuestions()
	vm.onNoticeCancel()
	assert.equal(vm.showNotice, false)
	assert.equal(vm.pendingRun, null)
	assert.deepEqual(http.calls, [])
})

test('a confirmed notice holds for the session even when storage is blocked', async () => {
	const vm = await panel({ confirmed: false })
	const original = globalThis.window.localStorage
	globalThis.window.localStorage = {
		getItem: () => { throw new Error('blocked') },
		setItem: () => { throw new Error('blocked') },
	}
	try {
		assert.equal(vm.noticeConfirmed(), false)
		vm.onNoticeConfirm()
		assert.equal(vm.noticeConfirmed(), true)
	} finally {
		globalThis.window.localStorage = original
	}
})

test('a second run while one is busy is ignored', async () => {
	const vm = await panel()
	vm.busyAction = 'outline'
	await vm.runQuestions()
	assert.deepEqual(http.calls, [])
})

test('a busy or failed model reads as a sentence and adds no draft', async () => {
	const vm = await panel()
	http.answers = [Object.assign(new Error('429'), { response: { status: 429, data: {} } })]
	await vm.runQuestions()
	assert.equal(vm.message, 'Too many requests. Wait a minute and try again.')

	http.answers = [{ status: 200, data: { available: true, questions: [] } }]
	await vm.runQuestions()
	assert.equal(vm.message, 'The AI model gave no usable answer. Try again later.')

	http.answers = [new Error('Network Error')]
	await vm.runQuestions()
	assert.equal(vm.message, 'The AI help could not run. Try again later.')
	assert.deepEqual(vm.emitted, [])
	assert.equal(vm.switchedOff, false)
})

test('hermiq missing switches the panel off for the rest of the session', async () => {
	const vm = await panel()
	http.answers = [Object.assign(new Error('404'), { response: { status: 404, data: {} } })]
	await vm.runQuestions()
	assert.equal(vm.switchedOff, true)
	assert.deepEqual(vm.emitted, [['off']])

	await vm.runQuestions()
	assert.equal(http.calls.length, 1)

	const next = instanceOf(vm.component, { blocks: BLOCKS, goals: GOALS, $emit: (...args) => vm.emitted.push(args) })
	assert.equal(next.switchedOff, true)
	vm.component.mounted.call(next)
	assert.deepEqual(vm.emitted, [['off'], ['off']])
})

test('a feature that is not enabled also switches the panel off', async () => {
	const vm = await panel()
	http.answers = [{ status: 200, data: { available: false, reason: 'feature-not-enabled' } }]
	await vm.runOutline()
	assert.equal(vm.switchedOff, true)
	assert.equal(vm.message, '')
})
