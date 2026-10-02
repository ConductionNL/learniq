// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Unit tests for the roll-call page's rules (attendance-roll-call).
// Run via `npm run test:js-unit`.

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	incompleteMarks,
	localDate,
	marksToSave,
	rollCallCounts,
	rollCallRoute,
	sessionsToMarkFilter,
	validLateMinutes,
	withStatus,
} from '../../src/utils/rollCall.js'

const present = {
	learnerId: 'vera',
	name: 'Vera',
	status: 'present',
	lateMinutes: null,
	absenceReasonKind: null,
	reason: '',
}

test('a late mark starts at five minutes and an absence with permission on ill', () => {
	assert.equal(withStatus(present, 'late').lateMinutes, 5)
	assert.equal(withStatus({ ...present, lateMinutes: 15 }, 'late').lateMinutes, 15)
	assert.equal(withStatus(present, 'absent-excused').absenceReasonKind, 'illness')
	assert.equal(
		withStatus(
			{ ...present, absenceReasonKind: 'appointment' },
			'absent-excused',
		).absenceReasonKind,
		'appointment',
	)
	const back = withStatus(
		{
			...present,
			status: 'absent-excused',
			absenceReasonKind: 'illness',
			lateMinutes: 10,
		},
		'present',
	)
	assert.equal(back.absenceReasonKind, null)
	assert.equal(back.lateMinutes, null)
	assert.equal(present.status, 'present', 'the row it came from is left alone')
})

test('late minutes are whole minutes from 1 to 600', () => {
	assert.equal(validLateMinutes(10), true)
	assert.equal(validLateMinutes('25'), true)
	assert.equal(validLateMinutes(0), false)
	assert.equal(validLateMinutes(601), false)
	assert.equal(validLateMinutes(2.5), false)
	assert.equal(validLateMinutes(''), false)
})

test('an incomplete mark is named before the save', () => {
	const pupils = [
		{ ...present, learnerId: 'a', status: 'late', lateMinutes: '' },
		{
			...present,
			learnerId: 'b',
			status: 'absent-excused',
			absenceReasonKind: null,
		},
		{ ...present, learnerId: 'c', status: 'absent-unexcused' },
		{ ...present, learnerId: 'd', status: 'late', lateMinutes: 10 },
	]
	assert.deepEqual(
		incompleteMarks(pupils).map((p) => p.learnerId),
		['a', 'b'],
	)
})

test('a save sends one mark per pupil with only what the server reads', () => {
	const marks = marksToSave([
		{
			...present,
			learnerId: 'vera',
			status: 'late',
			lateMinutes: '10',
			recordId: 'r1',
			name: 'Vera',
		},
		{
			...present,
			learnerId: 'daan',
			status: 'absent-unexcused',
			reason: '  Niet gemeld ',
		},
		{ ...present, learnerId: 'noor' },
	])
	assert.deepEqual(marks, [
		{ learnerId: 'vera', status: 'late', lateMinutes: 10 },
		{
			learnerId: 'daan',
			status: 'absent-unexcused',
			absenceReasonKind: null,
			reason: 'Niet gemeld',
		},
		{ learnerId: 'noor', status: 'present' },
	])
})

test('the counts above the list add up', () => {
	assert.deepEqual(
		rollCallCounts([
			{ status: 'present' },
			{ status: 'late' },
			{ status: 'absent-excused' },
			{ status: 'absent-unexcused' },
			{ status: 'left-early' },
		]),
		{ present: 2, late: 1, absentAuthorised: 1, absentUnauthorised: 1 },
	)
})

test('sessionsToMarkFilter lists lessons up to today, newest first', () => {
	const now = new Date(2026, 9, 2, 9, 0, 0)
	const filter = sessionsToMarkFilter({ cohortId: ['c7'] }, now)
	assert.deepEqual(filter.cohortId, ['c7'])
	assert.equal(filter['_order[startsAt]'], 'desc')
	const end = new Date(filter['startsAt[lte]'])
	assert.equal(localDate(end), '2026-10-02')
	assert.ok(end > now)
	assert.deepEqual(Object.keys(sessionsToMarkFilter({}, now)).sort(), [
		'_order[startsAt]',
		'startsAt[lte]',
	])
})

test('a lesson leads to the roll-call of its group and day', () => {
	const start = new Date(2026, 9, 1, 8, 30).toISOString()
	assert.deepEqual(rollCallRoute({ id: 's1', cohortId: 'c7', startsAt: start }), {
		path: '/attendance/roll-call',
		query: { cohortId: 'c7', date: '2026-10-01', sessionId: 's1' },
	})
	assert.deepEqual(rollCallRoute({}), { path: '/attendance/roll-call', query: {} })
	assert.equal(localDate('not a date'), '')
})
