// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The learning record screen leaves who and when to the server. The export
// and share owner fields are stamped by LearningRecordOwnerStamp, and the
// export is generated and the share granted by
// LearningRecordCreatedTransition in the request that creates them. The
// screen reads the row back and reports a row that did not move, and a revoke
// the server refused.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
// @spec openspec/specs/portable-learning-record/spec.md#scenario-a-learner-creates-a-share-with-a-mandatory-expiry

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const VIEW = readFileSync(
	new URL('../../src/views/MyLearningRecordView.vue', import.meta.url),
	'utf8',
)
const NL = JSON.parse(
	readFileSync(new URL('../../l10n/nl.json', import.meta.url), 'utf8'),
).translations

test('the screen sends no owner, requester or tenant field', () => {
	for (const field of ['learnerId', 'learnerRef', 'requestedBy', 'requestedAt', 'sharedBy', 'tenant_id']) {
		assert.doesNotMatch(VIEW, new RegExp(`\\b${field}:`), field)
	}
})

test('the screen does not fire generate or grant itself', () => {
	assert.doesNotMatch(VIEW, /action: 'generate'/)
	assert.doesNotMatch(VIEW, /action: 'grant'/)
})

test('a row the server did not move is reported as a failure', () => {
	assert.match(VIEW, /generated\.lifecycle !== 'generated'/)
	assert.match(VIEW, /granted\?\.lifecycle !== 'active'/)
})

test('a refused revoke is reported, in English and Dutch', () => {
	assert.match(VIEW, /if \(!resp\.ok\) \{\s*throw new Error\(`LearningRecordShare revoke failed/)
	assert.ok(NL['Could not revoke the share. Please try again.'])
})
