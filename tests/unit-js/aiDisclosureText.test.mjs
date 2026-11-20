// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// ai-disclosure-for-every-user: every signed-in user reads the school's part
// of the AI disclosure; only an admin or compliance officer gets the edit
// mode. These pin src/utils/aiDisclosureText.js, which
// LearniqAiProcessingDisclosure.vue uses for both.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	canEditDisclosure,
	disclosureTextForm,
	disclosureTextFromRecord,
	disclosureTextPayload,
	featureNote,
} from '../../src/utils/aiDisclosureText.js'

test('only an admin or compliance officer gets the edit mode', () => {
	assert.equal(canEditDisclosure('admin'), true)
	assert.equal(canEditDisclosure('compliance-officer'), true)
	for (const reader of ['learner', 'instructor', 'guardian', 'hr', 'team-lead', 'coordinator', 'administration-manager', '', undefined]) {
		assert.equal(canEditDisclosure(reader), false, String(reader))
	}
})

test('no record yet reads as an empty text, not an error', () => {
	assert.deepEqual(disclosureTextFromRecord(null), { id: null, introduction: null, contact: null, featureNotes: {} })
})

test('a stored record is read with its id, and empty fields and notes dropped', () => {
	assert.deepEqual(
		disclosureTextFromRecord({
			'@self': { id: 'd1' },
			introduction: 'We use AI for proctoring review.',
			contact: '  ',
			featureNotes: { 'assessment-ai-proctor-review': 'A person always decides.', empty: '', bad: 3 },
		}),
		{
			id: 'd1',
			introduction: 'We use AI for proctoring review.',
			contact: null,
			featureNotes: { 'assessment-ai-proctor-review': 'A person always decides.' },
		},
	)
	assert.deepEqual(disclosureTextFromRecord({ id: 'd2', featureNotes: ['not', 'a', 'map'] }).featureNotes, {})
})

test('the form has a field for every listed feature and keeps notes for unlisted ones', () => {
	const text = { id: 'd1', introduction: null, contact: 'dpo@school.example', featureNotes: { retired: 'Old note.', a: 'Note A.' } }
	assert.deepEqual(disclosureTextForm(text, [{ slug: 'a' }, { slug: 'b' }, { name: 'no slug' }]), {
		introduction: '',
		contact: 'dpo@school.example',
		featureNotes: { a: 'Note A.', b: '', retired: 'Old note.' },
	})
})

test('the payload trims, nulls empty fields, leaves out empty notes and adds the id only when there is one', () => {
	assert.deepEqual(
		disclosureTextPayload({ introduction: '  Hello  ', contact: '', featureNotes: { a: ' Note ', b: '' } }, null),
		{ introduction: 'Hello', contact: null, featureNotes: { a: 'Note' } },
	)
	assert.deepEqual(
		disclosureTextPayload({ introduction: '', contact: 'dpo', featureNotes: {} }, 'd1'),
		{ introduction: null, contact: 'dpo', featureNotes: {}, id: 'd1' },
	)
})

test('the payload never carries a composed fact', () => {
	const payload = disclosureTextPayload(
		{ introduction: 'x', contact: 'y', featureNotes: {}, verified: true, policyCompliant: true, lifecycle: 'approved' },
		'd1',
	)
	assert.deepEqual(Object.keys(payload).sort(), ['contact', 'featureNotes', 'id', 'introduction'])
})

test('a feature note is read by slug, null when there is none', () => {
	const text = disclosureTextFromRecord({ id: 'd1', featureNotes: { a: 'Note A.' } })
	assert.equal(featureNote(text, 'a'), 'Note A.')
	assert.equal(featureNote(text, 'b'), null)
	assert.equal(featureNote(null, 'a'), null)
})
