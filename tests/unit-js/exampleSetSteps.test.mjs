// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// One removal step per loaded example set (segment-tidy, D34).
// Run via `node --test tests/unit-js/` (package.json's `test:js-unit`).

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import {
	applyExampleSetRemovalSteps,
	REMOVE_STEP_ID,
} from '../../src/utils/exampleSetSteps.js'

function manifest() {
	return JSON.parse(
		readFileSync(new URL('../../src/manifest.json', import.meta.url), 'utf8'),
	)
}
function echo(text, vars = {}) {
	return text.replace(/\{(\w+)\}/g, (_, key) => vars[key] ?? '')
}

test('two loaded sets become two removal steps, each with its own action', () => {
	const built = applyExampleSetRemovalSteps(
		manifest(),
		[
			{ id: 'corporate', label: 'Company' },
			{ id: 'training', label: 'Training institute' },
		],
		echo,
	)
	const ids = built.setup.steps.map((s) => s.id)
	assert.deepEqual(ids, [
		'welcome',
		'example-set',
		'load-example-set',
		'segment',
		'remove-example-set-corporate',
		'remove-example-set-training',
		'done',
	])
	const corporate = built.setup.steps.find(
		(s) => s.id === 'remove-example-set-corporate',
	)
	assert.equal(corporate.type, 'run-action')
	assert.equal(corporate.action, 'remove-example-set-corporate')
	assert.equal(corporate.required, false)
	assert.equal(corporate.title, 'Remove the example set "Company"')
})

test('with nothing loaded the single removal step stays', () => {
	for (const loaded of [[], null, undefined, 'x']) {
		const built = applyExampleSetRemovalSteps(manifest(), loaded, echo)
		assert.equal(
			built.setup.steps.filter((s) => s.id.startsWith(REMOVE_STEP_ID)).length,
			1,
		)
		assert.ok(built.setup.steps.some((s) => s.id === REMOVE_STEP_ID))
	}
})

test('an id the action route would refuse is skipped', () => {
	const built = applyExampleSetRemovalSteps(
		manifest(),
		[
			{ id: '../x', label: 'Bad' },
			{ id: 'po', label: 'Primary school' },
			{ id: 'vo' },
		],
		echo,
	)
	assert.deepEqual(
		built.setup.steps
			.filter((s) => s.id.startsWith(REMOVE_STEP_ID))
			.map((s) => s.id),
		['remove-example-set-po'],
	)
})

test('the label is translated before it goes into the title', () => {
	const nl = { Company: 'Bedrijf' }
	const translate = (text, vars) => echo(nl[text] ?? text, vars)
	const built = applyExampleSetRemovalSteps(
		manifest(),
		[{ id: 'corporate', label: 'Company' }],
		translate,
	)
	assert.equal(
		built.setup.steps.find((s) => s.id === 'remove-example-set-corporate').title,
		'Remove the example set "Bedrijf"',
	)
})

test('both new strings have a Dutch catalogue value', () => {
	const catalogue = JSON.parse(
		readFileSync(new URL('../../l10n/nl.json', import.meta.url), 'utf8'),
	).translations
	for (const key of [
		'Remove the example set "{set}"',
		'This only runs when you click the button. It moves this example set to the trash and keeps everything you made yourself.',
	]) {
		assert.ok(catalogue[key], key)
	}
})
