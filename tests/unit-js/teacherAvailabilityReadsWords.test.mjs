// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The teacher availability list reads words (lq-names, 2026-10-04). It
// showed every property: the round as a uuid, the teacher as a Nextcloud user
// id and the free time blocks as stored JSON. It now names the round, the
// teacher and the times ("do 15 okt, 18:00–20:00").
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
// @spec openspec/changes/teacher-availability-reads-words/specs/parent-conferences/spec.md

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { createFormatters, formatTimeBlocks } from '../../src/utils/timeBlocks.js'

function readJson(rel) {
	return JSON.parse(readFileSync(new URL(rel, import.meta.url), 'utf8'))
}
const REGISTER = readJson('../../lib/Settings/learniq_register.json')
const NL = readJson('../../l10n/nl.json').translations
const SCHEMAS = Object.fromEntries(
	Object.values(REGISTER.components.schemas).map((s) => [s.slug, s]),
)
const PAGES = readJson('../../src/manifest.d/guardian-meetings.json').pages
const page = (id) => PAGES.find((p) => p.id === id)
const AMSTERDAM = { locale: 'nl-NL', timeZone: 'Europe/Amsterdam' }

test('a block reads as a day and two times in Dutch', () => {
	assert.equal(
		formatTimeBlocks(
			[
				{
					startsAt: '2026-10-15T16:00:00+00:00',
					endsAt: '2026-10-15T18:00:00+00:00',
				},
			],
			AMSTERDAM,
		),
		'do 15 okt, 18:00–20:00',
	)
})

test('several blocks are separated, and a block over midnight names both days', () => {
	assert.equal(
		formatTimeBlocks(
			[
				{ startsAt: '2026-10-15T16:00:00Z', endsAt: '2026-10-15T18:00:00Z' },
				{ startsAt: '2026-10-16T21:00:00Z', endsAt: '2026-10-16T22:30:00Z' },
			],
			AMSTERDAM,
		),
		'do 15 okt, 18:00–20:00; vr 16 okt, 23:00–za 17 okt, 00:30',
	)
})

test('the reader reads it in their own language', () => {
	assert.equal(
		formatTimeBlocks(
			[{ startsAt: '2026-10-15T16:00:00Z', endsAt: '2026-10-15T18:00:00Z' }],
			{ locale: 'en-GB', timeZone: 'Europe/Amsterdam' },
		),
		'Thu 15 Oct, 18:00–20:00',
	)
})

test('nothing readable gives nothing, never JSON', () => {
	assert.equal(formatTimeBlocks(null, AMSTERDAM), '')
	assert.equal(formatTimeBlocks('[]', AMSTERDAM), '')
	assert.equal(formatTimeBlocks([], AMSTERDAM), '')
	assert.equal(formatTimeBlocks([{ startsAt: 'later' }, null], AMSTERDAM), '')
	assert.equal(
		formatTimeBlocks([{ startsAt: '2026-10-15T16:00:00Z' }], AMSTERDAM),
		'do 15 okt, 18:00',
	)
})

test('the formatter learniq hands CnAppRoot reads the reader locale', () => {
	const { timeBlocks } = createFormatters(() => 'nl-NL')
	assert.match(
		timeBlocks([
			{ startsAt: '2026-10-15T12:00:00Z', endsAt: '2026-10-15T13:00:00Z' },
		]),
		/^do 15 okt, \d\d:00–\d\d:00$/,
	)
})

test('the teacher availability list names the round, the teacher and the times', () => {
	const columns = page('TeacherAvailabilities').config.columns
	const schema = SCHEMAS['teacher-availability']
	assert.deepEqual(
		columns.map((c) => c.key),
		['conferenceRoundId', 'teacherName', 'blocks', 'lifecycle'],
	)
	for (const column of columns) {
		assert.ok(
			column.key in schema.properties,
			`${column.key} is not on the schema`,
		)
		assert.ok(NL[column.label], `column "${column.label}" has no Dutch entry`)
	}
	const round = columns[0]
	assert.equal(schema.properties.conferenceRoundId.$ref, 'ConferenceRound')
	assert.equal(round.widget, 'fkResolve')
	assert.deepEqual(round.widgetProps, {
		register: 'learniq',
		schema: 'conference-round',
	})
	assert.ok('name' in SCHEMAS['conference-round'].properties)
	assert.equal(columns[2].formatter, 'timeBlocks')
	assert.equal(schema.properties.teacherName.type, 'string')
})

test('the availability page leaves the user id and tenant out', () => {
	const data = page('TeacherAvailabilityDetail').config.widgets.find(
		(w) => w.id === 'ta-data',
	).content
	assert.deepEqual(data.include, [
		'teacherName',
		'conferenceRoundId',
		'lifecycle',
		'blocks',
	])
	for (const key of data.include) {
		const title = SCHEMAS['teacher-availability'].properties[key].title
		assert.ok(NL[title], `field "${title}" has no Dutch entry`)
	}
})
