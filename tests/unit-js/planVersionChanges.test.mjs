// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// A learning plan shows what changed against the version it replaces: the
// goals and support measures added, removed or changed, and nothing else.
// Run by `npm run test:js-unit`.
//
// @spec openspec/changes/capability-rows-say-what-they-do/specs/learning-plan/spec.md#scenario-a-coordinator-sees-what-changed-between-two-versions

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { planVersionChanges } from '../../src/utils/planVersionChanges.js'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')

const version1 = {
	version: 1,
	goals: [
		{ goalId: 'g1', description: 'Reads a short text aloud', domain: 'taal', targetDate: '2026-12-01' },
		{ goalId: 'g2', description: 'Adds up to 100', domain: 'rekenen' },
	],
	supportMeasures: [
		{ measureId: 'm1', description: 'Extra reading time', responsibleId: 'jan' },
	],
}

test('a changed goal and an added support measure are shown, and nothing else', () => {
	const version2 = {
		version: 2,
		supersedesId: 'plan-v1',
		goals: [
			{ goalId: 'g1', description: 'Reads a short text aloud', domain: 'taal', targetDate: '2026-12-01' },
			{ goalId: 'g2', description: 'Adds and subtracts up to 100', domain: 'rekenen' },
		],
		supportMeasures: [
			{ measureId: 'm1', description: 'Extra reading time', responsibleId: 'jan' },
			{ measureId: 'm2', description: 'Weekly session with the remedial teacher' },
		],
	}

	assert.deepEqual(planVersionChanges(version2, version1), [
		{ kind: 'goal', change: 'changed', id: 'g2', description: 'Adds and subtracts up to 100', before: 'Adds up to 100' },
		{ kind: 'measure', change: 'added', id: 'm2', description: 'Weekly session with the remedial teacher' },
	])
})

test('a removed goal is listed after the current items, and a field other than the description counts as a change', () => {
	const version2 = {
		goals: [{ goalId: 'g1', description: 'Reads a short text aloud', domain: 'taal', targetDate: '2027-02-01' }],
		supportMeasures: [{ responsibleId: 'jan', description: 'Extra reading time', measureId: 'm1' }],
	}

	assert.deepEqual(planVersionChanges(version2, version1), [
		{ kind: 'goal', change: 'changed', id: 'g1', description: 'Reads a short text aloud', before: 'Reads a short text aloud' },
		{ kind: 'goal', change: 'removed', id: 'g2', description: 'Adds up to 100' },
	])
})

test('identical versions, a missing earlier version and items without an id give what can be told', () => {
	assert.deepEqual(planVersionChanges(version1, version1), [])
	assert.deepEqual(planVersionChanges({ goals: [{ description: 'no id' }] }, null), [])
	assert.deepEqual(planVersionChanges({ goals: [{ goalId: 'g9', description: 'New' }] }, null), [
		{ kind: 'goal', change: 'added', id: 'g9', description: 'New' },
	])
})

test('the learning plan page shows the changes block, fed by the plan being read', () => {
	const manifest = JSON.parse(readFileSync(resolve(root, 'src/manifest.d/learning.json'), 'utf8'))
	const page = manifest.pages.find((p) => p.id === 'LearningPlanDetail')
	const block = (page.config.bodyWidgets ?? []).find((w) => w.component === 'LearningPlanVersionChanges')
	assert.ok(block, 'LearningPlanDetail declares the LearningPlanVersionChanges body section')

	const registry = readFileSync(resolve(root, 'src/registry.js'), 'utf8')
	assert.match(registry, /LearningPlanVersionChanges: \{\s*kind: 'section',/)
})
