// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The public certificate page's verdict from each answer of
// GET /api/credentials/{id}/verify (certificate-verification-and-key-rotation).
// @spec openspec/changes/certificate-verification-and-key-rotation/specs/certification/spec.md#requirement-the-verification-link-opens-a-page-that-says-in-words-what-was-checked
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { CHECKS, credentialVerdict } from '../../src/services/credentialVerdict.js'

const NOW = new Date('2026-11-14T10:00:00Z')

test('a valid certificate is real and valid, with every check passed', () => {
	const verdict = credentialVerdict(200, {
		valid: true, issuedAt: '2026-06-01', expiresAt: null, issuerName: 'De Regenboog',
		title: 'Certificaat rekenen 2F', holderName: 'Fenna de Jong',
	}, NOW)
	assert.equal(verdict.state, 'valid')
	assert.deepEqual(verdict.checks, { signature: 'passed', notRevoked: 'passed', notExpired: 'passed', unchanged: 'passed' })
	assert.equal(verdict.title, 'Certificaat rekenen 2F')
	assert.equal(verdict.holderName, 'Fenna de Jong')
	assert.equal(verdict.issuerName, 'De Regenboog')
	assert.equal(verdict.expiresAt, null)
	assert.equal(verdict.checkedAt, NOW.toISOString())
})

test('the four checks are listed in the order the page shows them', () => {
	assert.deepEqual(CHECKS, ['signature', 'notRevoked', 'notExpired', 'unchanged'])
})

test('an expired certificate keeps its title and name and fails only the expiry check', () => {
	const verdict = credentialVerdict(200, {
		valid: false, issuedAt: '2025-09-01', expiresAt: '2026-09-01', issuerName: 'De Regenboog',
		title: 'Certificaat rekenen 2F', holderName: 'Fenna de Jong',
	}, NOW)
	assert.equal(verdict.state, 'expired')
	assert.equal(verdict.expiresAt, '2026-09-01')
	assert.equal(verdict.title, 'Certificaat rekenen 2F')
	assert.deepEqual(verdict.checks, { signature: 'passed', notRevoked: 'passed', notExpired: 'failed', unchanged: 'passed' })
})

test('a revoked certificate shows the date and reason and never a title or a name', () => {
	const verdict = credentialVerdict(200, {
		valid: false, revokedAt: '2026-10-02', revocationReason: 'issued in error',
		title: 'Certificaat rekenen 2F', holderName: 'Fenna de Jong',
	}, NOW)
	assert.equal(verdict.state, 'revoked')
	assert.equal(verdict.revokedAt, '2026-10-02')
	assert.equal(verdict.revocationReason, 'issued in error')
	assert.equal(verdict.title, null)
	assert.equal(verdict.holderName, null)
	assert.deepEqual(verdict.checks, { signature: 'notRun', notRevoked: 'failed', notExpired: 'notRun', unchanged: 'notRun' })
})

test('a failed signature is not genuine and fails the content check with it', () => {
	const verdict = credentialVerdict(200, { valid: false, error: 'signature_invalid', title: 'x', holderName: 'y' }, NOW)
	assert.equal(verdict.state, 'notGenuine')
	assert.equal(verdict.title, null)
	assert.equal(verdict.holderName, null)
	assert.deepEqual(verdict.checks, { signature: 'failed', notRevoked: 'passed', notExpired: 'notRun', unchanged: 'failed' })
})

test('an unknown id finds no certificate and shows no title and no name', () => {
	const verdict = credentialVerdict(404, { valid: false, error: 'not_found' }, NOW)
	assert.equal(verdict.state, 'notFound')
	assert.equal(verdict.title, null)
	assert.equal(verdict.holderName, null)
	assert.equal(verdict.checks, null)
})

test('a server fault or no answer is unavailable, not a verdict about the certificate', () => {
	assert.equal(credentialVerdict(500, { valid: false, error: 'verification_unavailable' }, NOW).state, 'unavailable')
	assert.equal(credentialVerdict(0, null, NOW).state, 'unavailable')
	assert.equal(credentialVerdict(200, { something: 'else' }, NOW).state, 'unavailable')
})

test('a signed but not issued certificate is not valid without a title or a name', () => {
	const verdict = credentialVerdict(200, { valid: false, issuedAt: null, expiresAt: '2027-01-01', title: 'x', holderName: 'y' }, NOW)
	assert.equal(verdict.state, 'notValid')
	assert.equal(verdict.title, null)
	assert.equal(verdict.holderName, null)
	assert.equal(verdict.checks.notExpired, 'notRun')
})
