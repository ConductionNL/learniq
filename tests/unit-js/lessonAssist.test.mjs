// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Unit tests for the lesson composer's AI assist actions
// (lesson-ai-assist-actions). Hermiq's delegate (hermiq PR 962) is not merged,
// so every path runs against a stubbed transport. Run via `npm run test:js-unit`.

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	buildRequestBody,
	canRun,
	classifyOutcome,
	createLessonAssistClient,
	draftTextFromResult,
	GOAL_COUNT_MAX,
	GOAL_TITLE_MAX,
	goalIdsFromSuggestions,
	goalPayload,
	isHermiqEnabled,
	LESSON_TEXT_MAX,
	lessonTextFromBlocks,
	normaliseLanguage,
	normaliseQuestionCount,
	normaliseReadingLevel,
} from '../../src/utils/lessonAssist.js'
import {
	countPendingDrafts,
	makeDraftBlock,
	serialiseLessonBlocks,
} from '../../src/utils/lessonBlocks.js'

const GOAL_A = { id: '00000000-0000-0000-0000-00000000000a', title: 'De leerling kan breuken vergelijken' }
const GOAL_B = { id: '00000000-0000-0000-0000-00000000000b', title: 'De leerling kan procenten berekenen' }
const GOAL_C = { id: '00000000-0000-0000-0000-00000000000c', title: 'De leerling kan breuken op een getallenlijn zetten' }

/**
 * A stub transport that records every call and answers from a queue.
 *
 * @param {Array<object|Error>} answers Axios-shaped answers, or errors to throw.
 * @return {{post: Function, urlFor: Function, calls: Array<object>}} The stub.
 */
function stubTransport(answers) {
	const calls = []
	return {
		calls,
		urlFor: (action) => `/apps/hermiq/api/lesson-authoring/${action}`,
		post: async (url, body) => {
			calls.push({ url, body })
			const next = answers.shift()
			if (next instanceof Error) throw next
			return next
		},
	}
}

/**
 * An axios-like rejection with an HTTP status.
 *
 * @param {number} status The status.
 * @return {Error} The error.
 */
function httpError(status) {
	const error = new Error(`HTTP ${status}`)
	error.response = { status, data: { error: 'x' } }
	return error
}

test('isHermiqEnabled reads the enabled-apps web roots', () => {
	assert.equal(isHermiqEnabled(undefined), false)
	assert.equal(isHermiqEnabled({}), false)
	assert.equal(isHermiqEnabled({ learniq: '/apps/learniq' }), false)
	assert.equal(isHermiqEnabled({ hermiq: '/apps/hermiq' }), true)
})

test('control fields are clamped to what hermiq accepts', () => {
	assert.equal(normaliseLanguage('nl'), 'nl')
	assert.equal(normaliseLanguage('en-GB'), 'en-GB')
	assert.equal(normaliseLanguage(''), 'nl')
	assert.equal(normaliseLanguage('not a tag'), 'nl')
	assert.equal(normaliseReadingLevel('A2'), 'A2')
	assert.equal(normaliseReadingLevel('2F'), '2F')
	assert.equal(normaliseReadingLevel('C2'), 'B1')
	assert.equal(normaliseQuestionCount('3'), 3)
	assert.equal(normaliseQuestionCount(0), 1)
	assert.equal(normaliseQuestionCount(99), 10)
	assert.equal(normaliseQuestionCount(undefined), 5)
})

test('lesson text leaves out pending drafts and other block types, and respects the limit', () => {
	const blocks = [
		{ blockId: 'b1', type: 'richText', order: 1, text: 'Breuken met dezelfde noemer.' },
		{ blockId: 'b2', type: 'media', order: 2, materialId: 'm1' },
		{ blockId: 'b3', type: 'richText', order: 3, text: 'AI draft text', draft: { action: 'outline' } },
		{ blockId: 'b4', type: 'richText', order: 4, text: '  Vergelijk de tellers.  ' },
	]
	assert.equal(lessonTextFromBlocks(blocks), 'Breuken met dezelfde noemer.\n\nVergelijk de tellers.')

	const long = [{ blockId: 'x', type: 'richText', order: 1, text: 'a'.repeat(LESSON_TEXT_MAX + 50) }]
	assert.equal(lessonTextFromBlocks(long).length, LESSON_TEXT_MAX)
})

