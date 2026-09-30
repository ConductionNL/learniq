// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// content-adaptive-next-step-and-preview: the preview state the lesson player
// reads from its route, the header every write it makes carries in a preview,
// and the rules the next step editor saves. The player makes no write in a
// preview; these helpers are what makes a slip still visible to the server.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	editableRules,
	emptyRule,
	isNextStepRefusal,
	nextStepPath,
	PREVIEW_HEADER,
	previewFromQuery,
	previewQuery,
	serialiseRules,
	writeHeaders,
} from '../../src/utils/lessonPreview.js'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')

test('a preview is read from the route query, with its simulated score', () => {
	assert.deepEqual(previewFromQuery({ preview: '1', score: '45' }), {
		active: true,
		score: 45,
	})
	assert.deepEqual(previewFromQuery({ preview: '1' }), {
		active: true,
		score: null,
	})
	assert.deepEqual(previewFromQuery({ preview: '1', score: 'x' }), {
		active: true,
		score: null,
	})
	assert.deepEqual(previewFromQuery({ score: '45' }), {
		active: false,
		score: null,
	})
	assert.deepEqual(previewFromQuery(undefined), { active: false, score: null })
})

test('the next lesson keeps the preview and its score', () => {
	assert.deepEqual(previewQuery({ active: true, score: 45 }), {
		preview: '1',
		score: '45',
	})
	assert.deepEqual(previewQuery({ active: true, score: null }), { preview: '1' })
	assert.deepEqual(previewQuery({ active: false, score: 45 }), {})
	assert.equal(
		nextStepPath('l-1', { active: false, score: null }),
		'/apps/learniq/api/lessons/l-1/next-step',
	)
	assert.equal(
		nextStepPath('l-1', { active: true, score: 80 }),
		'/apps/learniq/api/lessons/l-1/next-step?preview=1&score=80',
	)
})

test('every write in a preview carries the header the server refuses', () => {
	assert.deepEqual(
		writeHeaders({ active: true }, { Accept: 'application/json' }),
		{ Accept: 'application/json', [PREVIEW_HEADER]: '1' },
	)
	assert.deepEqual(
		writeHeaders({ active: false }, { Accept: 'application/json' }),
		{ Accept: 'application/json' },
	)
	assert.deepEqual(writeHeaders(undefined), {})
	assert.equal(PREVIEW_HEADER, 'X-Learniq-Preview')
	const guard = readFileSync(
		resolve(root, 'lib/Listener/PreviewWriteGuard.php'),
		'utf8',
	)
	assert.match(
		guard,
		new RegExp(`HEADER = '${PREVIEW_HEADER}'`),
		'the player and the server agree on the header name',
	)
})

test('the lesson player makes no write without the preview headers', () => {
	const player = readFileSync(resolve(root, 'src/views/LessonPlayer.vue'), 'utf8')
	const posts = player.match(/method: 'POST'/g) ?? []
	const marked = player.match(/writeHeaders\(this\.preview/g) ?? []
	assert.ok(posts.length > 0)
	assert.equal(
		marked.length,
		posts.length,
		'each POST in the player sends writeHeaders(this.preview, ...)',
	)
})

test('the editor saves only the fields a rule kind uses, and no rule without a target', () => {
	const rules = [
		{
			when: {
				kind: 'score-below',
				assessmentId: 'a-1',
				belowScore: '60',
				minScore: 5,
				lessonId: 'x',
			},
			goToLessonId: 'l-refresh',
		},
		{
			when: {
				kind: 'assessment-min-score',
				assessmentId: 'a-1',
				minScore: '',
			},
			goToLessonId: 'l-honours',
		},
		{
			when: { kind: 'lesson-completed', lessonId: 'l-intro' },
			goToLessonId: 'l-practice',
		},
		{ when: { kind: 'score-below' }, goToLessonId: null },
		{ when: { kind: 'moon-phase' }, goToLessonId: 'l-x' },
	]
	assert.deepEqual(serialiseRules(rules), [
		{
			when: { kind: 'score-below', assessmentId: 'a-1', belowScore: 60 },
			goToLessonId: 'l-refresh',
		},
		{
			when: {
				kind: 'assessment-min-score',
				assessmentId: 'a-1',
				minScore: null,
			},
			goToLessonId: 'l-honours',
		},
		{
			when: { kind: 'lesson-completed', lessonId: 'l-intro' },
			goToLessonId: 'l-practice',
		},
	])
	assert.deepEqual(serialiseRules(undefined), [])
})

test('stored rules open in the editor with every field, however they were stored', () => {
	assert.deepEqual(editableRules([{ goToLessonId: 'l-2' }, null]), [
		{
			when: {
				kind: 'score-below',
				assessmentId: null,
				belowScore: null,
				lessonId: null,
				minScore: null,
			},
			goToLessonId: 'l-2',
		},
		{
			when: {
				kind: 'score-below',
				assessmentId: null,
				belowScore: null,
				lessonId: null,
				minScore: null,
			},
			goToLessonId: null,
		},
	])
	assert.deepEqual(editableRules('none'), [])
	assert.deepEqual(emptyRule(), {
		when: { kind: 'score-below', assessmentId: null, belowScore: null },
		goToLessonId: null,
	})
})

test('the composer recognises the same-course refusal in the save response', () => {
	assert.equal(
		isNextStepRefusal({ errors: { reason: 'next-step-outside-course' } }),
		true,
	)
	assert.equal(
		isNextStepRefusal({
			message: 'A next step can only go to a lesson of the same course.',
		}),
		true,
	)
	assert.equal(isNextStepRefusal({ message: 'Validation failed' }), false)
	assert.equal(isNextStepRefusal(undefined), false)
})
