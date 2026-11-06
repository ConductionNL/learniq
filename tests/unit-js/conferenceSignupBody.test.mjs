// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// A conference signup request names the round, the pupil, the teachers and
// a note; the server stamps the tenant and the pupil's learner profile.
//
// @spec openspec/specs/parent-conferences/spec.md#requirement-a-guardian-or-self-signup-submission-is-gated-by-a-per-object-authorization-guard

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { conferenceSignupBody } from '../../src/utils/conferenceSignupBody.js'

test('a booking sends the round, the pupil, the teachers and the note, nothing the server stamps', () => {
	const body = conferenceSignupBody({
		roundId: 'round-1',
		learnerId: 'po-leerling-147',
		teacherIds: ['po-leerkracht-07'],
		notes: 'Graag na drie uur',
	})

	assert.deepEqual(body, {
		conferenceRoundId: 'round-1',
		learnerId: 'po-leerling-147',
		requestedTeacherIds: ['po-leerkracht-07'],
		notes: 'Graag na drie uur',
	})
})

test('an empty note is sent as null and a missing pupil as an empty id', () => {
	const body = conferenceSignupBody({ roundId: 'round-2', learnerId: undefined, teacherIds: [], notes: '' })

	assert.equal(body.notes, null)
	assert.equal(body.learnerId, '')
	assert.equal('tenant_id' in body, false)
	assert.equal('learnerRef' in body, false)
})
