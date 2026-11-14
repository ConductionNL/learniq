// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The rollover is irreversible: the wizard asks first, starts it through the
// `execute` transition (a PATCH of the lifecycle never fired the handler),
// says what happened, and shows the cohort actions in words.
// Run via `node --test tests/unit-js/` (package.json's `test:js-unit`).

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { actionOptions, executeRollover, refusal, transitionUrl } from '../../src/utils/rolloverWizard.js'

const t = (text, vars = {}) => text.replace(/{(\w+)}/g, (_, k) => vars[k] ?? '')
const plan = { id: 'plan-1', fromAcademicYear: '2025/2026', toAcademicYear: '2026/2027' }

test('executing asks first and posts the execute transition', async () => {
	const posted = []
	const asked = []
	const outcome = await executeRollover(plan, {
		confirm: async (question) => {
			asked.push(question)
			return true
		},
		post: async (url, body) => {
			posted.push([url, body])
			return { data: {} }
		},
		t,
	})
	assert.match(asked[0], /2025\/2026 to 2026\/2027/)
	assert.deepEqual(posted, [['/apps/openregister/api/objects/plan-1/transition', { action: 'execute' }]])
	assert.equal(outcome.status, 'started')
	assert.ok(outcome.message)
})

test('a no or a closed question starts nothing', async () => {
	for (const confirm of [async () => false, async () => { throw new Error('closed') }]) {
		const outcome = await executeRollover(plan, { confirm, post: async () => assert.fail('posted after a no'), t })
		assert.equal(outcome.status, 'cancelled')
	}
})

test('a refused transition says the server reason', async () => {
	const outcome = await executeRollover(plan, {
		confirm: async () => true,
		post: async () => {
			const error = new Error('refused')
			error.response = { status: 409, data: { message: 'The preview no longer matches the mapping.' } }
			throw error
		},
		t,
	})
	assert.equal(outcome.status, 'failed')
	assert.equal(outcome.message, 'The preview no longer matches the mapping.')
	assert.equal(refusal(new Error('network'), 'Fallback'), 'Fallback')
})

test('the cohort actions are words, stored as the schema values', () => {
	assert.deepEqual(actionOptions(t), [
		{ id: 'promote', label: 'Promote' },
		{ id: 'graduate', label: 'Graduate' },
		{ id: 'dissolve', label: 'Dissolve' },
	])
	assert.equal(transitionUrl('a b'), '/apps/openregister/api/objects/a%20b/transition')
})

test('the wizard no longer patches the lifecycle and no longer only logs failures', () => {
	const source = readFileSync(new URL('../../src/views/RolloverWizard.vue', import.meta.url), 'utf8')
	assert.doesNotMatch(source, /lifecycle: 'executing'/)
	assert.doesNotMatch(source, /console\.error/)
	assert.match(source, /rolloverWizard\.js/)
})
