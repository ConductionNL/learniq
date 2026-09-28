// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// assignment-missing-submissions-view: who has not handed in an assignment.
// Pins the roster, the three states, the overdue mark and the staff gate.
//
// @spec openspec/specs/assignments/spec.md#requirement-a-teacher-sees-who-has-not-handed-in-an-assignment

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	canSeeHandInStatus,
	handInRows,
	handInSummary,
	rosterFor,
} from '../../src/utils/handInStatus.js'

/**
 * @param {number} n How many learners.
 * @return {string[]} pupil-1 .. pupil-n
 */
function learners(n) {
	return Array.from({ length: n }, (_, i) => `pupil-${i + 1}`)
}

test('six of twenty-four have not handed in: 18 handed in, 2 started, 4 not started', () => {
	const roster = learners(24)
	const states = ['submitted', 'late', 'returned']
	const submissions = [
		...roster.slice(0, 18).map((id, i) => ({
			id: `sub-${i}`,
			learnerIds: [id],
			lifecycle: states[i % 3],
		})),
		{ id: 'sub-d1', learnerIds: ['pupil-19'], lifecycle: 'draft' },
		{ id: 'sub-d2', learnerIds: ['pupil-20'], lifecycle: 'draft' },
	]
	const rows = handInRows(roster, submissions, { dueAt: null })
	const summary = handInSummary(rows)

	assert.equal(summary.total, 24)
	assert.equal(summary.handedIn, 18)
	assert.equal(summary.started, 2)
	assert.equal(summary.notStarted, 4)
	assert.deepEqual(
		rows.filter((r) => r.state === 'not-started').map((r) => r.learnerId),
		['pupil-21', 'pupil-22', 'pupil-23', 'pupil-24'],
	)
	assert.equal(rows.find((r) => r.learnerId === 'pupil-19').submissionId, 'sub-d1')
})

test('a handed-in submission wins over an older draft for the same learner', () => {
	const rows = handInRows(
		['pupil-1'],
		[
			{ id: 'old', learnerIds: ['pupil-1'], lifecycle: 'draft' },
			{ id: 'new', learnerIds: ['pupil-1'], lifecycle: 'late' },
		],
		{},
	)
	assert.equal(rows[0].state, 'handed-in')
	assert.equal(rows[0].submissionId, 'new')
})

test('a submission for someone outside the roster does not count', () => {
	const rows = handInRows(
		['pupil-1'],
		[{ id: 's', learnerIds: ['stranger'], lifecycle: 'submitted' }],
		{},
	)
	assert.equal(rows[0].state, 'not-started')
})

test('a course-wide assignment uses every cohort of the course, each learner once', () => {
	const cohorts = [
		{ id: 'c-a', learnerIds: ['pupil-1', 'pupil-2'] },
		{ id: 'c-b', learnerIds: ['pupil-2', 'pupil-3', ''] },
	]
	assert.deepEqual(rosterFor({ courseId: 'course-1' }, cohorts), [
		'pupil-1',
		'pupil-2',
		'pupil-3',
	])
})

test('an assignment for one cohort uses only that cohort', () => {
	const cohorts = [
		{ id: 'c-a', learnerIds: ['pupil-1'] },
		{ id: 'c-b', learnerIds: ['pupil-2'] },
	]
	assert.deepEqual(rosterFor({ cohortId: 'c-b' }, cohorts), ['pupil-2'])
})

test('missing work is overdue after the due date, and not before', () => {
	const now = new Date('2026-09-27T12:00:00Z')
	const submissions = [
		{ id: 's', learnerIds: ['pupil-1'], lifecycle: 'submitted' },
	]
	const past = handInRows(
		['pupil-1', 'pupil-2'],
		submissions,
		{ dueAt: '2026-09-20T23:59:00Z' },
		now,
	)
	const future = handInRows(
		['pupil-2'],
		[],
		{ dueAt: '2026-10-01T23:59:00Z' },
		now,
	)

	assert.equal(past[0].overdue, false)
	assert.equal(past[1].overdue, true)
	assert.equal(handInSummary(past).overdue, 1)
	assert.equal(future[0].overdue, false)
})

test('only the teacher and admin views see the roster', () => {
	assert.equal(canSeeHandInStatus(['teacher', 'student']), true)
	assert.equal(canSeeHandInStatus(['admin', 'teacher', 'student']), true)
	assert.equal(canSeeHandInStatus(['student']), false)
	assert.equal(canSeeHandInStatus(undefined), false)
})
