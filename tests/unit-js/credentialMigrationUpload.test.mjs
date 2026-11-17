// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// @spec openspec/changes/credentials-migrate-with-validity/specs/certification/spec.md#requirement-certificates-from-the-previous-system-are-uploaded-with-a-dry-run-first

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { canTakeOver, MIGRATE_URL, migrationRowsFromCsv } from '../../src/utils/credentialMigrationUpload.js'

test('a Dutch export maps onto the migration fields', () => {
	const csv = 'E-mailadres;Cursuscode;Behaald op;Geldig tot;Certificaatnummer;Uitgevende instantie;Afdeling\n'
		+ 'jan@example.nl;VCA;2023-03-01;2028-03-01;VCA-2023-0112;SSVV;Spoor\n'
	const parsed = migrationRowsFromCsv(csv)

	assert.deepEqual(parsed.missing, [])
	assert.deepEqual(parsed.ignored, ['Afdeling'])
	assert.deepEqual(parsed.rows, [{
		learner: 'jan@example.nl',
		course: 'VCA',
		issuedAt: '2023-03-01',
		expiresAt: '2028-03-01',
		legacyNumber: 'VCA-2023-0112',
		legacyIssuer: 'SSVV',
	}])
})

test('a regulation column stands in for a course code', () => {
	const parsed = migrationRowsFromCsv('learner,regulation,issued on,certificate number\np-1,nis2,2025-05-05,9\n')

	assert.deepEqual(parsed.missing, [])
	assert.deepEqual(parsed.rows, [{ learner: 'p-1', regulation: 'nis2', issuedAt: '2025-05-05', legacyNumber: '9' }])
})

test('missing columns are named, including the course or regulation', () => {
	const parsed = migrationRowsFromCsv('learner,valid until\np-1,2028-01-01\n')

	assert.deepEqual(parsed.missing, ['issued on', 'certificate number', 'course code / regulation'])
})

test('only a dry run with ready rows can be taken over', () => {
	assert.equal(canTakeOver(null), false)
	assert.equal(canTakeOver({ commit: false, summary: { ready: 0 } }), false)
	assert.equal(canTakeOver({ commit: true, summary: { ready: 2 } }), false)
	assert.equal(canTakeOver({ commit: false, summary: { ready: 2 } }), true)
	assert.equal(MIGRATE_URL, '/apps/learniq/api/credentials/migrate')
})
