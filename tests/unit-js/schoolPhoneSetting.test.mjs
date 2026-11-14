// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The school's phone number for the public certificate page: what the field
// accepts, what it loads and the exact body it saves.
// @spec openspec/changes/certificate-verification-and-key-rotation/specs/certification/spec.md#requirement-the-verification-link-opens-a-page-that-says-in-words-what-was-checked
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { loadSchoolPhone, MAX_PHONE_LENGTH, parseSchoolPhone, saveSchoolPhone } from '../../src/services/schoolPhoneSetting.js'

/**
 * An endpoint answering with the given body and recording calls.
 *
 * @param {object} data The answer body.
 * @return {object}
 */
function endpoint(data) {
	const calls = []
	return {
		calls,
		url: '/settings',
		http: {
			get: async (...args) => { calls.push(['get', ...args]); return { data } },
			put: async (...args) => { calls.push(['put', ...args]); return { data } },
		},
	}
}

test('the field accepts a phone number, trimmed, and an empty field', () => {
	assert.equal(parseSchoolPhone('  +31 (0)20-123.45.67 '), '+31 (0)20-123.45.67')
	assert.equal(parseSchoolPhone(''), '')
	assert.equal(parseSchoolPhone(undefined), '')
	assert.equal(parseSchoolPhone('1'.repeat(MAX_PHONE_LENGTH)), '1'.repeat(MAX_PHONE_LENGTH))
})

test('the field refuses what is no phone number', () => {
	assert.equal(parseSchoolPhone('https://example.org'), null)
	assert.equal(parseSchoolPhone('(-)'), null)
	assert.equal(parseSchoolPhone('020+123'), null)
	assert.equal(parseSchoolPhone('1'.repeat(MAX_PHONE_LENGTH + 1)), null)
})

test('loading reads the stored number, and a stored non-number as empty', async () => {
	const ok = endpoint({ schoolPhone: '020 123 45 67' })
	assert.equal(await loadSchoolPhone(ok), '020 123 45 67')
	assert.deepEqual(ok.calls, [['get', '/settings']])
	assert.equal(await loadSchoolPhone(endpoint({ schoolPhone: '<b>' })), '')
	assert.equal(await loadSchoolPhone(endpoint({})), '')
})

test('saving puts only the number and checks the server stored it', async () => {
	const ok = endpoint({ config: { schoolPhone: '020 123 45 67' } })
	assert.equal(await saveSchoolPhone(ok, '020 123 45 67'), '020 123 45 67')
	assert.deepEqual(ok.calls, [['put', '/settings', { schoolPhone: '020 123 45 67' }]])
	await assert.rejects(saveSchoolPhone(endpoint({ config: {} }), '020 123 45 67'), /did not store/)
})
