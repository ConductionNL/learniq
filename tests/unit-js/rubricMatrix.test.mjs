// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The marking rubric (board LqInleveringNakijken) is drawn by CnChoiceMatrix
// with one pick per criterion; the picks come back as the selected levels the
// Submission stores as rubricScores.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { levelsFromPicks, rubricPicks, rubricRows } from '../../src/utils/rubricMatrix.js'

const RUBRIC = {
	criteria: [
		{
			criterionId: 'structure',
			label: 'Opbouw',
			weight: 1,
			levels: [
				{ levelId: 'weak', label: 'Onvoldoende', points: 1 },
				{ levelId: 'good', label: 'Goed', description: 'Inleiding, kern en slot', points: 3 },
			],
		},
		{ criterionId: 'sources', label: 'Bronnen', weight: 2, levels: [{ levelId: 'some', label: 'Enkele', points: 2 }] },
	],
}

test('each criterion is a row whose levels are its options', () => {
	const rows = rubricRows(RUBRIC)
	assert.equal(rows.length, 2)
	assert.deepEqual(rows[0], {
		id: 'structure',
		label: 'Opbouw',
		weight: 1,
		options: [
			{ key: 'weak', label: 'Onvoldoende', description: undefined, points: 1 },
			{ key: 'good', label: 'Goed', description: 'Inleiding, kern en slot', points: 3 },
		],
	})
})

test('no rubric draws no rows', () => {
	assert.deepEqual(rubricRows(null), [])
})

test('the selected levels show as picks', () => {
	assert.deepEqual(rubricPicks({ structure: { levelId: 'good', points: 3 } }), { structure: 'good' })
})

test('picks come back as levels with their points', () => {
	assert.deepEqual(levelsFromPicks(RUBRIC, { structure: 'good', sources: 'some' }), {
		structure: { levelId: 'good', points: 3 },
		sources: { levelId: 'some', points: 2 },
	})
})

test('a pick of a level the rubric does not have is dropped', () => {
	assert.deepEqual(levelsFromPicks(RUBRIC, { structure: 'gone' }), {})
})