test('goal ids never leave learniq: titles go out in a fixed order, ids stay behind', () => {
	const { titles, ids } = goalPayload([GOAL_A, GOAL_B, { id: 'no-title', title: '  ' }, GOAL_A, GOAL_C])
	assert.deepEqual(titles, [GOAL_A.title, GOAL_B.title, GOAL_C.title])
	assert.deepEqual(ids, [GOAL_A.id, GOAL_B.id, GOAL_C.id])

	const many = Array.from({ length: GOAL_COUNT_MAX + 5 }, (_, i) => ({ id: `g${i}`, title: 't'.repeat(GOAL_TITLE_MAX + 10) }))
	const capped = goalPayload(many)
	assert.equal(capped.ids.length, GOAL_COUNT_MAX)
	assert.equal(capped.titles[0].length, GOAL_TITLE_MAX)
})

test('suggested indexes map back to goal ids; out-of-range, non-integer and repeated indexes are ignored', () => {
	const ids = [GOAL_A.id, GOAL_B.id, GOAL_C.id]
	const suggested = [{ index: 0 }, { index: 2 }, { index: 7 }, { index: -1 }, { index: 1.5 }, { index: '1' }, { index: 0 }]
	assert.deepEqual(goalIdsFromSuggestions(suggested, ids), [GOAL_A.id, GOAL_C.id])
	assert.deepEqual(goalIdsFromSuggestions(undefined, ids), [])
})

test('request bodies carry only the contract fields for each action', () => {
	const input = {
		lessonText: 'Les over breuken.',
		goalTitles: [GOAL_A.title],
		language: 'nl',
		questionCount: 4,
		readingLevel: 'A2',
		// Fields the composer never passes, to prove the allowlist drops them.
		pupilName: 'Sanne',
		lessonId: '00000000-0000-0000-0000-000000000001',
	}
	assert.deepEqual(Object.keys(buildRequestBody('outline', input)).sort(), ['goalTitles', 'language', 'lessonText'])
	assert.deepEqual(Object.keys(buildRequestBody('questions', input)).sort(), ['goalTitles', 'language', 'lessonText', 'questionCount'])
	assert.deepEqual(Object.keys(buildRequestBody('simplify', input)).sort(), ['lessonText', 'readingLevel'])
	assert.deepEqual(Object.keys(buildRequestBody('goal-suggestions', input)).sort(), ['goalTitles', 'lessonText'])

	// Optional fields are left out when empty.
	assert.deepEqual(Object.keys(buildRequestBody('outline', { goalTitles: [GOAL_A.title] })).sort(), ['goalTitles', 'language'])
	assert.deepEqual(Object.keys(buildRequestBody('questions', { lessonText: 'x' })).sort(), ['language', 'lessonText', 'questionCount'])

	assert.throws(() => buildRequestBody('translate', input))
})

test('an action runs only when it has what it needs', () => {
	assert.equal(canRun('outline', buildRequestBody('outline', {})), false)
	assert.equal(canRun('outline', buildRequestBody('outline', { goalTitles: ['g'] })), true)
	assert.equal(canRun('questions', buildRequestBody('questions', {})), false)
	assert.equal(canRun('simplify', buildRequestBody('simplify', { lessonText: 'x' })), true)
	assert.equal(canRun('goal-suggestions', buildRequestBody('goal-suggestions', { lessonText: 'x' })), false)
	assert.equal(canRun('goal-suggestions', buildRequestBody('goal-suggestions', { lessonText: 'x', goalTitles: ['g'] })), true)
})

test('every answer falls into one outcome class', () => {
	const ok = { available: true, action: 'outline', draft: true, provider: 'nextcloud', draftText: '1. Start' }
	assert.equal(classifyOutcome('outline', { status: 200, data: ok }).outcome, 'ok')
	assert.equal(classifyOutcome('outline', { status: 200, data: { available: false, reason: 'feature-not-enabled' } }).outcome, 'off')
	assert.equal(classifyOutcome('outline', { status: 404, data: {} }).outcome, 'off')
	assert.equal(classifyOutcome('outline', { status: 200, data: { available: false, reason: 'provider-error' } }).outcome, 'retry')
	assert.equal(classifyOutcome('outline', { status: 200, data: { available: true, draftText: '   ' } }).outcome, 'retry')
	assert.equal(classifyOutcome('questions', { status: 200, data: { available: true, questions: [] } }).outcome, 'retry')
	assert.equal(classifyOutcome('outline', { status: 429 }).outcome, 'busy')
	assert.equal(classifyOutcome('outline', { status: 400, data: { error: 'x' } }).outcome, 'failed')
	assert.equal(classifyOutcome('outline', { status: 500, data: { error: 'x' } }).outcome, 'failed')
	assert.equal(classifyOutcome('outline', { error: new Error('network') }).outcome, 'failed')

	// An empty goal suggestion list is a valid answer, not a failure.
	const none = classifyOutcome('goal-suggestions', { status: 200, data: { available: true, suggestedGoals: [] } })
	assert.equal(none.outcome, 'ok')
})

