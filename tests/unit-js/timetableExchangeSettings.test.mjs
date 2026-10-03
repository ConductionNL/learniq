// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The group code maps the admin settings section edits
// (timetable-connection-and-import-screen).
// Run via `node --test tests/unit-js/` (package.json's `test:js-unit`).

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import {
	mapsToRows,
	rowsToMaps,
	SOURCE_LABELS,
} from '../../src/utils/timetableExchangeSettings.js'

test('maps become rows per source and back, empty rows dropped', () => {
	const sources = ['roster-zermelo', 'roster-xedule']
	const rows = mapsToRows(sources, { 'roster-zermelo': { '4H1': 'c-1' } })
	assert.deepEqual(rows, {
		'roster-zermelo': [{ code: '4H1', cohortId: 'c-1' }],
		'roster-xedule': [],
	})
	rows['roster-zermelo'].push({ code: ' 4H2 ', cohortId: 'c-2' })
	rows['roster-xedule'].push({ code: '', cohortId: 'c-3' })
	assert.deepEqual(rowsToMaps(rows), {
		'roster-zermelo': { '4H1': 'c-1', '4H2': 'c-2' },
		'roster-xedule': {},
	})
})

test('every source the server knows has a label', () => {
	const php = readFileSync(
		new URL('../../lib/Service/TimetableExchangeSettings.php', import.meta.url),
		'utf8',
	)
	const ids = [...php.matchAll(/'(roster-[a-z-]+)'/g)].map((m) => m[1])
	assert.ok(ids.length >= 4)
	for (const id of ids) {
		assert.ok(SOURCE_LABELS[id], id)
	}
})
