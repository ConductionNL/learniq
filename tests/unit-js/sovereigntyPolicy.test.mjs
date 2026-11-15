// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The AI-processing disclosure page reads the SovereigntyPolicy singleton into
// its state, seeds the edit form from it and saves the form back. These pin
// the shapes LearniqAiProcessingDisclosure.vue built inline before the logic
// moved into src/utils/sovereigntyPolicy.js.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	firstRecord,
	policyFormFrom,
	policyFromRecord,
	policyFromSaved,
	policyPayload,
	recordId,
} from '../../src/utils/sovereigntyPolicy.js'

test('firstRecord takes the first element of a non-empty array only', () => {
	assert.deepEqual(firstRecord([{ id: 'a' }, { id: 'b' }]), { id: 'a' })
	assert.equal(firstRecord([]), null)
	assert.equal(firstRecord(null), null)
	assert.equal(firstRecord({ results: [1] }), null)
})

test('recordId prefers the own id, then @self.id, else null', () => {
	assert.equal(recordId({ id: 'x', '@self': { id: 'y' } }), 'x')
	assert.equal(recordId({ '@self': { id: 'y' } }), 'y')
	assert.equal(recordId({ '@self': {} }), null)
	assert.equal(recordId({}), null)
	assert.equal(recordId({ id: 0 }), 0)
})

test('policyFromRecord defaults the tier and nulls empty fields', () => {
	assert.deepEqual(policyFromRecord({ id: 'p1', policy: 'on-premises-only', rationale: 'r', setBy: 'u', setAt: 't' }), {
		id: 'p1', policy: 'on-premises-only', rationale: 'r', setBy: 'u', setAt: 't',
	})
	assert.deepEqual(policyFromRecord({ '@self': { id: 's1' }, policy: '', rationale: '' }), {
		id: 's1', policy: 'eu-hosted-allowed', rationale: null, setBy: null, setAt: null,
	})
})

test('policyFormFrom carries the tier and an empty-string rationale', () => {
	assert.deepEqual(policyFormFrom({ policy: 'x', rationale: null }), { policy: 'x', rationale: '' })
	assert.deepEqual(policyFormFrom({ policy: 'x', rationale: 'why' }), { policy: 'x', rationale: 'why' })
})

test('policyPayload adds the id only when there is one', () => {
	assert.deepEqual(policyPayload({ policy: 'x', rationale: '' }, null), { policy: 'x', rationale: null })
	assert.deepEqual(policyPayload({ policy: 'x', rationale: 'r' }, 'p1'), { policy: 'x', rationale: 'r', id: 'p1' })
	assert.equal('id' in policyPayload({ policy: 'x', rationale: '' }, ''), false)
})

test('policyFromSaved falls back to what was sent', () => {
	assert.deepEqual(policyFromSaved({}, { id: null, policy: 'x', rationale: null }), {
		id: null, policy: 'x', rationale: null, setBy: null, setAt: null,
	})
	assert.deepEqual(policyFromSaved({ id: 'n', policy: 'y', rationale: '', setBy: 'u', setAt: 't' }, { id: 'o', policy: 'x', rationale: 'r' }), {
		id: 'n', policy: 'y', rationale: '', setBy: 'u', setAt: 't',
	})
	assert.deepEqual(Object.keys(policyFromSaved({}, { id: 1, policy: 'x', rationale: 'r' })), ['id', 'policy', 'rationale', 'setBy', 'setAt'])
})
