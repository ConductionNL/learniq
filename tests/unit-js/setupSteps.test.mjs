// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The setup wizard's declared steps (segment-wizard-choice). The wizard is the
// shared CnSetupWizard, so what this app owns is the declaration; these tests
// pin the parts the example-set contract depends on.
// Run via `node --test tests/unit-js/` (package.json's `test:js-unit`).

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { SEGMENTS } from '../../src/utils/workspaceRuntime.js'

const manifest = JSON.parse(
	readFileSync(new URL('../../src/manifest.json', import.meta.url), 'utf8'),
)
const steps = manifest.setup.steps
const step = (id) => steps.find((s) => s.id === id)

test('welcome, then the example-set offer, then load, segment and done (gate 100)', () => {
	assert.deepEqual(
		steps.map((s) => s.id),
		['welcome', 'example-set', 'load-example-set', 'segment', 'done'],
	)
	assert.equal(manifest.setup.version, 2)
})

test('both choices are single-select and read their options from the server', () => {
	assert.equal(step('example-set').optionsSource, 'profiles')
	assert.equal(step('example-set').configKey, 'example_profile')
	assert.equal(step('example-set').multiple, false)
	assert.equal(step('segment').optionsSource, 'segments')
	assert.equal(step('segment').configKey, 'segment')
	assert.equal(step('segment').multiple, false)
	assert.equal(step('load-example-set').action, 'load-example-set')
})

test('the segment is pre-selected from the example set picked earlier', () => {
	const segment = step('segment')
	assert.equal(segment.suggestFrom, 'example_profile')
	assert.deepEqual(
		segment.suggestMap,
		Object.fromEntries(SEGMENTS.map((code) => [code, code])),
	)
})

test('no step is required, so setup never gates the app', () => {
	for (const s of steps) {
		assert.notEqual(s.required, true, `${s.id} must stay optional`)
	}
})
