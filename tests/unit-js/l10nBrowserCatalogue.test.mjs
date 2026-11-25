// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
//
// The English browser catalogue carries only what changes a string.
//
// Every page loads l10n/en.js for an English reader. An entry that maps a
// string to itself changes nothing, because a missing key already reads as
// the key, yet it cost 560 KB on every page (ADR-124 speed budget).
// scripts/build-l10n-js.js now drops those for English only; l10n/en.json
// stays complete.

import assert from 'node:assert/strict'
import fs from 'node:fs'
import { createRequire } from 'node:module'
import path from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const require = createRequire(import.meta.url)
const { browserEntries } = require('../../scripts/build-l10n-js.js')
const L10N = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../l10n')

test('English drops the entries that map a string to itself', () => {
	assert.deepEqual(
		browserEntries('en', { Save: 'Save', 'learniq.title': 'Learniq' }),
		{ 'learniq.title': 'Learniq' },
	)
})

test('a regional English drops them too', () => {
	assert.deepEqual(browserEntries('en_GB', { Save: 'Save' }), {})
})

test('another language keeps every entry, cognates included', () => {
	assert.deepEqual(
		browserEntries('nl', { Save: 'Opslaan', Status: 'Status' }),
		{ Save: 'Opslaan', Status: 'Status' },
	)
})

test('the shipped en.js stays small and en.json complete', () => {
	const enJson = JSON.parse(fs.readFileSync(path.join(L10N, 'en.json'), 'utf8')).translations
	assert.ok(fs.statSync(path.join(L10N, 'en.js')).size < 32 * 1024)
	assert.ok(Object.keys(enJson).length > 5000)
})
