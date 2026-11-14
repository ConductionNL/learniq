// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The teacher scope given rows in each id shape, cohorts and programmes with
// missing lists, and filters with missing values.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { appendFilter, filterMatchesNothing, teacherScope } from '../../src/utils/teacherScope.js'

test('cohort ids come from id, uuid or @self; a cohort without teachers or an id is left out', () => {
	const scope = teacherScope({
		userId: 'jan',
		cohorts: [
			{ id: 'c1', teacherIds: ['jan'] },
			{ uuid: 'c2', teacherIds: ['jan'] },
			{ '@self': { id: 'c3' }, teacherIds: ['jan'] },
			{ teacherIds: ['jan'] },
			{ id: 'c5' },
			null,
		],
	})
	assert.deepEqual(scope.cohortIds, ['c1', 'c2', 'c3'])
	assert.deepEqual(scope.learnerIds, [])
})

test('a programme without course ids adds no course; empty course ids are skipped', () => {
	const scope = teacherScope({
		userId: 'jan',
		cohorts: [{ id: 'c1', teacherIds: ['jan'], programmeId: 'p1', courseId: 'k0' }],
		programmes: [{ id: 'p1' }, { uuid: 'p1', courseIds: ['k1', '', null] }, { id: 'p2', courseIds: ['k9'] }],
	})
	assert.deepEqual(scope.programmeIds, ['p1'])
	assert.deepEqual(scope.courseIds, ['k0', 'k1'])
})

test('a scope from no rows is empty', () => {
	assert.deepEqual(teacherScope({ userId: 'jan' }), {
		cohortIds: [],
		courseIds: [],
		programmeIds: [],
		learnerIds: [],
	})
})

test('no filter can still match rows', () => {
	assert.equal(filterMatchesNothing(null), false)
	assert.equal(filterMatchesNothing(undefined), false)
})

test('a missing filter appends nothing, and null or undefined values are skipped', () => {
	assert.equal(appendFilter(new URLSearchParams(), null).toString(), '')
	const params = appendFilter(new URLSearchParams(), { a: null, b: undefined, c: 0, d: ['x', 1] })
	assert.equal(params.toString(), 'c=0&d%5B%5D=x&d%5B%5D=1')
})
