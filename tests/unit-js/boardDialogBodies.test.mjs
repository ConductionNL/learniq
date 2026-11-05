// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// What the board dialogs (lane LQB-C, G5 and G11) send, and when they may send
// it. The dialogs bind fields and post what src/dialogs/dialogBodies.js
// builds, so these cases cover the writes without mounting a component.
//
// @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	attendeeList,
	availabilityBlocks,
	barrierBody,
	canCreateCohort,
	canDecideExemption,
	canGrantRegulationExemption,
	canRejectRegulationExemption,
	canReopen,
	canSaveConfidentialNote,
	canSaveDossierNote,
	canSaveGrade,
	canSendBarrier,
	canSendConcern,
	cohortBody,
	concernAudience,
	concernReportBody,
	conferenceReportBody,
	confidentialNoteBody,
	dayLabel,
	dayStart,
	decisionData,
	dossierNoteBody,
	eveningHalfHours,
	eveningSummary,
	exemptionSteps,
	gradeEntryCreateBody,
	groupEnrolBody,
	pickedFromBlocks,
	refusalNotice,
	reopenData,
	revocationReason,
	withdrawData,
} from '../../src/dialogs/dialogBodies.js'

const same = (local) => local

test('a concern from a pupil\'s page speaks to staff; otherwise the role decides', () => {
	assert.equal(concernAudience('vo-leerling-112', 'teacher'), 'staff')
	assert.equal(concernAudience('', 'guardian'), 'guardian')
	assert.equal(concernAudience('', 'learner'), 'learner')
	assert.equal(concernAudience('  ', 'teacher'), 'learner')
})

test('a concern carries topic, words and the wish to talk; date and pupil only when given', () => {
	assert.deepEqual(
		concernReportBody({ topic: 'bullying', description: '  Uitgescholden in de groepsapp. ', happenedOn: '', wantsConversation: true, learnerId: '' }),
		{ topic: 'bullying', description: 'Uitgescholden in de groepsapp.', wantsConversation: true },
	)
	assert.deepEqual(
		concernReportBody({ topic: 'other', description: 'x', happenedOn: '2026-10-05', wantsConversation: false, learnerId: 'vo-leerling-112' }),
		{ topic: 'other', description: 'x', wantsConversation: false, happenedOn: '2026-10-05', learnerId: 'vo-leerling-112' },
	)
	assert.equal(canSendConcern({ topic: 'bullying', description: ' ' }), false)
	assert.equal(canSendConcern({ topic: 'gossip', description: 'x' }), false)
	assert.equal(canSendConcern({ topic: 'violence', description: 'x' }), true)
})

test('a barrier report needs where, what and a known severity', () => {
	const form = { affectedSurface: ' Presentielijst ', description: 'Focus springt weg', severity: 'serious' }
	assert.deepEqual(barrierBody(form), { affectedSurface: 'Presentielijst', description: 'Focus springt weg', severity: 'serious' })
	assert.equal(canSendBarrier(form), true)
	assert.equal(canSendBarrier({ ...form, severity: null }), false)
	assert.equal(canSendBarrier({ ...form, affectedSurface: '' }), false)
})

test('a revocation reason is the chosen reason, then the explanation when there is one', () => {
	assert.equal(revocationReason('Fraude', ''), 'Fraude')
	assert.equal(revocationReason('Fraude', ' Diploma vervalst '), 'Fraude: Diploma vervalst')
})

test('a new group sends what is filled in, with its kind and tenant', () => {
	const body = cohortBody({
		name: ' 3HV-C ', academicYear: '2026-2027', period: 'Hele schooljaar', kind: 'care',
		programmeId: '', programmeYear: '3', teacherIds: ['vo-docent-08'], learnerIds: [], notes: '',
	}, 'tenant-1')
	assert.deepEqual(body, {
		name: '3HV-C', academicYear: '2026-2027', period: 'Hele schooljaar', kind: 'care',
		programmeYear: 3, teacherIds: ['vo-docent-08'], tenant_id: 'tenant-1',
	})
	assert.equal(cohortBody({ name: 'x', kind: 'sports' }, null).kind, 'teaching')
	assert.equal(canCreateCohort({ name: '3HV-C', academicYear: '2026-2027', period: '' }), false)
	assert.equal(canCreateCohort({ name: '3HV-C', academicYear: '2026-2027', period: 'Periode 1' }), true)
})

