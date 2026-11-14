// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Only the exam board gets the decision forms on an exam-board case.
//
// @spec openspec/specs/exam-board/spec.md#requirement-fraudcase-read-access-is-restricted-hearing-decision-internals-are-withheld-by-the-server

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { canWithdrawExemption, isExamBoard } from '../../src/utils/examBoard.js'

test('the exam board and admins are the exam board', () => {
	assert.equal(isExamBoard(['admin', 'student', 'exam-board']), true)
	assert.equal(isExamBoard(['admin', 'teacher', 'student', 'exam-board']), true)
})

test('a teacher, a learner and a missing state are not', () => {
	assert.equal(isExamBoard(['teacher', 'student']), false)
	assert.equal(isExamBoard(['student']), false)
	assert.equal(isExamBoard(['admin', 'student']), false)
	assert.equal(isExamBoard(undefined), false)
})

test('staff and the exam board may withdraw an exemption request, a learner may not', () => {
	assert.equal(canWithdrawExemption(['teacher', 'student']), true)
	assert.equal(canWithdrawExemption(['admin', 'student', 'exam-board']), true)
	assert.equal(canWithdrawExemption(['student']), false)
})

test('the dossier gates its decision forms and internals on the exam board, not on isAdmin', () => {
	const source = readFileSync(new URL('../../src/views/ExamCaseDossierView.vue', import.meta.url), 'utf8')
	assert.doesNotMatch(source, /isAdmin/)
	assert.match(source, /isExamBoard\(/)
	for (const action of ['startAssessment', 'grantExemption', 'rejectExemption', 'scheduleHearing', 'holdHearing', 'decideFraudCase', 'dismissFraudCase']) {
		const button = source.indexOf(`@click="${action}"`)
		assert.ok(button > 0, action)
		const blocks = [...source.slice(0, button).matchAll(/<div\s+v-if="([^"]*)"/g)]
		const block = blocks.at(-1)
		assert.match(block[1], /^\s*canDecide\s*&&/, `${action} is not gated on canDecide`)
		assert.ok(!source.slice(block.index, button).includes('</div>'), `${action} sits outside its gated block`)
	}
})
