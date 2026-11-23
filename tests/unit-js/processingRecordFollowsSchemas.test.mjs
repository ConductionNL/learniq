// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// A schema that is added or changed reaches the record of processing without
// anyone editing the record. OpenRegister builds the record from the
// `x-openregister-processing` blocks when the register is imported; learniq's
// half is that every schema holding personal data carries a complete block,
// and that `npm run check:register` refuses a release in which one does not.
// These tests run the requirement's two scenarios against that check.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
//
// @spec openspec/changes/capability-rows-say-what-they-do/specs/avg-verwerkingsregister/spec.md#requirement-a-schema-that-is-added-or-changed-reaches-the-record-of-processing-without-anyone-editing-the-record

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { test } from 'node:test'

const require = createRequire(import.meta.url)
const { problemsOf } = require('../validate-processing-coverage.js')

/**
 * The shipped register, freshly parsed so a test may change its copy.
 *
 * @return {object} The decoded register.
 */
function register() {
	return JSON.parse(readFileSync(new URL('../../lib/Settings/learniq_register.json', import.meta.url), 'utf8'))
}

/**
 * A processing block naming the given activity, with every field of the record.
 *
 * @param {string} code The activity code.
 * @param {string} naam The activity name.
 * @param {string[]} dataCategories The personal-data fields.
 * @return {object} The block.
 */
function block(code, naam, dataCategories) {
	return {
		code,
		naam,
		doelbinding: 'Begeleiding van de leerling',
		rechtsgrond: 'Wettelijke verplichting',
		dataCategories,
		retentionReference: 'not declared',
		attribution: { default: code },
	}
}

test('the shipped register declares the processing of every schema that holds personal data', () => {
	assert.deepEqual(problemsOf(register()), [])
})

test('a new schema with a processing block passes the check and names its activity for the record', () => {
	const changed = register()
	changed.components.schemas.MentorMeeting = {
		properties: { learnerId: { type: 'string' }, notes: { type: 'string' } },
		'x-openregister-processing': block('learniq-care-and-support', 'Leerlingbegeleiding', ['learnerId']),
	}

	assert.deepEqual(problemsOf(changed), [])
	assert.equal(changed.components.schemas.MentorMeeting['x-openregister-processing'].naam, 'Leerlingbegeleiding')
})

test('a new schema without a processing block is refused, named with its personal-data fields', () => {
	const changed = register()
	changed.components.schemas.MentorMeeting = {
		properties: { learnerId: { type: 'string' }, givenName: { type: 'string' } },
	}

	assert.deepEqual(problemsOf(changed), [
		'MentorMeeting holds personal data and declares no x-openregister-processing (learnerId, givenName)',
	])
})

test('a changed block keeps passing with its new data category, and a block that loses a field of the record is refused', () => {
	const changed = register()
	const [name, schema] = Object.entries(changed.components.schemas)
		.find(([, candidate]) => candidate['x-openregister-processing']?.code === 'learniq-care-and-support')
	const processing = schema['x-openregister-processing']
	processing.dataCategories = [...processing.dataCategories, 'health']

	assert.deepEqual(problemsOf(changed), [])

	delete processing.rechtsgrond
	assert.deepEqual(problemsOf(changed), [`${name} declares x-openregister-processing without rechtsgrond`])
})
