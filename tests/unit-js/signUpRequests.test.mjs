// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Aanmeldverzoeken (learniq/LqTlAanmeldverzoeken): the self sign-ups grouped
// under "Wachten op besluit", each pending row with an Acties menu that
// approves or rejects it with a reason.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { canDecline, declineBody } from '../../src/utils/signUpDecision.js'

const people = JSON.parse(readFileSync(new URL('../../src/manifest.d/people.json', import.meta.url), 'utf8'))
const page = people.pages.find((p) => p.id === 'SignUpRequests')

test('the page lists the self sign-ups, waiting ones first, with counts', () => {
	assert.deepEqual(page.config.filter, { source: 'self' })
	const [waiting, all] = page.config.quickFilters
	assert.equal(waiting.label, 'Waiting for a decision')
	assert.deepEqual(waiting.filter, { lifecycle: 'pending' })
	assert.equal(waiting.default, true)
	assert.equal(all.label, 'All sign-ups')
	assert.ok(waiting.showCount && all.showCount)
})

test('a pending row approves through the transition and rejects through the dialog', () => {
	const [approve, reject] = page.config.actions
	assert.equal(approve.url, '/apps/openregister/api/objects/@objectId/transition')
	assert.deepEqual(approve.payload, { action: 'approve' })
	assert.equal(reject.target, 'DeclineSignUpDialog')
	for (const action of [approve, reject]) {
		assert.deepEqual(action.visibleWhen, { field: 'lifecycle', op: 'eq', value: 'pending' })
	}
})

test('a rejection carries the trimmed reason as the decline input', () => {
	assert.deepEqual(declineBody('  vol  '), { action: 'decline', data: { declineReason: 'vol' } })
	assert.equal(canDecline('   '), false)
	assert.equal(canDecline(null), false)
	assert.equal(canDecline('De cursus is vol'), true)
})
