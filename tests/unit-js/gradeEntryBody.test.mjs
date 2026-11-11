// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The GradeEntry the grade dialog saves (src/dialogs/dialogBodies.js): every
// fallback of gradeEntryCreateBody and every value withOptional leaves out,
// pinned before the body was split into named steps. The happy path stays in
// boardDialogBodies.test.mjs.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { gradeEntryCreateBody } from '../../src/dialogs/dialogBodies.js'

const required = { learnerId: ' l1 ', curriculumPlanId: 'p1', componentId: 'c1', value: '7' }

test('the form wins over the component and the plan', () => {
	const plan = { gradeScaleId: 'plan-scale', components: [{ componentId: 'c1', weight: 2, period: 1, gradeScaleId: 'c-scale' }] }
	const body = gradeEntryCreateBody({ ...required, gradeScaleId: ' f-scale ', weight: '3', period: ' P2 ', grader: ' g1 ', sourceKind: 'method-test' }, plan, 't1', 'ten')
	assert.deepEqual(body, {
		learnerId: 'l1',
		curriculumPlanId: 'p1',
		componentId: 'c1',
		gradeScaleId: 'f-scale',
		sourceKind: 'method-test',
		value: 7,
		grader: 'g1',
		weight: 3,
		period: 'P2',
		tenant_id: 'ten',
	})
})

test('the scale falls back to the component, then the plan, then empty', () => {
	const withPlanScale = { gradeScaleId: 'plan-scale', components: [{ componentId: 'c1' }] }
	assert.equal(gradeEntryCreateBody(required, withPlanScale, 't1', null).gradeScaleId, 'plan-scale')
	assert.equal(gradeEntryCreateBody(required, null, 't1', null).gradeScaleId, '')
	assert.equal(gradeEntryCreateBody(required, { components: 'none' }, 't1', null).gradeScaleId, '')
})

test('an unknown source is manual, and an absent weight or period is left out', () => {
	const body = gradeEntryCreateBody({ ...required, sourceKind: 'guess', weight: 'x' }, { components: [{ componentId: 'c1' }] }, 't1', null)
	assert.equal(body.sourceKind, 'manual')
	assert.equal('weight' in body, false)
	assert.equal('period' in body, false)
	assert.equal('tenant_id' in body, false)
	assert.equal(body.grader, 't1')
})

test('a component period of 0 is kept as text', () => {
	assert.equal(gradeEntryCreateBody(required, { components: [{ componentId: 'c1', period: 0 }] }, 't1', null).period, '0')
})

test('optional values are trimmed, and empty ones of any kind are left out', () => {
	const body = gradeEntryCreateBody({
		...required,
		courseId: ' k1 ',
		courseName: '',
		cohortId: null,
		methodName: undefined,
		methodBlock: [],
		comment: '   ',
		gradedAt: '2026-01-02',
		visibleFrom: 'soon',
	}, null, 't1', Number.NaN)
	assert.equal(body.courseId, 'k1')
	assert.equal(body.comment, '')
	assert.equal(body.gradedAt, '2026-01-02T00:00:00Z')
	assert.equal(body.visibleFrom, 'soon')
	for (const key of ['courseName', 'cohortId', 'methodName', 'methodBlock', 'tenant_id']) {
		assert.equal(key in body, false, key)
	}
	assert.deepEqual(gradeEntryCreateBody({ ...required, methodBlock: ['b'] }, null, 't1', 0).methodBlock, ['b'])
	assert.equal(gradeEntryCreateBody(required, null, 't1', 0).tenant_id, 0)
})
