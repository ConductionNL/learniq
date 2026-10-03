// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// cohort-gradebook-batch-publish: the scope of a publish, the distribution the
// teacher previews, and the report after the batch.
//
// @spec openspec/specs/grading/spec.md#requirement-a-teacher-previews-and-batch-publishes-a-cohorts-concept-grades

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	ALL_COMPONENTS,
	bandsFor,
	distribution,
	publishable,
	publishReport,
	scopeEntries,
} from '../../src/utils/gradebookPublish.js'

/**
 * @param {string} learnerId Learner.
 * @param {string} componentId Component.
 * @param {number|null} value Mark.
 * @param {string} lifecycle Lifecycle.
 * @return {object} A GradeEntry.
 */
function entry(learnerId, componentId, value, lifecycle = 'concept') {
	return {
		id: `${learnerId}-${componentId}`,
		learnerId,
		componentId,
		value,
		lifecycle,
	}
}

const scale = { min: 1, max: 10, passThreshold: 5.5 }

test('the preview shows the spread of one component', () => {
	const entries = [
		entry('p1', 'toets-1', 4.5),
		entry('p2', 'toets-1', 6.0),
		entry('p3', 'toets-1', 7.5),
		entry('p4', 'toets-1', 8.0),
		entry('p1', 'toets-2', 9.0),
	]
	const summary = distribution(scopeEntries(entries, 'toets-1'), scale)

	assert.equal(summary.count, 4)
	assert.equal(summary.average, 6.5)
	assert.equal(summary.lowest, 4.5)
	assert.equal(summary.highest, 8)
	assert.equal(summary.passing, 3)
	assert.equal(summary.bands.length, 9)
	assert.equal(
		summary.bands.reduce((n, b) => n + b.count, 0),
		4,
		'every mark lands in exactly one band',
	)
	assert.equal(summary.bands.find((b) => b.from === 4).count, 1)
	assert.equal(summary.bands.find((b) => b.from === 8).count, 1)
})

test('a ten lands in the last band, not outside it', () => {
	const summary = distribution([entry('p1', 'c', 10), entry('p2', 'c', 1)], scale)
	assert.equal(summary.bands.at(-1).count, 1)
	assert.equal(summary.bands[0].count, 1)
})

test('without a pass threshold the passing count is absent', () => {
	assert.equal(
		distribution([entry('p1', 'c', 7)], { min: 1, max: 10 }).passing,
		null,
	)
	assert.equal(distribution([entry('p1', 'c', 7)], null).passing, null)
})

test('only concept entries with a mark are publishable', () => {
	const scoped = scopeEntries(
		[
			entry('p1', 'c', 6),
			entry('p2', 'c', 7),
			entry('p3', 'c', 8),
			entry('p4', 'c', 9, 'published'),
			entry('p5', 'c', null),
			entry('p6', 'c', 5, 'invalidated'),
		],
		'c',
	)
	assert.deepEqual(
		publishable(scoped).map((e) => e.learnerId),
		['p1', 'p2', 'p3'],
	)
	assert.equal(
		distribution(scoped, scale).count,
		4,
		'published marks still count in the preview',
	)
})

test('all components widens the scope', () => {
	const entries = [
		entry('p1', 'a', 6),
		entry('p1', 'b', 7),
		entry('p2', 'b', 8, 'revised'),
	]
	assert.equal(scopeEntries(entries, ALL_COMPONENTS).length, 2)
	assert.equal(scopeEntries(entries, 'a').length, 1)
})

test('wide scales get five equal bands', () => {
	const bands = bandsFor(0, 100)
	assert.equal(bands.length, 5)
	assert.deepEqual(bands[0], { from: 0, to: 20 })
	assert.deepEqual(bands[4], { from: 80, to: 100 })
	assert.deepEqual(bandsFor(5, 5), [{ from: 5, to: 5 }])
	assert.deepEqual(bandsFor(5, 1), [])
})

test('a refused entry is reported and the rest count as published', () => {
	const report = publishReport([
		{ entry: entry('p1', 'c', 6), ok: true },
		{
			entry: entry('p2', 'c', 7),
			ok: false,
			reason: 'The report period is locked.',
		},
		{ entry: entry('p3', 'c', 8), ok: true },
	])
	assert.equal(report.published, 2)
	assert.deepEqual(report.refused, [
		{ learnerId: 'p2', reason: 'The report period is locked.' },
	])
})

test('an empty scope has nothing to preview', () => {
	assert.deepEqual(distribution([], scale), {
		count: 0,
		average: null,
		lowest: null,
		highest: null,
		passing: null,
		bands: [],
	})
})
