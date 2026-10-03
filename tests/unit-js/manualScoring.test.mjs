// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// learniq#948: a teacher had no screen to score open answers, so an attempt
// with an essay item never passed AssessmentGradeGuard. These tests pin the
// scoring screen's wiring (a registered custom page reached from the results
// list) and the pure helpers it scores with.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	applyScore,
	isFullyScored,
	manualItems,
	needsManualScoring,
	resultUrl,
	scoreError,
	transitionUrl,
} from '../../src/utils/manualScoring.js'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const read = (p) => readFileSync(resolve(root, p), 'utf8')
const learning = JSON.parse(read('src/manifest.d/learning.json'))

const essay = { id: 'essay', interactionType: 'extendedText', maxScore: 10 }
const open = {
	id: 'open',
	interactionType: 'textEntry',
	correctResponse: null,
	maxScore: 4,
}
const mc = {
	id: 'mc',
	interactionType: 'choice',
	correctResponse: { value: 'B' },
	maxScore: 1,
}

const result = {
	id: 'r1',
	lifecycle: 'submitted',
	responses: [
		{
			itemId: 'essay',
			response: { value: 'My essay' },
			autoScore: null,
			manualScore: null,
		},
		{
			itemId: 'open',
			response: { value: 'x' },
			autoScore: null,
			manualScore: null,
		},
		{ itemId: 'mc', response: { value: 'B' }, autoScore: 1, manualScore: null },
	],
}

test('a custom page renders the scoring screen and its component is registered', () => {
	const pageDef = learning.pages.find((p) => p.id === 'AssessmentScoring')
	assert.ok(pageDef, 'AssessmentScoring page is missing')
	assert.equal(pageDef.type, 'custom')
	assert.equal(pageDef.route, '/assessments/:assessmentId/scoring')
	assert.match(
		read('src/registry.js'),
		new RegExp(`\\b${pageDef.component}: page\\(${pageDef.component}\\)`),
	)
})

test('the results list links to the scoring screen', () => {
	const index = learning.pages.find((p) => p.id === 'AssessmentResults')
	const actions = index.config.headerActions || []
	assert.ok(
		actions.some(
			(a) => a.handler === 'navigate' && a.route === 'AssessmentScoring',
		),
		'no header action navigates to AssessmentScoring',
	)
})

test('the view writes scores with a PATCH and grades through the transition endpoint', () => {
	const view = read('src/views/AssessmentScoringView.vue')
	assert.ok(view.includes('axios.patch(generateUrl(resultUrl('))
	assert.ok(view.includes("action: 'grade'"))
	assert.equal(
		resultUrl('r 1'),
		'/apps/openregister/api/objects/learniq/assessment-result/r%201',
	)
	// Open Register routes a transition at /api/objects/{id}/transition with
	// the action in the body; there is no /{register}/{schema}/{id}/transition/{action}.
	assert.equal(transitionUrl('r1'), '/apps/openregister/api/objects/r1/transition')
})

test('an item needs manual scoring the way AssessmentGradeGuard decides it', () => {
	assert.equal(needsManualScoring(essay), true)
	assert.equal(needsManualScoring(open), true)
	assert.equal(needsManualScoring(mc), false)
})

test('the manual items follow the assessment order and take the points override as maximum', () => {
	const assessment = {
		itemRefs: [
			{ itemId: 'mc', points: 1 },
			{ itemId: 'open', points: 2 },
			{ itemId: 'essay' },
		],
	}
	const items = { essay, open, mc }
	assert.deepEqual(manualItems(assessment, items), [
		{ itemId: 'open', title: 'open', max: 2 },
		{ itemId: 'essay', title: 'essay', max: 10 },
	])
})

test('applying a score changes only that response and keeps the answers as they were', () => {
	const responses = applyScore(result, 'essay', 7)
	assert.deepEqual(responses[0], { ...result.responses[0], manualScore: 7 })
	assert.deepEqual(responses.slice(1), result.responses.slice(1))
	assert.equal(result.responses[0].manualScore, null, 'input was mutated')
})

test('an attempt is ready to grade once every manual item has a score', () => {
	assert.equal(isFullyScored(result, ['essay', 'open']), false)
	let scored = { ...result, responses: applyScore(result, 'essay', 7) }
	assert.equal(isFullyScored(scored, ['essay', 'open']), false)
	scored = { ...scored, responses: applyScore(scored, 'open', 0) }
	assert.equal(isFullyScored(scored, ['essay', 'open']), true)
})

test('a score is a number from zero up to the maximum', () => {
	assert.equal(scoreError('', 10), '')
	assert.equal(scoreError('7', 10), '')
	assert.equal(scoreError('0', 10), '')
	assert.notEqual(scoreError('-1', 10), '')
	assert.notEqual(scoreError('11', 10), '')
	assert.notEqual(scoreError('abc', 10), '')
	assert.equal(scoreError('11', null), '')
})

test('the learner submit saves with a PATCH and reaches the transition endpoint, so an attempt can become submitted', () => {
	const view = read('src/views/TakeAssessmentView.vue')
	const submit = view.slice(view.indexOf('async submitAssessment()'))
	const body = submit.slice(0, submit.indexOf('\n\t\t},\n'))
	// The old URL, /objects/learniq/assessment-result/{id}/transition/submit, is
	// not an Open Register route and answered 404.
	assert.ok(
		!body.includes('/transition/submit'),
		'submit still calls a route that does not exist',
	)
	assert.ok(body.includes("action: 'submit'"), 'submit does not send the action')
	assert.ok(body.includes('transitionUrl('), 'submit does not use transitionUrl')
	// A PUT replaces the object; the learner sends only responses and submittedAt.
	assert.ok(
		body.includes("method: 'PATCH'"),
		'responses are not saved with a PATCH',
	)
})
