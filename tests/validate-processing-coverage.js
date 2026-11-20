#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// validate-processing-coverage.js — every schema in the register that holds
// personal data declares its processing (governance-processing-record-every-schema).
//
// A schema "holds personal data" when one of its properties is named in the
// personal-data field list below: a field that names or points at a person.
// Such a schema MUST carry an `x-openregister-processing` block with the
// record's fields, so the GDPR record of processing stays complete when the
// next schema is added. The check fails, naming each schema, otherwise.
//
// Usage:   node tests/validate-processing-coverage.js [register.json]
// Exit:    0 = every personal-data schema declares its processing   1 = one does not

'use strict'

const fs = require('fs')
const path = require('path')

/** Property names that name or point at a person. */
const PERSONAL_DATA_FIELDS = [
	'learnerId',
	'learnerRef',
	'learnerUserId',
	'learnerIds',
	'learnerRefs',
	'ncUserId',
	'teacherIds',
	'managerId',
	'employeeId',
	'parentIds',
	'guardianRefs',
	'givenName',
	'familyName',
	'birthDate',
	'markedBy',
	'submittedBy',
	'email',
	'phone',
]

/** The fields a processing block MUST carry for the record. */
const REQUIRED_FIELDS = ['code', 'naam', 'doelbinding', 'rechtsgrond', 'dataCategories', 'retentionReference']

/**
 * The problems of one register: a personal-data schema without a block, or a
 * block missing a field of the record.
 *
 * @param {object} register The decoded register.
 * @return {string[]} One line per problem.
 */
function problemsOf(register) {
	const schemas = register?.components?.schemas || {}
	const problems = []
	for (const [name, schema] of Object.entries(schemas)) {
		const properties = Object.keys(schema?.properties || {})
		const personal = properties.filter((property) => PERSONAL_DATA_FIELDS.includes(property))
		if (personal.length === 0) continue

		const block = schema['x-openregister-processing']
		if (!block || typeof block !== 'object' || Array.isArray(block)) {
			problems.push(`${name} holds personal data and declares no x-openregister-processing (${personal.join(', ')})`)
			continue
		}

		const missing = REQUIRED_FIELDS.filter((field) => block[field] === undefined || block[field] === null || block[field] === '')
		if (missing.length > 0) {
			problems.push(`${name} declares x-openregister-processing without ${missing.join(', ')}`)
		}
	}
	return problems
}

module.exports = { PERSONAL_DATA_FIELDS, REQUIRED_FIELDS, problemsOf }

if (require.main === module) {
	const file = process.argv[2] || path.resolve(__dirname, '..', 'lib', 'Settings', 'learniq_register.json')
	const register = JSON.parse(fs.readFileSync(file, 'utf8'))
	const problems = problemsOf(register)
	if (problems.length > 0) {
		console.error(`processing-coverage: FAIL — ${problems.length} schema(s) hold personal data without a complete processing declaration:`)
		for (const problem of problems) console.error(`  • ${problem}`)
		process.exit(1)
	}
	console.log('processing-coverage: every personal-data schema declares its processing')
}
