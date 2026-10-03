// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// timetabling-lesson-note: a series note lands on the same lesson in the next
// weeks (same group, course, weekday and start time), and a planninq lesson
// is named by its timetable reference, a learniq Session by its id.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	noteFor,
	repeatsLesson,
	seriesTargets,
} from '../../src/utils/lessonNotes.js'

const tuesday = (day) => `2026-09-${String(day).padStart(2, '0')}T09:00:00`

const lesson = {
	id: 's-1',
	cohortId: 'c-1',
	courseId: 'wb',
	title: 'Wiskunde B',
	startsAt: tuesday(29),
}

test('a series note lands on the same lesson in the next weeks only', () => {
	const candidates = [
		{
			id: 's-2',
			cohortId: 'c-1',
			courseId: 'wb',
			startsAt: '2026-10-06T09:00:00',
		},
		{
			id: 's-3',
			cohortId: 'c-1',
			courseId: 'wb',
			startsAt: '2026-10-13T09:00:00',
		},
		{
			id: 's-4',
			cohortId: 'c-1',
			courseId: 'wb',
			startsAt: '2026-10-20T09:00:00',
		},
		{
			id: 's-5',
			cohortId: 'c-1',
			courseId: 'wb',
			startsAt: '2026-10-27T09:00:00',
		},
		{
			id: 'x-time',
			cohortId: 'c-1',
			courseId: 'wb',
			startsAt: '2026-10-06T11:00:00',
		},
		{
			id: 'x-course',
			cohortId: 'c-1',
			courseId: 'ne',
			startsAt: '2026-10-06T09:00:00',
		},
		{
			id: 'x-group',
			cohortId: 'c-2',
			courseId: 'wb',
			startsAt: '2026-10-06T09:00:00',
		},
	]

	const ids = seriesTargets(lesson, candidates, 3).map((l) => l.id)

	assert.deepEqual(ids, ['s-1', 's-2', 's-3', 's-4'])
})

test('without weeks the note is for the chosen lesson only', () => {
	assert.deepEqual(
		seriesTargets(
			lesson,
			[{ ...lesson, id: 's-2', startsAt: '2026-10-06T09:00:00' }],
			0,
		).map((l) => l.id),
		['s-1'],
	)
})

test('a lesson without a course repeats by title', () => {
	const a = {
		id: 'p-1',
		cohortId: 'c-1',
		title: 'Wiskunde B',
		startsAt: tuesday(29),
	}
	assert.equal(
		repeatsLesson(a, { ...a, id: 'p-2', startsAt: '2026-10-06T09:00:00' }),
		true,
	)
	assert.equal(
		repeatsLesson(a, {
			...a,
			id: 'p-3',
			title: 'Engels',
			startsAt: '2026-10-06T09:00:00',
		}),
		false,
	)
})

test('a planninq lesson is named by its timetable reference', () => {
	const note = noteFor(
		{
			id: 'p-1',
			cohortId: 'c-1',
			source: 'planninq',
			sourceSystem: 'roster-zermelo',
			externalRef: 'zm-1',
		},
		{
			topic: ' Hoofdstuk 4 ',
			text: 'Neem je rekenmachine mee ',
			audience: 'learners',
		},
	)
	assert.deepEqual(note, {
		cohortId: 'c-1',
		topic: 'Hoofdstuk 4',
		text: 'Neem je rekenmachine mee',
		audience: 'learners',
		timetableSessionRef: { sourceSystem: 'roster-zermelo', externalRef: 'zm-1' },
	})
})

test('a learniq lesson is named by its session id, and the audience is closed', () => {
	const note = noteFor(lesson, {
		topic: '',
		text: 'Opgave 12',
		audience: 'anything',
	})
	assert.equal(note.sessionId, 's-1')
	assert.equal(note.topic, null)
	assert.equal(note.audience, 'learners')
	assert.equal(noteFor(lesson, { text: 'x', audience: 'cover' }).audience, 'cover')
})
