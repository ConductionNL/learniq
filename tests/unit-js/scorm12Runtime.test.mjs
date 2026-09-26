// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Unit tests for the SCORM 1.2 window.API shim (lesson-player-runtime,
// finding 5.6). Run via `node --test tests/unit-js/` (package.json's
// `test:js-unit` script), same pattern as tests/unit-js/courseOrder.test.mjs.

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { buildScorm12CompletionStatement, createScorm12Api } from '../../src/utils/scorm12Runtime.js'

test('buildScorm12CompletionStatement: completed/passed map to the XapiCompletionHandler-recognised verbs', () => {
	const completed = buildScorm12CompletionStatement({
		lessonStatus: 'completed',
		actorAccountName: 'learner-1',
		activityId: 'https://learniq.example/lessons/l1',
	})
	assert.equal(completed.verb.id, 'http://adlnet.gov/expapi/verbs/completed')
	assert.equal(completed.result.completion, true)
	assert.equal(completed.result.success, true)

	const passed = buildScorm12CompletionStatement({
		lessonStatus: 'passed',
		actorAccountName: 'learner-1',
		activityId: 'https://learniq.example/lessons/l1',
	})
	assert.equal(passed.verb.id, 'http://adlnet.gov/expapi/verbs/passed')
})

test('buildScorm12CompletionStatement: failed maps to completed with success:false, never a third unrecognised verb', () => {
	const failed = buildScorm12CompletionStatement({
		lessonStatus: 'failed',
		actorAccountName: 'learner-1',
		activityId: 'https://learniq.example/lessons/l1',
	})
	assert.equal(failed.verb.id, 'http://adlnet.gov/expapi/verbs/completed')
	assert.equal(failed.result.success, false)
})

test('buildScorm12CompletionStatement: a non-terminal status throws rather than building a false statement', () => {
	assert.throws(() => {
		buildScorm12CompletionStatement({
			lessonStatus: 'incomplete',
			actorAccountName: 'learner-1',
			activityId: 'x',
		})
	}, /not a terminal SCORM 1.2 lesson_status/)
})

test('buildScorm12CompletionStatement: carries a score when provided, omits it when not', () => {
	const withScore = buildScorm12CompletionStatement({
		lessonStatus: 'completed',
		scoreRaw: 87,
		actorAccountName: 'learner-1',
		activityId: 'x',
	})
	assert.equal(withScore.result.score.raw, 87)

	const withoutScore = buildScorm12CompletionStatement({
		lessonStatus: 'completed',
		actorAccountName: 'learner-1',
		activityId: 'x',
	})
	assert.equal('score' in withoutScore.result, false)
})

test('buildScorm12CompletionStatement: required xAPI fields are present per the XapiStatement schema', () => {
	const statement = buildScorm12CompletionStatement({
		lessonStatus: 'completed',
		actorAccountName: 'learner-1',
		activityId: 'x',
	})
	for (const field of ['actor', 'verb', 'object', 'stored', 'timestamp', 'version']) {
		assert.ok(field in statement, `statement MUST carry ${field}`)
	}
	assert.equal(statement.version, '1.0.3')
})

test('createScorm12Api: exposes all 8 required SCORM 1.2 functions', () => {
	const api = createScorm12Api()
	for (const fn of [
		'LMSInitialize',
		'LMSFinish',
		'LMSGetValue',
		'LMSSetValue',
		'LMSCommit',
		'LMSGetLastError',
		'LMSGetErrorString',
		'LMSGetDiagnostic',
	]) {
		assert.equal(typeof api[fn], 'function', `${fn} MUST be a function`)
	}
})

test('createScorm12Api: LMSGetValue/LMSSetValue require initialization first', () => {
	const api = createScorm12Api()
	assert.equal(api.LMSGetValue('cmi.core.lesson_status'), '')
	assert.equal(api.LMSGetLastError(), '301')

	assert.equal(api.LMSSetValue('cmi.core.lesson_status', 'completed'), 'false')
	assert.equal(api.LMSGetLastError(), '301')
})

test('createScorm12Api: a full init/set/get/commit/finish cycle works', () => {
	const api = createScorm12Api()
	assert.equal(api.LMSInitialize(), 'true')
	assert.equal(api.LMSSetValue('cmi.core.score.raw', '87'), 'true')
	assert.equal(api.LMSGetValue('cmi.core.score.raw'), '87')
	assert.equal(api.LMSCommit(), 'true')
	assert.equal(api.LMSSetValue('cmi.core.lesson_status', 'completed'), 'true')
	assert.equal(api.LMSFinish(), 'true')
})

test('createScorm12Api: a second LMSInitialize call fails', () => {
	const api = createScorm12Api()
	api.LMSInitialize()
	assert.equal(api.LMSInitialize(), 'false')
	assert.equal(api.LMSGetLastError(), '101')
})

test('createScorm12Api: onCompletion fires exactly once, on the earliest terminal-status signal', () => {
	let fireCount = 0
	let lastStatement = null
	const api = createScorm12Api({
		onCompletion: (statement) => {
			fireCount += 1
			lastStatement = statement
		},
		actorAccountName: 'learner-1',
		activityId: 'https://learniq.example/lessons/l1',
	})
	api.LMSInitialize()
	api.LMSSetValue('cmi.core.score.raw', '92')
	// Setting the terminal status itself fires completion immediately —
	// design.md Decision 3 ("record early, not late").
	api.LMSSetValue('cmi.core.lesson_status', 'passed')
	assert.equal(fireCount, 1)
	assert.equal(lastStatement.verb.id, 'http://adlnet.gov/expapi/verbs/passed')
	assert.equal(lastStatement.result.score.raw, 92)

	// LMSFinish afterwards MUST NOT fire a second completion.
	api.LMSFinish()
	assert.equal(fireCount, 1)
})

test('createScorm12Api: a non-terminal status never fires onCompletion', () => {
	let fireCount = 0
	const api = createScorm12Api({ onCompletion: () => { fireCount += 1 } })
	api.LMSInitialize()
	api.LMSSetValue('cmi.core.lesson_status', 'incomplete')
	api.LMSCommit()
	api.LMSFinish()
	assert.equal(fireCount, 0)
})

test('createScorm12Api: LMSGetErrorString/LMSGetDiagnostic describe known codes', () => {
	const api = createScorm12Api()
	assert.equal(api.LMSGetErrorString('0'), 'No error')
	assert.equal(api.LMSGetErrorString('301'), 'Not initialized')
	assert.equal(api.LMSGetDiagnostic('301'), 'Not initialized')
})
