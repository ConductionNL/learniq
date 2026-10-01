// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// compliance-external-training-spreadsheet-upload: the browser half of the
// upload. The server checks each row; these cover reading the file and
// writing the failed rows back out.

import assert from 'node:assert/strict'
import test from 'node:test'
import {
	failedRowsCsv,
	isFailed,
	parseCsv,
	previewCounts,
	reasonText,
	rowsFromCsv,
} from '../../src/utils/externalTrainingUpload.js'

test('parseCsv reads quotes, doubled quotes, CRLF and a byte order mark', () => {
	const rows = parseCsv('﻿a,b\r\n"x, y","say ""hi"""\r\n\r\n"line\nbreak",z\n')
	assert.deepEqual(rows, [
		['a', 'b'],
		['x, y', 'say "hi"'],
		['line\nbreak', 'z'],
	])
})

test('parseCsv takes the semicolon a Dutch spreadsheet saves with', () => {
	assert.deepEqual(parseCsv('leerling;titel\nP1;BHV, herhaling'), [
		['leerling', 'titel'],
		['P1', 'BHV, herhaling'],
	])
})

test('rowsFromCsv maps English and Dutch headings and names what is missing or ignored', () => {
	const english = rowsFromCsv(
		'Email,Title,Provider,Completed on,Valid until,Regulation,Evidence note,Seat\nl1@s.nl,BHV,Oranje Kruis,2026-09-15,2027-09-15,bhv,,12\n',
	)
	assert.deepEqual(english.missing, [])
	assert.deepEqual(english.ignored, ['Seat'])
	assert.deepEqual(english.rows, [
		{
			learner: 'l1@s.nl',
			title: 'BHV',
			provider: 'Oranje Kruis',
			completedAt: '2026-09-15',
			validUntil: '2027-09-15',
			regulationSlug: 'bhv',
			evidenceNote: '',
		},
	])

	const dutch = rowsFromCsv('Deelnemer;Opleiding;Datum\nP1;EHBO;15-09-2026')
	assert.deepEqual(dutch.missing, ['provider'])
	assert.deepEqual(dutch.rows, [
		{ learner: 'P1', title: 'EHBO', completedAt: '15-09-2026' },
	])
	assert.deepEqual(rowsFromCsv('').missing, [
		'learner',
		'title',
		'provider',
		'completed on',
	])
})

test('the failed rows file holds only failed rows, with the reason, and reads back without it', () => {
	const rows = [
		{
			learner: 'P1',
			title: 'BHV',
			provider: 'Oranje Kruis',
			completedAt: '2026-09-15',
		},
		{
			learner: 'stranger@x.nl',
			title: '=HYPERLINK("x")',
			provider: 'Oranje Kruis',
			completedAt: '2026-09-15',
		},
		{
			learner: 'P3',
			title: 'BHV',
			provider: 'Oranje Kruis',
			completedAt: '2026-12-01',
		},
	]
	const report = [
		{ row: 1, status: 'created', reason: null },
		{
			row: 2,
			status: 'unmatched',
			reason: 'No learner in your organisation has this email address.',
		},
		{
			row: 3,
			status: 'duplicate',
			reason: 'The same learner, training and date are on row {row}.',
			reasonParams: { row: 1 },
		},
		{
			row: 3,
			status: 'invalid',
			reason: 'The completed on date is in the future.',
		},
	]
	const csv = failedRowsCsv(rows, report)
	const lines = csv.trim().split('\r\n')
	assert.equal(lines.length, 3)
	assert.match(lines[1], /^"stranger@x\.nl","'=HYPERLINK\(""x""\)"/)
	assert.match(lines[2], /"The completed on date is in the future\."$/)

	const again = rowsFromCsv(csv)
	assert.deepEqual(again.missing, [])
	assert.deepEqual(again.ignored, ['reason'])
	assert.equal(again.rows[1].learner, 'P3')
	assert.equal(again.rows[1].completedAt, '2026-12-01')
	assert.equal(failedRowsCsv(rows, [report[0]]).trim().split('\r\n').length, 1)
})

test('reasonText fills the placeholders and leaves unknown ones', () => {
	assert.equal(
		reasonText({ reason: 'On row {row}, {other}.', reasonParams: { row: 4 } }),
		'On row 4, {other}.',
	)
	assert.equal(reasonText(null), '')
})

test('previewCounts and isFailed sort the rows by what happens to them', () => {
	const report = [
		{ status: 'ready' },
		{ status: 'created' },
		{ status: 'unmatched' },
		{ status: 'invalid' },
		{ status: 'duplicate' },
		{ status: 'skipped' },
	]
	assert.deepEqual(previewCounts(report), { ready: 2, skipped: 2, failed: 2 })
	assert.equal(isFailed({ status: 'duplicate' }), false)
	assert.equal(isFailed(null), false)
	assert.deepEqual(previewCounts(null), { ready: 0, skipped: 0, failed: 0 })
})
