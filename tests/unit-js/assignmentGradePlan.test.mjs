// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Marking a submission creates a concept GradeEntry only when the plan is
// found. The plan hangs off the assignment's course; reading it off the
// assignment (which cannot hold it) meant UI marking never created a grade.
// Run by `npm run test:js-unit`.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { resolveGradePlan } from '../../src/utils/assignmentGradePlan.js'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')

/**
 * A fetchObject over fixed rows.
 *
 * @param {object} rows Rows by schema slug, then id.
 * @return {function(string, string): Promise<object|null>}
 */
function reader(rows) {
	return async (schema, id) => rows[schema]?.[id] ?? null
}

// The real Assignment shape: no curriculumPlanId, no gradeScaleId.
const assignment = { id: 'as1', courseId: 'c1', curriculumPlanComponentId: 'comp1' }

test('the plan comes from the course and the scale from the plan', async () => {
	const plan = await resolveGradePlan(
		assignment,
		reader({
			course: { c1: { curriculumPlanId: 'p1' } },
			'curriculum-plan': { p1: { gradeScaleId: 'g1' } },
		}),
	)
	assert.deepEqual(plan, {
		curriculumPlanId: 'p1',
		componentId: 'comp1',
		gradeScaleId: 'g1',
		courseId: 'c1',
	})
})

test('a plan without a scale uses the default scale', async () => {
	const plan = await resolveGradePlan(
		assignment,
		reader({
			course: { c1: { curriculumPlanId: 'p1' } },
			'curriculum-plan': { p1: {} },
		}),
	)
	assert.equal(plan.gradeScaleId, '')
})

test('no component, no course plan, or an unreadable plan gives no grade', async () => {
	const rows = {
		course: { c1: { curriculumPlanId: 'p1' } },
		'curriculum-plan': { p1: { gradeScaleId: 'g1' } },
	}
	assert.equal(await resolveGradePlan({ courseId: 'c1' }, reader(rows)), null)
	assert.equal(
		await resolveGradePlan(assignment, reader({ course: { c1: {} } })),
		null,
	)
	assert.equal(
		await resolveGradePlan(
			assignment,
			reader({ course: { c1: { curriculumPlanId: 'p1' } } }),
		),
		null,
	)
})

test('the marking view resolves the plan through the course, not off the assignment', () => {
	const view = readFileSync(
		resolve(root, 'src/views/MarkSubmissionView.vue'),
		'utf8',
	)
	assert.doesNotMatch(
		view,
		/assignment\.curriculumPlanId/,
		'MarkSubmissionView reads a plan id the Assignment schema cannot hold',
	)
	assert.doesNotMatch(
		view,
		/assignment\.gradeScaleId/,
		'MarkSubmissionView reads a scale id the Assignment schema cannot hold',
	)
	assert.match(
		view,
		/resolveGradePlan\(/,
		'MarkSubmissionView does not use resolveGradePlan',
	)
})
