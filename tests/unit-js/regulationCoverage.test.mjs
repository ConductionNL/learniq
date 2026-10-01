// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// compliance-rule-coverage-table: sorting, the empty in-scope row and the
// department filter's URL and options.

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	COVERAGE_URL,
	coverageUrl,
	departmentPaths,
	sortByPercent,
} from '../../src/utils/regulationCoverage.js'

const rows = [
	{ slug: 'AVG', coveragePercent: 75 },
	{ slug: 'NIS2', coveragePercent: null },
	{ slug: 'VCA', coveragePercent: 33.3 },
	{ slug: 'BHV', coveragePercent: 100 },
]

test('sorting by percentage keeps a rule with nobody in scope last, both ways', () => {
	assert.deepEqual(
		sortByPercent(rows, 'asc').map((row) => row.slug),
		['VCA', 'AVG', 'BHV', 'NIS2'],
	)
	assert.deepEqual(
		sortByPercent(rows, 'desc').map((row) => row.slug),
		['BHV', 'AVG', 'VCA', 'NIS2'],
	)
	assert.equal(rows[0].slug, 'AVG', 'the input is not reordered')
	assert.deepEqual(sortByPercent(null, 'asc'), [])
})

test('the department filter narrows the URL and offers every named level', () => {
	assert.equal(coverageUrl(null), COVERAGE_URL)
	assert.equal(
		coverageUrl('Operations/Logistics'),
		`${COVERAGE_URL}?department=Operations%2FLogistics`,
	)
	assert.deepEqual(
		departmentPaths([
			{ department: '' },
			{ department: 'Operations' },
			{ department: 'Operations/Logistics' },
		]),
		['Operations', 'Operations/Logistics'],
	)
	assert.deepEqual(departmentPaths(undefined), [])
})