test('a dossier note takes its care team only when it is for the care team', () => {
	const form = { learnerId: 'vo-leerling-112', date: '2026-10-05', category: 'conversation', body: 'Gesprek', confidentiality: 'team-visible', careTeamUserIds: ['vo-zorgcoordinator-01'] }
	assert.equal('careTeamUserIds' in dossierNoteBody(form, 'vo-docent-08', null), false)
	assert.deepEqual(dossierNoteBody({ ...form, confidentiality: 'care-team-only' }, 'vo-docent-08', 't').careTeamUserIds, ['vo-zorgcoordinator-01'])
	assert.equal(dossierNoteBody(form, 'vo-docent-08', 't').authorId, 'vo-docent-08')
	assert.equal(canSaveDossierNote(form), true)
	assert.equal(canSaveDossierNote({ ...form, learnerId: '' }), false)
	assert.equal(canSaveDossierNote({ ...form, category: 'gossip' }), false)
})

test('a confidential note is open unless closed, and needs a case name and a note', () => {
	const body = confidentialNoteBody({ title: ' Pesten ', note: 'Eerste gesprek', participantIds: ['x'], status: 'weird', destroyAfter: '' }, 'counsellor', null)
	assert.deepEqual(body, { title: 'Pesten', note: 'Eerste gesprek', authorId: 'counsellor', participantIds: ['x'], status: 'open' })
	assert.equal(canSaveConfidentialNote({ title: 'x', note: '' }), false)
	assert.equal(canSaveConfidentialNote({ title: 'x', note: 'y' }), true)
})

test('an exemption still waiting goes into assessment before the decision', () => {
	assert.deepEqual(exemptionSteps('submitted', 'grant'), ['startAssessment', 'grant'])
	assert.deepEqual(exemptionSteps('in-assessment', 'reject'), ['reject'])
	assert.equal(canDecideExemption({ decision: 'grant', decisionRationale: '' }), false)
	assert.equal(canDecideExemption({ decision: 'grant', decisionRationale: 'DELF B2' }), true)
	assert.deepEqual(decisionData({ decisionRationale: ' DELF B2 ', policyReference: 'art. 14.2', validUntil: '' }), { decisionRationale: 'DELF B2', policyReference: 'art. 14.2' })
})

test('a regulation exemption is granted only with rationale, policy and end date', () => {
	const form = { decisionRationale: 'Bedrijfsarts', policyReference: 'Arbobeleid 4.2', validUntil: '2027-06-30' }
	assert.equal(canGrantRegulationExemption(form), true)
	assert.equal(canGrantRegulationExemption({ ...form, validUntil: '' }), false)
	assert.equal(canRejectRegulationExemption({ decisionRationale: 'Geen grond' }), true)
	assert.equal(canRejectRegulationExemption({ decisionRationale: ' ' }), false)
})

test('a withdrawal carries its reason and a reopen its new date', () => {
	assert.deepEqual(withdrawData('  Op advies van de mentor '), { withdrawReason: 'Op advies van de mentor' })
	assert.deepEqual(reopenData('2026-10-13T17:00', same), { resubmissionDueAt: '2026-10-13T17:00' })
	assert.equal(canReopen('2026-10-13T17:00', new Date('2026-10-10T12:00:00')), true)
	assert.equal(canReopen('2026-10-09T17:00', new Date('2026-10-10T12:00:00')), false)
	assert.equal(canReopen('', new Date('2026-10-10T12:00:00')), false)
})

