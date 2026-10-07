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

test('welcome, then the example-set offer, then segment, removal and done (gate 100)', () => {
	assert.deepEqual(
		steps.map((s) => s.id),
		[
			'welcome',
			'example-set',
			'segment',
			'remove-example-set',
			'done',
		],
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

// example-set-removal-in-wizard: the removal is a run-action step that the
// server always reports done, so CnSetupWizard never starts it on its own.
test('the removal step posts its own action and says it only runs on a click', () => {
	const remove = step('remove-example-set')
	assert.equal(remove.type, 'run-action')
	assert.equal(remove.action, 'remove-example-set')
	assert.match(remove.body, /only runs when you click/)
	const nl = JSON.parse(
		readFileSync(new URL('../../l10n/nl.json', import.meta.url), 'utf8'),
	).translations
	for (const text of [remove.title, remove.body, step('example-set').body]) {
		assert.ok(nl[text], `"${text}" has a Dutch translation`)
	}
})
