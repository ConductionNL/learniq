// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Unit tests for the global-search query builder + result classifier
// (global-search, finding G-new-1). Run via `node --test tests/unit-js/`
// (package.json's `test:js-unit` script), same pattern as
// tests/unit-js/courseOrder.test.mjs.

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	buildGlobalSearchRequests,
	classifyPersonKind,
	groupGlobalSearchResults,
	personResultLabel,
	STAFF_ROLES,
} from '../../src/utils/globalSearch.js'

test('a blank search term builds no requests', () => {
	assert.deepEqual(buildGlobalSearchRequests(''), [])
	assert.deepEqual(buildGlobalSearchRequests('   '), [])
	assert.deepEqual(buildGlobalSearchRequests(undefined), [])
})

test('a real term builds exactly one learner-profile and one cohort request, both searchable schemas', () => {
	const requests = buildGlobalSearchRequests(' Bram ')
	assert.equal(requests.length, 2)

	const people = requests.find((r) => r.kind === 'people')
	assert.ok(people, 'a people request MUST be built')
	assert.equal(people.register, 'learniq')
	assert.equal(people.schema, 'learner-profile')
	// The term is trimmed before it reaches OpenRegister's _search param.
	assert.equal(people.params._search, 'Bram')

	const cohorts = requests.find((r) => r.kind === 'cohorts')
	assert.ok(cohorts, 'a cohorts request MUST be built')
	assert.equal(cohorts.register, 'learniq')
	assert.equal(cohorts.schema, 'cohort')
	assert.equal(cohorts.params._search, 'Bram')
})

test('limit defaults to 8 and a caller-supplied limit is honoured', () => {
	const defaultRequests = buildGlobalSearchRequests('bram')
	assert.ok(defaultRequests.every((r) => r.params._limit === 8))

	const customRequests = buildGlobalSearchRequests('bram', { limit: 3 })
	assert.ok(customRequests.every((r) => r.params._limit === 3))

	// A non-positive/garbage limit falls back to the default rather than
	// silently sending a broken _limit value.
	const garbageRequests = buildGlobalSearchRequests('bram', { limit: -5 })
	assert.ok(garbageRequests.every((r) => r.params._limit === 8))
})

test('classifyPersonKind: no roles at all defaults to learner, not staff', () => {
	assert.equal(classifyPersonKind(undefined), 'learner')
	assert.equal(classifyPersonKind(null), 'learner')
	assert.equal(classifyPersonKind([]), 'learner')
})

test('classifyPersonKind: a plain learner role stays a learner', () => {
	assert.equal(classifyPersonKind(['learner']), 'learner')
})

test('classifyPersonKind: a guardian (parent role) is not classified as staff', () => {
	assert.equal(classifyPersonKind(['parent']), 'learner')
})

test('classifyPersonKind: every declared staff role classifies as staff', () => {
	for (const role of STAFF_ROLES) {
		assert.equal(classifyPersonKind([role]), 'staff', `role "${role}" MUST classify as staff`)
	}
})

test('classifyPersonKind: a mixed roles array with any staff role classifies as staff', () => {
	assert.equal(classifyPersonKind(['learner', 'mentor']), 'staff')
})

test('personResultLabel: prefers given+family name, falls back to ncUserId, falls back to id', () => {
	assert.equal(personResultLabel({ givenName: 'Bram', familyName: 'de Vries' }), 'Bram de Vries')
	assert.equal(personResultLabel({ givenName: '', familyName: '', ncUserId: 'bram123' }), 'bram123')
	assert.equal(personResultLabel({ id: 'uuid-1' }), 'uuid-1')
	assert.equal(personResultLabel({}), '')
})

test('groupGlobalSearchResults: splits learner-profile hits into learners/staff and maps cohorts, each with a route', () => {
	const learnerProfileResults = [
		{ id: 'l1', givenName: 'Bram', familyName: 'de Vries', roles: ['learner'] },
		{ id: 's1', givenName: 'Anna', familyName: 'Bakker', roles: ['mentor'] },
		{ id: 'l2', ncUserId: 'noor', roles: [] },
	]
	const cohortResults = [{ id: 'c1', name: '3H Biologie 2025-2026' }]

	const grouped = groupGlobalSearchResults(learnerProfileResults, cohortResults)

	assert.equal(grouped.learners.length, 2)
	assert.equal(grouped.staff.length, 1)
	assert.equal(grouped.cohorts.length, 1)

	assert.deepEqual(
		grouped.learners.map((r) => r.id),
		['l1', 'l2'],
	)
	assert.equal(grouped.staff[0].id, 's1')
	assert.equal(grouped.staff[0].label, 'Anna Bakker')
	assert.deepEqual(grouped.staff[0].route, { path: '/learner-profiles/s1' })

	assert.equal(grouped.cohorts[0].label, '3H Biologie 2025-2026')
	assert.deepEqual(grouped.cohorts[0].route, { path: '/cohorts/c1' })
})

test('groupGlobalSearchResults: handles empty/missing inputs without throwing', () => {
	assert.deepEqual(groupGlobalSearchResults(), { learners: [], staff: [], cohorts: [] })
	assert.deepEqual(groupGlobalSearchResults([], []), { learners: [], staff: [], cohorts: [] })
})