test('a conference report names the slot\'s pupil and teacher and splits who were there', () => {
	assert.deepEqual(attendeeList('Moeder, vader , '), ['Moeder', 'vader'])
	const body = conferenceReportBody({
		slot: { learnerId: 'vo-leerling-112', learnerRef: 'ref-1', teacherId: 'vo-docent-08' },
		slotId: 'slot-1', narrative: '', attendees: 'Moeder', recordedBy: 'vo-docent-08', recordedAt: '2026-10-06T17:25:00Z', tenant: null,
	})
	assert.deepEqual(body, {
		conferenceSlotId: 'slot-1', learnerId: 'vo-leerling-112', teacherId: 'vo-docent-08',
		recordedBy: 'vo-docent-08', recordedAt: '2026-10-06T17:25:00Z', learnerRef: 'ref-1', attendeeIds: ['Moeder'],
	})
})

test('an evening offers its half hours and sums up the free ones as the board does', () => {
	assert.deepEqual(eveningHalfHours({ from: '18:30', until: '21:00' }), ['18:30', '19:00', '19:30', '20:00', '20:30'])
	assert.deepEqual(eveningSummary(['19:00', '18:30', '19:30'], 10, 5), { from: '18:30', until: '20:00', count: 6 })
	assert.deepEqual(eveningSummary(['19:00', '19:30', '20:00', '20:30'], 10, 5), { from: '19:00', until: '21:00', count: 8 })
	assert.equal(eveningSummary([], 10, 5), null)
})

test('neighbouring free half hours become one block, and blocks read back as half hours', () => {
	const blocks = availabilityBlocks({ '2026-11-10': ['19:00', '18:30', '20:30'], '2026-11-12': ['19:00'] }, same)
	assert.deepEqual(blocks, [
		{ startsAt: '2026-11-10T18:30:00', endsAt: '2026-11-10T19:30:00' },
		{ startsAt: '2026-11-10T20:30:00', endsAt: '2026-11-10T21:00:00' },
		{ startsAt: '2026-11-12T19:00:00', endsAt: '2026-11-12T19:30:00' },
	])
	assert.deepEqual(pickedFromBlocks(blocks), { '2026-11-10': ['18:30', '19:00', '20:30'], '2026-11-12': ['19:00'] })
	assert.deepEqual(pickedFromBlocks(null), {})
})

test('a grade falls back to the plan component and is saved only when complete', () => {
	const plan = { components: [{ componentId: 'c1', weight: 2, period: 1, gradeScaleId: 'scale-1' }] }
	const form = { learnerId: 'vo-leerling-112', curriculumPlanId: 'plan-1', componentId: 'c1', value: '5,3', weight: '', gradedAt: '2026-10-05' }
	const body = gradeEntryCreateBody(form, plan, 'vo-docent-08', 't')
	assert.equal(body.value, 5.3)
	assert.equal(body.weight, 2)
	assert.equal(body.period, '1')
	assert.equal(body.gradeScaleId, 'scale-1')
	assert.equal(body.grader, 'vo-docent-08')
	assert.equal(body.sourceKind, 'manual')
	assert.equal(body.gradedAt, '2026-10-05T00:00:00Z')
	assert.equal(canSaveGrade(form, plan), true)
	assert.equal(canSaveGrade({ ...form, value: 'acht' }, plan), false)
	assert.equal(dayStart(''), '')
})

test('a group enrolment names its audience and leaves empty extras out', () => {
	assert.deepEqual(groupEnrolBody({ audienceKind: 'team', audience: ' talen ', dueDate: '', message: 'Voor kerst' }), { audienceKind: 'team', audience: 'talen', message: 'Voor kerst' })
	assert.equal(groupEnrolBody({ audienceKind: 'everyone', audience: 'x' }).audienceKind, 'section')
})

test('a refusal is an alert banner and a day reads in the reader\'s language', () => {
	assert.deepEqual(refusalNotice('Niet verstuurd.', 'Probeer het later.'), { variant: 'error', role: 'alert', layout: 'stacked', title: 'Niet verstuurd.', text: 'Probeer het later.' })
	assert.equal(dayLabel('2025-10-14T12:00:00Z', 'nl'), '14 oktober 2025')
	assert.equal(dayLabel('', 'nl'), '')
})