test('the client posts the allowlisted body to the action URL and classifies the answer', async () => {
	const transport = stubTransport([
		{ status: 200, data: { available: true, action: 'goal-suggestions', draft: true, provider: 'ollama', suggestedGoals: [{ index: 1, title: GOAL_B.title }] } },
		{ status: 200, data: { available: false, action: 'questions', reason: 'feature-not-enabled' } },
		httpError(429),
		httpError(404),
		new Error('offline'),
	])
	const client = createLessonAssistClient(transport)
	const { titles, ids } = goalPayload([GOAL_A, GOAL_B])

	const suggestions = await client.run('goal-suggestions', { lessonText: 'Procenten.', goalTitles: titles })
	assert.equal(suggestions.outcome, 'ok')
	assert.deepEqual(goalIdsFromSuggestions(suggestions.data.suggestedGoals, ids), [GOAL_B.id])
	assert.equal(transport.calls[0].url, '/apps/hermiq/api/lesson-authoring/goal-suggestions')
	assert.deepEqual(transport.calls[0].body, { lessonText: 'Procenten.', goalTitles: [GOAL_A.title, GOAL_B.title] })
	assert.ok(!JSON.stringify(transport.calls[0].body).includes(GOAL_A.id))

	assert.equal((await client.run('questions', { lessonText: 'x' })).outcome, 'off')
	assert.equal((await client.run('simplify', { lessonText: 'x' })).outcome, 'busy')
	assert.equal((await client.run('outline', { goalTitles: ['g'] })).outcome, 'off')
	assert.equal((await client.run('outline', { goalTitles: ['g'] })).outcome, 'failed')
	await assert.rejects(() => client.run('translate', {}))
})

test('draft text: questions become a numbered list, outlines and rewrites stay as returned', () => {
	assert.equal(draftTextFromResult('questions', { questions: ['Welke breuk is groter?', ' ', 'Waarom?'] }), '1. Welke breuk is groter?\n2. Waarom?')
	assert.equal(draftTextFromResult('outline', { draftText: ' 1. Start\n2. Instructie ' }), '1. Start\n2. Instructie')
	assert.equal(draftTextFromResult('simplify', { draftText: 'Planten maken voedsel.' }), 'Planten maken voedsel.')
})

test('drafts are marked, can be kept, and never reach OpenRegister with the marker', () => {
	const blocks = [
		{ blockId: 'b1', type: 'richText', order: 1, text: 'Eigen tekst', materialId: null },
		makeDraftBlock({ blockId: 'b2', text: '1. Start', action: 'outline', provider: 'nextcloud', note: 'Check it.' }),
	]
	assert.equal(countPendingDrafts(blocks), 1)
	assert.deepEqual(blocks[1].draft, { note: 'Check it.', action: 'outline', provider: 'nextcloud' })

	const serialised = serialiseLessonBlocks(blocks)
	assert.deepEqual(serialised[1], { blockId: 'b2', type: 'richText', order: 0, text: '1. Start' })
	assert.ok(!JSON.stringify(serialised).includes('draft'))

	// CnBlockListEditor keeps a draft by emitting the block without `draft`.
	const { draft, ...kept } = blocks[1]
	assert.ok(draft)
	assert.equal(countPendingDrafts([blocks[0], kept]), 0)
	blocks[1] = kept
	assert.equal(blocks[1].type, 'richText')
})

test('the serialiser keeps only the payload of each block type (unchanged behaviour)', () => {
	const serialised = serialiseLessonBlocks([
		{ blockId: 'm', type: 'media', order: 1, text: null, materialId: 'mat-1', assessmentId: null },
		{ blockId: 'q', type: 'quiz', order: 2, assessmentId: null },
		{ blockId: 'e', type: 'richText', order: 3, text: '' },
	])
	assert.deepEqual(serialised, [
		{ blockId: 'm', type: 'media', order: 1, materialId: 'mat-1' },
		{ blockId: 'q', type: 'quiz', order: 2 },
		{ blockId: 'e', type: 'richText', order: 3, text: '' },
	])
})

