// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The administration's Today: the registration that asks for attention, the
// days to issue, the certificates expiring before 1 January and the coming
// exams. Run by `npm run test:js-unit`.
//
// @spec openspec/specs/certification/spec.md#scenario-youssefs-birth-date

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	comingExamRows,
	expiringRows,
	hasAnything,
	issueRows,
	registrationCard,
} from '../../src/utils/administrationToday.js'

const ANSWER = {
	today: '2026-10-05',
	first: {
		id: 'l-8',
		closingLine: 'De aanmelding voor het examen van donderdag sluit woensdag om 12.00 uur.',
		summary: '10 van de 11 deelnemers zijn compleet.',
		incomplete: [{
			enrolmentId: 'e-youssef',
			sentence: 'Van Youssef El Amrani (Jansen Installatietechniek) ontbreekt de geboortedatum.',
			notice: 'De werkgever kreeg vanochtend bericht.',
			contactName: 'Linda Jansen',
		}],
	},
	toIssue: [{ cohortId: 'c-1', name: 'F-gassen 1 okt', dayDate: '2026-10-01' }],
	expiring: {
		before: '2027-01-01',
		rows: [
			{ credentialId: 'k-2', learnerName: 'Sanne de Wit', courseName: 'F-gassen', expiresOn: '2026-10-20', renewal: { enrolmentId: 'e-r' }, renewalLine: 'Herhaling op donderdag 15 oktober' },
			{ credentialId: 'k-3', learnerName: 'Eva', courseName: 'BRL 100', expiresOn: '2026-12-15', renewal: null, renewalLine: 'Geen herhaling geboekt' },
		],
	},
	comingExams: [{ registrationId: 'l-8', examDate: '2026-10-08', courseName: 'F-gassen', bodyName: 'Examenbureau', participantCount: 11 }],
}

test('the card reads the closing moment, how many are complete and who is not', () => {
	assert.deepEqual(registrationCard(ANSWER), {
		id: 'l-8',
		closingLine: 'De aanmelding voor het examen van donderdag sluit woensdag om 12.00 uur.',
		summary: '10 van de 11 deelnemers zijn compleet.',
		people: [{
			key: 'e-youssef',
			sentence: 'Van Youssef El Amrani (Jansen Installatietechniek) ontbreekt de geboortedatum.',
			notice: 'De werkgever kreeg vanochtend bericht.',
			contactName: 'Linda Jansen',
		}],
	})
	assert.equal(registrationCard({ ...ANSWER, first: null }), null)
	assert.equal(registrationCard(null), null)
})

test('the lists read the days to issue, the expiring certificates and the coming exams', () => {
	assert.deepEqual(issueRows(ANSWER, 'en-GB'), [{ id: 'c-1', name: 'F-gassen 1 okt', date: '1 October' }])
	assert.deepEqual(expiringRows(ANSWER, 'nl').map((row) => [row.learner, row.expires, row.renewal, row.booked]), [
		['Sanne de Wit', '20 oktober', 'Herhaling op donderdag 15 oktober', true],
		['Eva', '15 december', 'Geen herhaling geboekt', false],
	])
	assert.deepEqual(comingExamRows(ANSWER, 'en-GB'), [{ id: 'l-8', date: '8 October', course: 'F-gassen', body: 'Examenbureau', participants: 11 }])
})

test('an empty answer shows nothing', () => {
	assert.equal(hasAnything(ANSWER), true)
	assert.equal(hasAnything({ first: null, toIssue: [], expiring: { rows: [] }, comingExams: [] }), false)
	assert.equal(hasAnything(null), false)
	assert.deepEqual(issueRows(null, 'en'), [])
	assert.deepEqual(expiringRows({ expiring: null }, 'en'), [])
})
