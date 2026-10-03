// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// absence-and-booking-fields-are-required: a staff booking names the pupil's
// learner profile, because the schema requires it before any listener runs.
//
// @spec openspec/changes/absence-and-booking-fields-are-required/specs/parent-conferences/spec.md#requirement-a-conference-signup-names-its-pupils-learner-profile

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { conferenceSignupBody } from '../../src/utils/conferenceSignupBody.js'

test('a staff booking sends the round, the pupil, the pupil profile and the tenant', () => {
	const body = conferenceSignupBody({
		roundId: 'round-1',
		round: { id: 'round-1', tenant_id: 'tenant-1' },
		learner: { id: 'profile-vera', ncUserId: 'po-leerling-147' },
		teacherIds: ['po-leerkracht-07'],
		notes: '',
	})

	assert.equal(body.conferenceRoundId, 'round-1')
	assert.equal(body.learnerId, 'po-leerling-147')
	assert.equal(body.learnerRef, 'profile-vera')
	assert.equal(body.tenant_id, 'tenant-1')
	assert.equal(body.notes, null)
	assert.equal(body.requestedTeacherIds.length, 1)
})

test('a profile known only by uuid still names the pupil', () => {
	const body = conferenceSignupBody({
		roundId: 'round-2',
		round: null,
		learner: { uuid: 'profile-sami', ncUserId: 'po-leerling-467' },
		teacherIds: [],
		notes: 'Graag na drie uur',
	})

	assert.equal(body.learnerRef, 'profile-sami')
	assert.equal(body.conferenceRoundId, 'round-2')
	assert.equal(body.tenant_id, undefined)
	assert.equal(body.notes, 'Graag na drie uur')
})

test('without a pupil there is no profile to send, so the server refuses it', () => {
	const body = conferenceSignupBody({
		roundId: 'round-1',
		round: { id: 'round-1' },
		learner: null,
		teacherIds: [],
		notes: '',
	})

	assert.equal(body.learnerRef, '')
	assert.equal(body.learnerId, '')
})
