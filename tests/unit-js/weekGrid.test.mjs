// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The week grid of My timetable (week-timetable-grid T2): lesson hours from
// the week's start times, each session in its day and hour, and its change as
// a pill in words. Run via `node --test tests/unit-js/` (`test:js-unit`).
//
// @spec openspec/changes/week-timetable-grid/specs/personal-timetable/spec.md#requirement-a-teacher-reads-her-week-as-day-columns-and-lesson-hours

import assert from 'node:assert/strict'
import { test } from 'node:test'

const { changePill, clockOf, dayOf, isoWeek, lessonHourRows, roomName, weekGridItems } = await import('../../src/utils/weekGrid.js')

/**
 * A translate double that fills `{name}` placeholders.
 *
 * @param {string} text The source string.
 * @param {object} vars The placeholders.
 * @return {string} The filled string.
 */
const t = (text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, name) => String(vars[name] ?? match))

/**
 * A local ISO timestamp, so the tests read the same in every time zone.
 *
 * @param {number} day The October 2026 day.
 * @param {number} hour The hour.
 * @param {number} minute The minute.
 * @return {string} The timestamp.
 */
const at = (day, hour, minute = 0) => new Date(2026, 9, day, hour, minute).toISOString()

const WEEK = [
	{ id: 's1', title: 'Nederlands', cohortName: 'V4c', room: { name: '1.12' }, startsAt: at(5, 8, 30), endsAt: at(5, 9, 20), lifecycle: 'scheduled' },
	{ id: 's2', title: 'Wiskunde', room: { name: '0.14' }, movedAt: '2026-10-04T12:00:00Z', startsAt: at(7, 10, 25), endsAt: at(7, 11, 15), lifecycle: 'scheduled' },
	{ id: 's3', title: 'Engels', startsAt: at(6, 9, 20), endsAt: at(6, 10, 10), lifecycle: 'cancelled' },
	{ id: 's4', title: 'Geschiedenis', cover: true, startsAt: at(8, 8, 30), endsAt: at(8, 9, 20) },
	{ id: 's5', title: 'Mentoruur', location: 'https://meet.example/abc', startsAt: at(9, 9, 20), endsAt: at(9, 10, 10) },
	{ id: 'bad', title: 'No start' },
]

test('clockOf and dayOf read a timestamp in local time and refuse anything else', () => {
	assert.equal(clockOf(at(5, 8, 30)), '08:30')
	assert.equal(dayOf(at(5, 8, 30)), '2026-10-05')
	assert.equal(clockOf(''), '')
	assert.equal(clockOf('not a date'), '')
	assert.equal(dayOf(undefined), '')
})

test('the lesson hours are the distinct start times, earliest first, numbered', () => {
	const rows = lessonHourRows(WEEK, t)
	assert.deepEqual(rows, [
		{ key: '08:30', label: 'Hour 1', start: '08:30', end: '09:20' },
		{ key: '09:20', label: 'Hour 2', start: '09:20', end: '10:10' },
		{ key: '10:25', label: 'Hour 3', start: '10:25', end: '11:15' },
	])
})

test('an hour keeps the earliest end among its sessions', () => {
	const rows = lessonHourRows([
		{ startsAt: at(5, 8, 30), endsAt: at(5, 10, 10) },
		{ startsAt: at(6, 8, 30), endsAt: at(6, 9, 20) },
	], t)
	assert.equal(rows[0].end, '09:20')
})

test('a week without sessions has no lesson hours', () => {
	assert.deepEqual(lessonHourRows([], t), [])
	assert.deepEqual(lessonHourRows(null, t), [])
})

test('each session sits in its day and the row of its start time, with group and room', () => {
	const items = weekGridItems(WEEK, t)
	assert.equal(items.length, 5)
	assert.deepEqual(
		items.map((item) => [item.title, item.date, item.row]),
		[
			['Nederlands', '2026-10-05', '08:30'],
			['Wiskunde', '2026-10-07', '10:25'],
			['Engels', '2026-10-06', '09:20'],
			['Geschiedenis', '2026-10-08', '08:30'],
			['Mentoruur', '2026-10-09', '09:20'],
		],
	)
	assert.deepEqual(items[0].meta, ['V4c', '1.12'])
	assert.equal(items[0].session, WEEK[0])
	assert.deepEqual(items[4].meta, [])
})

test('a change reads as a pill in words: cancelled, cover, or the room a moved lesson is in now', () => {
	assert.deepEqual(changePill(WEEK[2], t), { label: 'Cancelled', variant: 'cancelled' })
	assert.deepEqual(changePill(WEEK[3], t), { label: 'Cover', variant: 'substitute' })
	assert.deepEqual(changePill({ substituteTeacherId: 'jan' }, t), { label: 'Cover', variant: 'substitute' })
	assert.deepEqual(changePill(WEEK[1], t), { label: 'Room 0.14', variant: 'changed' })
	assert.equal(changePill(WEEK[0], t), null)
	assert.equal(changePill({ movedAt: '2026-10-04T12:00:00Z' }, t), null)
})

test('a session without a title is named so', () => {
	const [item] = weekGridItems([{ id: 'x', startsAt: at(5, 8, 30) }], t)
	assert.equal(item.title, 'Untitled session')
})

test('the room is the Room name, else a location that is no online link', () => {
	assert.equal(roomName({ room: { name: '1.12' }, location: 'B2' }), '1.12')
	assert.equal(roomName({ location: 'B2' }), 'B2')
	assert.equal(roomName({ location: 'https://meet.example/abc' }), '')
	assert.equal(roomName({}), '')
})

test('isoWeek numbers the week by ISO 8601', () => {
	assert.equal(isoWeek(new Date(2026, 9, 5)), 41)
	assert.equal(isoWeek(new Date(2026, 9, 9)), 41)
	assert.equal(isoWeek(new Date(2026, 0, 1)), 1)
	assert.equal(isoWeek(new Date(2027, 0, 1)), 53)
	assert.equal(isoWeek(new Date(2024, 11, 30)), 1)
})

test('a moved lesson names its new room in the pill and the room it left on its line', () => {
	const [item] = weekGridItems([
		{ id: 'm', title: 'Nederlands', cohortName: 'V4c', room: { name: '0.14' }, movedAt: '2026-10-06T08:00:00Z', movedFromRoom: '1.12', startsAt: at(7, 10, 25) },
	], t)
	assert.deepEqual(item.pill, { label: 'Room 0.14', variant: 'changed' })
	assert.deepEqual(item.meta, ['V4c', 'Not in 1.12'])
})

test('a moved lesson without the room it left keeps its room on the line', () => {
	const [item] = weekGridItems([{ id: 'm', title: 'X', room: { name: '0.14' }, movedAt: '2026-10-06T08:00:00Z', startsAt: at(7, 10, 25) }], t)
	assert.deepEqual(item.meta, ['0.14'])
})