test('each outcome carries its reason, and only ok carries the answer', () => {
	const off = { outcome: 'off', reason: 'not-installed', data: null }
	assert.deepEqual(classifyOutcome('outline', { status: 404, data: { available: true } }), off)
	assert.deepEqual(classifyOutcome('outline', { status: 429, error: 'x' }), { outcome: 'busy', reason: 'rate-limited', data: null })
	const failed = { outcome: 'failed', reason: 'request-failed', data: null }
	assert.deepEqual(classifyOutcome('outline', undefined), failed)
	assert.deepEqual(classifyOutcome('outline', { status: 200, error: null, data: { available: true, draftText: 'x' } }), failed)
	assert.deepEqual(classifyOutcome('outline', { status: 200 }), failed)
	assert.deepEqual(classifyOutcome('outline', { status: 200, data: 'text' }), failed)
	assert.deepEqual(classifyOutcome('outline', { status: 200, data: { available: false, reason: 'feature-not-enabled', draftText: 'x' } }), {
		outcome: 'off',
		reason: 'feature-not-enabled',
		data: null,
	})
	assert.deepEqual(classifyOutcome('outline', { status: 200, data: { available: false, reason: 7 } }), { outcome: 'retry', reason: '7', data: null })
	assert.deepEqual(classifyOutcome('outline', { status: 200, data: { available: false } }), { outcome: 'retry', reason: 'provider-error', data: null })
	const unusable = { outcome: 'retry', reason: 'unusable-answer', data: null }
	assert.deepEqual(classifyOutcome('outline', { status: 200, data: { draftText: 'x' } }), unusable)
	assert.deepEqual(classifyOutcome('outline', { status: 200, data: { available: true, draftText: 3 } }), unusable)
	assert.deepEqual(classifyOutcome('questions', { status: 200, data: { available: true, questions: 'q' } }), unusable)
	assert.deepEqual(classifyOutcome('unknown', { status: 200, data: { available: true } }), unusable)
	const goals = { available: true, suggestedGoals: [] }
	assert.deepEqual(classifyOutcome('goal-suggestions', { status: 200, data: goals }), { outcome: 'ok', reason: null, data: goals })
	const rewrite = { available: true, draftText: ' text ' }
	assert.deepEqual(classifyOutcome('simplify', { status: 200, data: rewrite }), { outcome: 'ok', reason: null, data: rewrite })
	const questions = { available: true, questions: [{ q: 1 }] }
	assert.deepEqual(classifyOutcome('questions', { status: 200, data: questions }), { outcome: 'ok', reason: null, data: questions })
})

test('goal payloads skip blank, repeated and missing goals and stop at the cap', () => {
	assert.deepEqual(goalPayload(undefined), { titles: [], ids: [] })
	assert.deepEqual(
		goalPayload([null, { id: 7, title: '  Read  ' }, { id: '', title: 'x' }, { id: 'b' }, { id: 7, title: 'again' }, { id: 'c', title: 'C' }]),
		{ titles: ['Read', 'C'], ids: ['7', 'c'] },
	)
	const long = 'a'.repeat(GOAL_TITLE_MAX) + '   b'
	assert.equal(goalPayload([{ id: 'l', title: ' ' + long }]).titles[0].length, GOAL_TITLE_MAX)
	const many = Array.from({ length: GOAL_COUNT_MAX + 5 }, (_, i) => ({ id: `g${i}`, title: `T${i}` }))
	const capped = goalPayload(many)
	assert.equal(capped.ids.length, GOAL_COUNT_MAX)
	assert.equal(capped.ids.at(-1), `g${GOAL_COUNT_MAX - 1}`)
	assert.deepEqual(goalIdsFromSuggestions([{ index: 1 }, null, { index: -1 }, { index: 2 }, { index: 0.5 }, { index: '0' }, { index: 1 }, { index: 0 }], ['a', 'b']), ['b', 'a'])
	assert.deepEqual(goalIdsFromSuggestions({ index: 0 }, ['a']), [])
})

test('request bodies normalise their fields and refuse an unknown action', () => {
	assert.throws(() => buildRequestBody('translate', {}), { message: 'Unknown lesson assist action: translate' })
	assert.deepEqual(buildRequestBody('outline'), { goalTitles: [], language: buildRequestBody('outline').language })
	assert.deepEqual(buildRequestBody('goal-suggestions', { goalTitles: [null, ' g ', ''], lessonText: 'x' }), { lessonText: 'x', goalTitles: ['g'] })
	assert.deepEqual(Object.keys(buildRequestBody('questions', { lessonText: '', goalTitles: ['g'] })), ['lessonText', 'questionCount', 'language', 'goalTitles'])
	assert.deepEqual(Object.keys(buildRequestBody('outline', { lessonText: 'x' })), ['goalTitles', 'language', 'lessonText'])
	assert.equal(canRun('questions', null), false)
	assert.equal(canRun('simplify', { lessonText: 3 }), false)
	assert.equal(canRun('outline', { goalTitles: 'g' }), false)
	assert.equal(canRun('goal-suggestions', { lessonText: '', goalTitles: ['g'] }), false)
})
