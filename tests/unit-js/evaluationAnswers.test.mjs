// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// assessment-course-evaluation-answer-page: the answer form's rules for each
// question kind, the body it posts and how the staff figures read.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	answerBody,
	formQuestions,
	isAnswered,
	missingRequired,
	questionText,
	resultFigures,
} from '../../src/utils/evaluationAnswers.js'

const questions = [
	{
		questionId: 'q1',
		text: { nl: 'Duidelijk?', en: 'Clear?' },
		kind: 'likert-5',
		required: true,
	},
	{
		questionId: 'q5',
		text: { nl: 'Algemeen oordeel', en: 'Overall' },
		kind: 'likert-5',
		required: true,
	},
	{
		questionId: 'q6',
		text: { nl: 'Wat kan beter?', en: 'What could be better?' },
		kind: 'free-text',
		required: false,
	},
	{ questionId: 'q7', text: { en: 'Essay' }, kind: 'essay', required: true },
	{ questionId: '', kind: 'likert-5' },
	null,
]

test('the form renders known kinds only, in order', () => {
	assert.deepEqual(
		formQuestions(questions).map((q) => q.questionId),
		['q1', 'q5', 'q6'],
	)
	assert.deepEqual(formQuestions(undefined), [])
})

test('question text follows the reader language, then English, then Dutch', () => {
	assert.equal(questionText(questions[0], 'nl'), 'Duidelijk?')
	assert.equal(questionText(questions[0], 'en-GB'), 'Clear?')
	assert.equal(questionText(questions[0], 'de'), 'Clear?')
	assert.equal(
		questionText({ questionId: 'q9', text: { nl: 'Alleen NL' } }, 'de'),
		'Alleen NL',
	)
	assert.equal(questionText({ questionId: 'q9' }, 'de'), 'q9')
})

test('a rating is a whole number from 1 to 5; text is not blank', () => {
	assert.equal(isAnswered('likert-5', 3), true)
	assert.equal(isAnswered('likert-5', '5'), true)
	for (const bad of [0, 6, 2.5, '', null, undefined, 'x']) {
		assert.equal(isAnswered('likert-5', bad), false, String(bad))
	}
	assert.equal(isAnswered('free-text', ' ok '), true)
	assert.equal(isAnswered('free-text', '   '), false)
	assert.equal(isAnswered('free-text', 4), false)
})

test('required questions without an answer block the submit', () => {
	assert.deepEqual(missingRequired(questions, {}), ['q1', 'q5'])
	assert.deepEqual(missingRequired(questions, { q1: 4, q5: 5 }), [])
	assert.deepEqual(missingRequired(questions, undefined), ['q1', 'q5'])
})

test('the posted body holds answered questions only and nothing about the learner', () => {
	assert.deepEqual(
		answerBody(questions, {
			q1: '4',
			q5: 5,
			q6: '  More practice. ',
			q7: 'x',
			learnerId: 'jan',
		}),
		{
			answers: { q1: 4, q5: 5, q6: 'More practice.' },
		},
	)
	assert.deepEqual(answerBody(questions, { q6: '  ' }), { answers: {} })
})

test('staff figures hide the mean under five responses', () => {
	assert.deepEqual(
		resultFigures({
			invitationCount: 4,
			responseCount: 3,
			meanOverallScore: null,
			meanHidden: true,
		}),
		{
			responses: 3,
			invitations: 4,
			mean: null,
			hidden: true,
		},
	)
	assert.deepEqual(
		resultFigures({
			invitationCount: 8,
			responseCount: 6,
			meanOverallScore: 4,
			meanHidden: false,
		}),
		{
			responses: 6,
			invitations: 8,
			mean: 4,
			hidden: false,
		},
	)
	assert.deepEqual(resultFigures(null), {
		responses: 0,
		invitations: 0,
		mean: null,
		hidden: true,
	})
})
