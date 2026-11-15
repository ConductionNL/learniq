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
	const rows = handInRows({ learnerIds: roster, submissions, assignment: { dueAt: null } })
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
	const rows = handInRows({
		learnerIds: ['pupil-1'],
		submissions: [
			{ id: 'old', learnerIds: ['pupil-1'], lifecycle: 'draft' },
			{ id: 'new', learnerIds: ['pupil-1'], lifecycle: 'late' },
		],
		assignment: {},
	})
	assert.equal(rows[0].state, 'handed-in')
	assert.equal(rows[0].submissionId, 'new')
})

test('a submission for someone outside the roster does not count', () => {
	const rows = handInRows({
		learnerIds: ['pupil-1'],
		submissions: [{ id: 's', learnerIds: ['stranger'], lifecycle: 'submitted' }],
		assignment: {},
	})
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
	const past = handInRows({
		learnerIds: ['pupil-1', 'pupil-2'],
		submissions,
		assignment: { dueAt: '2026-09-20T23:59:00Z' },
	}, now)
	const future = handInRows({
		learnerIds: ['pupil-2'],
		submissions: [],
		assignment: { dueAt: '2026-10-01T23:59:00Z' },
	}, now)

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

test('the roster skips blank ids, matches a cohort by uuid, and reads missing cohorts as none', () => {
	const cohorts = [
		{ uuid: 'c-a', learnerIds: ['p1', '', 7, 'p2', 'p1'] },
		{ id: 'c-b', learnerIds: ['p3'] },
		null,
		{ id: 'c-c' },
	]
	assert.deepEqual(rosterFor({ cohortId: 'c-a' }, cohorts), ['p1', 'p2'])
	assert.deepEqual(rosterFor({}, cohorts), ['p1', 'p2', 'p3'])
	assert.deepEqual(rosterFor(null, undefined), [])
	assert.deepEqual(rosterFor({ cohortId: 'x' }, undefined), [])
})

test('a row names the handed-in submission before a draft, and an unreadable due date is never overdue', () => {
	const submissions = [
		{ uuid: 'd1', lifecycle: 'draft', learnerIds: ['p1', 'p2'] },
		{ id: 's1', uuid: 'u1', lifecycle: 'returned', learnerIds: ['p1'] },
		null,
		{ id: 'x', lifecycle: 'draft' },
	]
	const now = new Date('2026-05-01T00:00:00Z')
	assert.deepEqual(handInRows({ learnerIds: ['p1', 'p2', 'p3'], submissions, assignment: { dueAt: '2026-04-01' } }, now), [
		{ learnerId: 'p1', state: 'handed-in', lifecycle: 'returned', submissionId: 's1', overdue: false },
		{ learnerId: 'p2', state: 'started', lifecycle: 'draft', submissionId: 'd1', overdue: true },
		{ learnerId: 'p3', state: 'not-started', lifecycle: null, submissionId: null, overdue: true },
	])
	assert.equal(handInRows({ learnerIds: ['p3'], submissions: [], assignment: { dueAt: 'not a date' } }, now)[0].overdue, false)
	assert.equal(handInRows({ learnerIds: ['p3'], submissions: null, assignment: { dueAt: '2026-06-01' } }, now)[0].overdue, false)
	assert.equal(handInRows({ learnerIds: ['p3'], submissions: null, assignment: { dueAt: '2026-05-01T00:00:00Z' } }, now)[0].overdue, false)
	assert.deepEqual(handInRows({ learnerIds: undefined, submissions, assignment: null }, now), [])
	assert.deepEqual(handInRows({ learnerIds: ['p9'], submissions: [{ lifecycle: 'submitted', learnerIds: ['p9'] }], assignment: null }, now)[0], {
		learnerId: 'p9',
		state: 'handed-in',
		lifecycle: 'submitted',
		submissionId: null,
		overdue: false,
	})
})
