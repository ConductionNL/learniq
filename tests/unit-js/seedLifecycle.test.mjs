// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

// The e2e seeder reaches a fixture's state through the register's own
// transitions, because OpenRegister refuses a create in a non-initial state.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { lifecycleOf, lifecyclePath } from '../e2e/seed-lifecycle.mjs'

const register = JSON.parse(
	readFileSync(new URL('../../lib/Settings/learniq_register.json', import.meta.url), 'utf8'),
)

test('the states the seeder asks for are reachable from each initial state', () => {
	const cases = [
		['lesson', 'published', ['publish']],
		['grade-entry', 'published', ['publish']],
		['report-period', 'composed', ['compose']],
		['group-plan', 'active', ['activate']],
	]
	for (const [slug, wanted, path] of cases) {
		const lifecycle = lifecycleOf(register, slug)
		assert.ok(lifecycle, `${slug} declares a lifecycle`)
		assert.deepEqual(lifecyclePath(lifecycle, lifecycle.initial, wanted), path, slug)
	}
})

test('a state two steps away is reached through the one between', () => {
	const lifecycle = lifecycleOf(register, 'lesson')
	assert.deepEqual(lifecyclePath(lifecycle, 'draft', 'retired'), ['publish', 'retire'])
})

test('the initial state needs no transition and an unknown one has no path', () => {
	const lifecycle = lifecycleOf(register, 'lesson')
	assert.deepEqual(lifecyclePath(lifecycle, 'draft', 'draft'), [])
	assert.equal(lifecyclePath(lifecycle, 'draft', 'no-such-state'), null)
	assert.equal(lifecycleOf(register, 'no-such-schema'), null)
})
