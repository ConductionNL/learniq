// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The absence reports list offered "Add ExcuseRequest" (live run, 2026-10-04).
// CnIndexPage builds its Add button from the schema title, run through the
// app's catalogue, so a title written as the definition key reached the
// screen as a code. The title is now a sentence with a Dutch entry, and the
// button reads "Verzuimmelding toevoegen" on a Dutch instance.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

function readJson (rel) {
	return JSON.parse(readFileSync(new URL(rel, import.meta.url), 'utf8'))
}
const REGISTER = readJson('../../lib/Settings/learniq_register.json')
const NL = readJson('../../l10n/nl.json').translations
const EN = readJson('../../l10n/en.json').translations
const PEOPLE = readJson('../../src/manifest.d/people.json').pages

const schema = Object.values(REGISTER.components.schemas).find((s) => s.slug === 'excuse-request')

test('the excuse request list has an Add button to label', () => {
	const page = PEOPLE.find((p) => p.id === 'ExcuseRequests')
	assert.equal(page.type, 'index')
	assert.equal(page.config.schema, 'excuse-request')
	assert.equal(page.config.addLabel, undefined, 'the label comes from the schema title')
})

test('the excuse request title reads as words, not as the definition key', () => {
	assert.equal(/[a-z][A-Z]/.test(schema.title), false, `title "${schema.title}"`)
	assert.equal(schema.title, 'Excuse request')
})

test('the excuse request title has a Dutch and an English entry', () => {
	assert.equal(NL[schema.title], 'Verzuimmelding')
	assert.ok(EN[schema.title], `"${schema.title}" has no English entry`)
})
