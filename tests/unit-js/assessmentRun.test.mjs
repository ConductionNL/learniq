// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Taking an assessment runs through CnChecklistRunWidget (learniq/LqLlToetsMaken):
// one item a page, each answer saved a moment after it is given, resumed at the
// first open item, handed in from any page.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { applyAnswer, runAnswers, runContent, runQuestion, SAVE_ANSWER } from '../../src/utils/assessmentRun.js'

test('a choice item offers its options in the attempt order; an essay is answered in words', () => {
	const choices = [{ id: 'B', label: 'Celkern' }, { id: 'A', label: 'Celwand' }]
	const choice = runQuestion({ uuid: 'i1', interactionType: 'choice' }, { prompt: 'Welk deel?', choices, help: 'Kies één antwoord.' })
	assert.deepEqual(choice, {
		id: 'i1',
		text: 'Welk deel?',
		type: 'choice',
		options: [{ value: 'B', label: 'Celkern' }, { value: 'A', label: 'Celwand' }],
		help: 'Kies één antwoord.',
	})
	const essay = runQuestion({ uuid: 'i2', interactionType: 'extendedText' }, { prompt: 'Leg uit.', choices, help: '' })
	assert.equal(essay.type, 'text')
	assert.deepEqual(essay.options, [])
})

test('kept answers go in and given answers come back by item', () => {
	assert.deepEqual(runAnswers({ i1: 'B', i2: null }), [{ questionId: 'i1', value: 'B' }])
	assert.deepEqual(applyAnswer({ i1: 'B' }, { questionId: 'i2', value: 'Omdat' }), { i1: 'B', i2: 'Omdat' })
	assert.deepEqual(applyAnswer({ i1: 'B' }, { questionId: 'i1', value: '' }), { i1: null })
	assert.deepEqual(applyAnswer({ i1: 'B' }, undefined), { i1: 'B' })
})

test('the run saves each answer through the view and offers hand-in on every page', () => {
	const content = runContent({ submit: 'Toets inleveren', saved: 'Je antwoorden zijn opgeslagen' })
	assert.equal(content.saveEachAnswer, true)
	assert.equal(content.saveAction.id, SAVE_ANSWER)
	assert.equal(content.pageSize, 1)
	assert.equal(content.startAt, 'firstOpen')
	assert.equal(content.submitLabel, 'Toets inleveren')
	const view = readFileSync(new URL('../../src/views/TakeAssessmentView.vue', import.meta.url), 'utf8')
	assert.ok(view.includes('<CnChecklistRunWidget'))
	assert.ok(view.includes('cnDispatchAction'))
	assert.ok(!view.includes('scheduleAutosave'), 'the widget waits, not the view')
})
