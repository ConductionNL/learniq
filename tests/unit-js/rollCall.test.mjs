// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Unit tests for the roll-call page (attendance-roll-call): its CnRowMarkList
// configuration matches what /api/attendance/roll-call reads and the register
// stores, and the dashboard's "Sessions to mark" leads to it.
// Run via `npm run test:js-unit`.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	localDate,
	rollCallRoute,
	sessionsToMarkFilter,
} from '../../src/utils/rollCall.js'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const people = JSON.parse(readFileSync(resolve(root, 'src/manifest.d/people.json'), 'utf8'))
const register = JSON.parse(readFileSync(resolve(root, 'lib/Settings/learniq_register.json'), 'utf8'))
const marksPhp = readFileSync(resolve(root, 'lib/Service/Attendance/RollCallMarks.php'), 'utf8')
const page = people.pages.find((p) => p.id === 'RollCall')
const content = page.widgets.find((w) => w.slot === 'body').props.content

/**
 * A PHP array constant of RollCallMarks, read from the source.
 *
 * @param {string} name The constant.
 * @return {string[]} Its string items.
 */
function phpList(name) {
	const match = marksPhp.match(new RegExp(`const ${name} = \\[([^\\]]*)\\]`))
	return [...match[1].matchAll(/'([^']+)'/g)].map((m) => m[1])
}

/**
 * A PHP integer constant of RollCallMarks, read from the source.
 *
 * @param {string} name The constant.
 * @return {number} Its value.
 */
function phpInt(name) {
	return Number(marksPhp.match(new RegExp(`const ${name} = (\\d+)`))[1])
}

const field = (name) => content.detailFields.find((f) => f.field === name)

test('the roll-call page is the row mark list over the roll-call endpoint', () => {
	assert.equal(page.type, 'custom')
	assert.equal(page.widgets.find((w) => w.slot === 'body').widgetKey, 'row-mark-list')
	assert.equal(content.load.url, '/apps/learniq/api/attendance/roll-call')
	assert.equal(content.save.url, content.load.url)
	assert.equal(content.save.rowsKey, 'marks')
	assert.deepEqual(Object.keys(content.save.body).sort(), ['cohortId', 'date', 'sessionId'])
	assert.equal(content.rowKey, 'learnerId')
	assert.equal(content.stateField, 'status')
	assert.equal(content.defaultState, 'present')
})

test('every mark is one the server stores, and each key is used once', () => {
	const statuses = phpList('STATUSES')
	for (const state of content.states) {
		assert.ok(statuses.includes(state.value), state.value)
	}
	const keys = content.states.map((state) => state.key)
	assert.equal(new Set(keys).size, keys.length)
})

test('a late mark starts at five minutes, up to the server bound', () => {
	const late = field('lateMinutes')
	assert.deepEqual(late.states, ['late'])
	assert.equal(late.default, 5)
	assert.deepEqual(late.quick, [5, 10, 15])
	assert.equal(late.min, 1)
	assert.equal(late.max, phpInt('MAX_LATE_MINUTES'))
	assert.equal(late.required, true)
})

test('an absence with permission needs a reason from the one register vocabulary', () => {
	const reason = field('absenceReasonKind')
	assert.deepEqual(reason.states, ['absent-excused'])
	assert.equal(reason.default, 'illness')
	assert.equal(reason.required, true)
	const options = reason.options.map((o) => o.value)
	assert.deepEqual(options, phpList('REASON_KINDS'))
	const stored = register.components.schemas.AttendanceRecord.properties.absenceReasonKind.enum.filter((v) => v !== null)
	assert.deepEqual(options, stored)
})

test('the note is optional, shown off present, within the server bound', () => {
	const note = field('reason')
	assert.deepEqual(note.notStates, ['present'])
	assert.notEqual(note.required, true)
	assert.equal(note.maxlength, phpInt('MAX_REASON_LENGTH'))
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
