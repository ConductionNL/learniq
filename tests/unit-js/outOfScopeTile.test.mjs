// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The compliance page's "No longer in scope" list: the mandatory enrolments
// a change of department, roles or job title took out of the scope of their
// regulation. Pinned as a declaration: which enrolments it reads, in what
// order, and what it shows of each.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
//
// @spec openspec/changes/compliance-assign-from-hr-roles/tasks.md#task-4.1

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { outOfScopeTile } from '../../src/utils/dashboardTiles.js'

const LABELS = {
	learner: 'Leerling',
	course: 'Cursus',
	regulation: 'Regeling',
	dueDate: 'Deadline',
	outOfScopeSince: 'Niet meer in scope sinds',
	empty: 'Niets buiten de scope',
}

test('the tile is a shared object table under its title', () => {
	const tile = outOfScopeTile('out-of-scope', 'Niet meer in scope', LABELS)
	assert.equal(tile.id, 'out-of-scope')
	assert.equal(tile.title, 'Niet meer in scope')
	assert.equal(tile.type, 'object-table')
})

test('it reads only enrolments with outOfScopeSince set, newest first', () => {
	const { source } = outOfScopeTile('out-of-scope', 'x', LABELS).content
	assert.deepEqual(source, {
		register: 'learniq',
		schema: 'enrolment',
		filter: { outOfScopeSince: { isnull: false } },
		order: { outOfScopeSince: 'desc' },
		limit: 20,
	})
})

test('it shows the learner, course, regulation, deadline and the out-of-scope day', () => {
	const { columns, rowRoute, emptyText } = outOfScopeTile('out-of-scope', 'x', LABELS).content
	assert.deepEqual(columns.map((col) => [col.key, col.label, col.format]), [
		['learnerName', 'Leerling', undefined],
		['courseName', 'Cursus', undefined],
		['regulationSlug', 'Regeling', undefined],
		['dueDate', 'Deadline', 'date'],
		['outOfScopeSince', 'Niet meer in scope sinds', 'date'],
	])
	assert.equal(rowRoute, 'EnrolmentDetail')
	assert.equal(emptyText, 'Niets buiten de scope')
})

test('each call hands out a fresh declaration', () => {
	const first = outOfScopeTile('out-of-scope', 'x', LABELS)
	first.content.source.filter.outOfScopeSince.isnull = true
	assert.equal(outOfScopeTile('out-of-scope', 'x', LABELS).content.source.filter.outOfScopeSince.isnull, false)
})
