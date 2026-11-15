// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The learning plans panel on a learner's profile lists the plans made for
// that learner. LearningPlan.learnerId holds the Nextcloud user id, so the
// panel filters on the profile's ncUserId, not on the profile's own uuid.
//
// @spec openspec/changes/capability-rows-say-what-they-do/specs/learning-plan/spec.md#requirement-a-coordinator-writes-a-plan-of-a-chosen-kind-for-a-named-learner-and-it-shows-on-that-learner

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const people = JSON.parse(readFileSync(new URL('../../src/manifest.d/people.json', import.meta.url), 'utf8'))
const register = JSON.parse(readFileSync(new URL('../../lib/Settings/learniq_register.json', import.meta.url), 'utf8'))

/**
 * Every object in a JSON tree.
 *
 * @param {unknown} node The tree.
 * @yield {object} Each object.
 */
function * objects(node) {
	if (Array.isArray(node)) {
		for (const item of node) yield * objects(item)
	} else if (node !== null && typeof node === 'object') {
		yield node
		for (const value of Object.values(node)) yield * objects(value)
	}
}

const panel = [...objects(people)].find((o) => o.id === 'lprof-plans')

test('the plans panel reads learning plans by the learner\'s Nextcloud user id', () => {
	assert.ok(panel, 'lprof-plans is declared')
	assert.equal(panel.content.schema, 'learning-plan')
	assert.deepEqual(panel.content.filter, { learnerId: '@object.ncUserId' })
	assert.deepEqual(panel.content.viewAllQuery, { learnerId: '@object.ncUserId' })
})

test('the two ids it joins are the ones the register stores', () => {
	const schemas = register.components.schemas
	assert.match(schemas.LearningPlan.properties.learnerId.description, /NC user ID/)
	assert.ok(schemas.LearnerProfile.properties.ncUserId, 'a learner profile carries ncUserId')
})

test('a row shows kind, period and next review, and opens the plan', () => {
	assert.deepEqual(panel.content.columns.map((c) => c.key), ['kind', 'period', 'nextReviewAt'])
	assert.equal(panel.content.rowRoute, 'LearningPlanDetail')
})
