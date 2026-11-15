// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// timetabling-multi-year-hour-plan: the plan grid, totals per year against the
// norm, the copy for the next intake and the activities CSV.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	activitiesCsv,
	cellHours,
	copyForNextIntake,
	nextSchoolYear,
	planColumns,
	setCell,
	yearTotals,
} from '../../src/utils/hourPlan.js'

const plan = {
	name: 'MMC 2026-2027',
	programmeId: 'p',
	intakeYear: '2026-2027',
	durationYears: 2,
	periodsPerYear: [
		{ periodCode: 'P1', label: 'Periode 1' },
		{ periodCode: 'P2', label: 'Periode 2' },
	],
	lines: [
		{ courseId: 'ne', programmeYear: 1, periodCode: 'P1', contactHours: 300 },
		{
			courseId: 'ne',
			programmeYear: 2,
			periodCode: 'P1',
			contactHours: 340,
			otherHours: 20,
		},
		{
			courseId: 'bpv',
			programmeYear: 2,
			periodCode: null,
			contactHours: 300,
			otherHours: 400,
		},
	],
	yearNorms: [
		{ programmeYear: 1, contactHours: 700 },
		{ programmeYear: 2, contactHours: 700 },
	],
}

test('the grid has a column per year and period', () => {
	const cols = planColumns(plan)
	assert.equal(cols.length, 4)
	assert.deepEqual(cols[2], {
		programmeYear: 2,
		periodCode: 'P1',
		label: 'Periode 1',
	})
	assert.deepEqual(
		planColumns({ durationYears: 3 }).map((c) => c.programmeYear),
		[1, 2, 3],
	)
})

test('a year below its norm is marked with the hours it is short', () => {
	const totals = yearTotals(plan)
	assert.equal(totals[0].shortBy, 400)
	assert.equal(totals[1].contactHours, 640)
	assert.equal(totals[1].shortBy, 60)
	assert.equal(totals[1].otherHours, 420)
})

test('setting a cell adds, changes and drops lines', () => {
	let lines = setCell(plan.lines, { courseId: 'en', programmeYear: 1, periodCode: 'P2', hours: '80' })
	assert.equal(cellHours(lines, { courseId: 'en', programmeYear: 1, periodCode: 'P2' }), 80)
	lines = setCell(lines, { courseId: 'en', programmeYear: 1, periodCode: 'P2', hours: 0 })
	assert.equal(lines.length, 3)
	// A line with other hours is kept at 0 contact hours.
	lines = setCell(lines, { courseId: 'bpv', programmeYear: 2, periodCode: null, hours: 0 })
	assert.equal(lines.find((l) => l.courseId === 'bpv').otherHours, 400)
	assert.equal(cellHours(lines, { courseId: 'bpv', programmeYear: 2, periodCode: null }), 0)
})

test('the copy is a draft for the next intake with the same lines', () => {
	const copy = copyForNextIntake(plan)
	assert.equal(nextSchoolYear('2026-2027'), '2027-2028')
	assert.equal(copy.intakeYear, '2027-2028')
	assert.equal(copy.name, 'MMC 2027-2028')
	assert.equal(copy.lifecycle, 'draft')
	assert.equal(copy.lines.length, 3)
	assert.notEqual(copy.lines[0], plan.lines[0])
})

test('the activities CSV quotes what needs quoting', () => {
	const csv = activitiesCsv(
		[
			{
				cohortName: 'MV2A',
				programmeYear: 2,
				courseName: 'Nederlands, taal',
				periodCode: 'P1',
				contactHours: 30,
				otherHours: 0,
				activityKind: 'lesson',
				teacherIds: ['a', 'b'],
			},
		],
		{ cohortName: 'Group' },
	)
	assert.equal(csv.split('\n')[1], 'MV2A,2,"Nederlands, taal",P1,30,0,lesson,a b')
	assert.ok(csv.startsWith('Group,programmeYear'))
})

test('a plan without a length has one year, and a period without a label shows its code', () => {
	assert.deepEqual(planColumns({}), [
		{ programmeYear: 1, periodCode: null, label: '' },
	])
	assert.deepEqual(
		planColumns({ durationYears: '0', periodsPerYear: [{ periodCode: 'Q1' }] }),
		[{ programmeYear: 1, periodCode: 'Q1', label: 'Q1' }],
	)
	assert.deepEqual(planColumns(null), [
		{ programmeYear: 1, periodCode: null, label: '' },
	])
})

test('a year without a norm, or with an empty one, is never short', () => {
	const totals = yearTotals({
		durationYears: 3,
		lines: [
			{ programmeYear: '1', contactHours: 'x', otherHours: 5 },
			{ programmeYear: 3, contactHours: 900 },
		],
		yearNorms: [
			{ programmeYear: 1, contactHours: null },
			{ programmeYear: 3, contactHours: '800' },
		],
	})
	assert.deepEqual(totals, [
		{ programmeYear: 1, contactHours: 0, otherHours: 5, norm: null, shortBy: 0 },
		{ programmeYear: 2, contactHours: 0, otherHours: 0, norm: null, shortBy: 0 },
		{ programmeYear: 3, contactHours: 900, otherHours: 0, norm: 800, shortBy: 0 },
	])
	assert.deepEqual(yearTotals(null), [
		{ programmeYear: 1, contactHours: 0, otherHours: 0, norm: null, shortBy: 0 },
	])
	assert.equal(
		yearTotals({ yearNorms: [{ programmeYear: 1 }] })[0].norm,
		null,
	)
})

test('a new cell becomes a lesson line, an empty or negative cell adds nothing', () => {
	assert.deepEqual(setCell(null, { courseId: 'wi', programmeYear: 2, periodCode: undefined, hours: '12' }), [
		{
			courseId: 'wi',
			programmeYear: 2,
			periodCode: null,
			contactHours: 12,
			otherHours: 0,
			activityKind: 'lesson',
		},
	])
	assert.deepEqual(setCell([], { courseId: 'wi', programmeYear: 2, periodCode: null, hours: '-4' }), [])
	assert.deepEqual(setCell([], { courseId: 'wi', programmeYear: 2, periodCode: null, hours: 'abc' }), [])
	const kept = setCell(plan.lines, { courseId: 'ne', programmeYear: 2, periodCode: 'P1', hours: -3 })
	assert.deepEqual(kept[1], { ...plan.lines[1], contactHours: 0 })
	assert.equal(kept[0], plan.lines[0])
	const changed = setCell(plan.lines, { courseId: 'ne', programmeYear: 1, periodCode: 'P1', hours: 10 })
	assert.deepEqual(changed.map((l) => l.contactHours), [10, 340, 300])
	assert.equal(cellHours(undefined, { courseId: 'ne', programmeYear: 1, periodCode: 'P1' }), 0)
	assert.equal(
		cellHours(
			[{ courseId: 'ne', programmeYear: '1', contactHours: 'x' }],
			{ courseId: 'ne', programmeYear: 1, periodCode: undefined },
		),
		0,
	)
	assert.equal(
		cellHours(
			[{ courseId: 'ne', programmeYear: '1', contactHours: '7' }],
			{ courseId: 'ne', programmeYear: 1, periodCode: null },
		),
		7,
	)
})
