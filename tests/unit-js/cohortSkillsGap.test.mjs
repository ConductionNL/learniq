// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The skills-gap page has a cohort view: each required competency with the
// number of the cohort's learners below the framework's target level.
// Run by `npm run test:js-unit`.
//
// @spec openspec/changes/capability-rows-say-what-they-do/specs/competency/spec.md#scenario-a-teacher-opens-the-skills-gap-of-a-class

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { cohortSkillsGap, reachesTarget } from '../../src/utils/cohortSkillsGap.js'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')

const framework = {
	id: 'fw1',
	targetLevelId: 'competent',
	proficiencyLevels: [
		{ levelId: 'nog-niet', order: 1 },
		{ levelId: 'competent', order: 2 },
		{ levelId: 'excellent', order: 3 },
	],
}
const learnerIds = Array.from({ length: 27 }, (_, i) => `learner-${i + 1}`)
const competencies = [
	{ id: 'arg', code: 'ARG', title: 'Argumenteren', frameworkId: 'fw1' },
	{ id: 'pres', code: 'PRES', title: 'Presenteren', frameworkId: 'fw1' },
]

// Argumenteren: 12 learners at or above target, 5 below it, 10 with no row -> 15 below.
// Presenteren: all 27 at target.
const attainments = [
	...learnerIds.slice(0, 10).map((l) => ({ learnerId: l, competencyId: 'arg', frameworkId: 'fw1', proficiencyLevelId: 'competent' })),
	...learnerIds.slice(10, 12).map((l) => ({ learnerId: l, competencyId: 'arg', frameworkId: 'fw1', proficiencyLevelId: 'excellent' })),
	...learnerIds.slice(12, 17).map((l) => ({ learnerId: l, competencyId: 'arg', frameworkId: 'fw1', proficiencyLevelId: 'nog-niet' })),
	...learnerIds.map((l) => ({ learnerId: l, competencyId: 'pres', frameworkId: 'fw1', proficiencyLevelId: 'competent' })),
	{ learnerId: 'someone-else', competencyId: 'arg', frameworkId: 'fw1', proficiencyLevelId: 'excellent' },
]

test('Argumenteren is listed with 15 of 27 learners below the target level, thinnest first', () => {
	const rows = cohortSkillsGap({ learnerIds, competencies, attainments, frameworks: [framework] })

	assert.deepEqual(rows, [
		{ id: 'arg', code: 'ARG', title: 'Argumenteren', below: 15, total: 27 },
		{ id: 'pres', code: 'PRES', title: 'Presenteren', below: 0, total: 27 },
	])
})

test('without a target level any recorded level counts as reached', () => {
	const noTarget = { ...framework, targetLevelId: undefined }

	assert.equal(reachesTarget(noTarget, 'nog-niet'), true)
	assert.equal(reachesTarget(noTarget, null), false)
	const rows = cohortSkillsGap({ learnerIds, competencies: [competencies[0]], attainments, frameworks: [noTarget] })
	assert.equal(rows[0].below, 10)
})

test('a level not in the framework does not reach a set target', () => {
	assert.equal(reachesTarget(framework, 'unknown'), false)
	assert.equal(reachesTarget(framework, 'excellent'), true)
})

test('a learner listed twice counts once', () => {
	const rows = cohortSkillsGap({ learnerIds: ['a', 'a', 'b'], competencies: [competencies[0]], attainments: [], frameworks: [framework] })

	assert.deepEqual(rows[0], { id: 'arg', code: 'ARG', title: 'Argumenteren', below: 2, total: 2 })
})

test('the skills-gap page opens a cohort view from ?cohortId=', () => {
	const view = readFileSync(resolve(root, 'src/views/SkillsGapDashboard.vue'), 'utf8')

	assert.match(view, /query\?\.cohortId/)
	assert.match(view, /cohortSkillsGap\(/)
	assert.match(view, /'{below} of {total} learners below the target level'/)
})
