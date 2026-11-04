// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Mijn leren reads "{done} van {total} verplichte cursussen klaar", or
// "verplichte vakken" for a pupil, with the optional parts under
// "Keuzecursussen" or "Keuzevakken".
//
// @spec openspec/specs/programme-mandatory-parts/spec.md#requirement-progress-counts-mandatory-parts

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { programmeWords } from '../../src/utils/programmeWords.js'

const nl = JSON.parse(readFileSync(new URL('../../l10n/nl.json', import.meta.url), 'utf8')).translations

/**
 * The Dutch text of a catalogue key, with the placeholders filled.
 *
 * @param {string} key The catalogue key.
 * @param {object} values The placeholders.
 * @return {string} The text.
 */
function dutch(key, values = {}) {
	return nl[key].replace(/\{(\w+)\}/g, (all, name) => String(values[name] ?? all))
}

test('a course-taking learner reads courses', () => {
	for (const segment of ['corporate', 'training', 'mbo', 'he', '', undefined]) {
		const words = programmeWords(segment)
		assert.equal(dutch(words.progress, { done: 3, total: 5 }), '3 van 5 verplichte cursussen klaar', String(segment))
		assert.equal(dutch(words.optional), 'Keuzecursussen', String(segment))
	}
})

test('a pupil in primary or secondary school reads subjects', () => {
	for (const segment of ['po', 'vo']) {
		const words = programmeWords(segment)
		assert.equal(dutch(words.progress, { done: 5, total: 5 }), '5 van 5 verplichte vakken klaar', segment)
		assert.equal(dutch(words.optional), 'Keuzevakken', segment)
	}
})
