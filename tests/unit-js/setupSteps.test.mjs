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

test('welcome, then the example-set offer, then segment and done (gate 100)', () => {
	assert.deepEqual(
		steps.map((s) => s.id),
		['welcome', 'example-set', 'segment', 'done'],
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
	// Each card loads itself (wizard-dataset-card-load); no load step.
	assert.equal(step('example-set').loadAction, 'load-example-set')
	assert.equal(step('load-example-set'), undefined)
})

test('the segment is pre-selected from the example set picked earlier', () => {
	const segment = step('segment')
	assert.equal(segment.suggestFrom, 'example_profile')
	assert.deepEqual(
		segment.suggestMap,
		Object.fromEntries(SEGMENTS.map((code) => [code, code])),
	)
})

test('the segment step says the menus follow the answer', () => {
	assert.match(step('segment').body, /menus that fit/)
	const nl = JSON.parse(
		readFileSync(new URL('../../l10n/nl.json', import.meta.url), 'utf8'),
	).translations
	assert.ok(
		nl[step('segment').body],
		'the segment step body has a Dutch translation',
	)
})

test('no step is required, so setup never gates the app', () => {
	for (const s of steps) {
		assert.notEqual(s.required, true, `${s.id} must stay optional`)
	}
})

// wizard-drops-the-removal-step: the wizard offers example data and does not
// remove it. Removal is an administrator's act outside the first-run flow.
test('the wizard has no removal step', () => {
	assert.equal(step('remove-example-set'), undefined)
	assert.ok(
		steps.every((s) => !String(s.id).startsWith('remove-')),
		'no step removes data',
	)
	const nl = JSON.parse(
		readFileSync(new URL('../../l10n/nl.json', import.meta.url), 'utf8'),
	).translations
	assert.ok(
		nl[step('example-set').body],
		'the example-set body has a Dutch translation',
	)
})
