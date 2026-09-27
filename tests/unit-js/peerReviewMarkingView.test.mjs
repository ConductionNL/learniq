// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// peer-review-projection-guard: the marking screen reads the work from the
// server's projection and never asks for the Submission itself, so the
// reviewer's path can not reveal the author of a double-blind review. Read as
// text: plain `node --test` cannot load a .vue file.
//
// @spec openspec/changes/peer-review-projection-guard/specs/assignments/spec.md#requirement-both-sides-of-peer-review-anonymity-are-server-enforced-projections

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const view = readFileSync(
	resolve(dirname(fileURLToPath(import.meta.url)), '../../src/views/PeerReviewMarkingView.vue'),
	'utf8',
)
const code = view.slice(view.indexOf('<script>'))

test('the marking view never fetches the Submission', () => {
	assert.doesNotMatch(code, /objects\/learniq\/[Ss]ubmission/)
})

test('the marking view reads the work from the projection endpoint', () => {
	assert.match(code, /\/apps\/learniq\/api\/peer-review\/\{id\}\/work'/)
	assert.match(code, /\/apps\/learniq\/api\/peer-review\/\{id\}\/work\/files\/\{fileId\}'/)
})

test('the authors shown come from the projection', () => {
	assert.match(code, /this\.work\?\.authorIds/)
})
