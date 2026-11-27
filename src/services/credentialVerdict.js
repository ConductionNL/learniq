// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// What the public certificate page says, from the answer of
// GET /api/credentials/{id}/verify (certificate-verification-and-key-rotation).
// Pure: no HTTP, no l10n, so the page and its tests read the same verdict.
//
// The route checks in a fixed order: found, then revoked, then the signature,
// then lifecycle and expiry. A check after the one that failed was not run,
// so the page says so instead of claiming it passed.
//
// @spec openspec/changes/certificate-verification-and-key-rotation/specs/certification/spec.md#requirement-the-verification-link-opens-a-page-that-says-in-words-what-was-checked

/** The four checks the page lists, in the order it lists them. */
export const CHECKS = ['signature', 'notRevoked', 'notExpired', 'unchanged']

/** A check passed, failed, or was not run. */
export const PASSED = 'passed'
export const FAILED = 'failed'
export const NOT_RUN = 'notRun'

/**
 * Whether a date lies before now.
 *
 * @param {string|null|undefined} value An ISO date.
 * @param {Date} now The moment of the check.
 * @return {boolean}
 */
function isPast(value, now) {
	if (!value) {
		return false
	}
	const time = Date.parse(value)
	return !Number.isNaN(time) && time < now.getTime()
}

/**
 * The checks with one outcome each.
 *
 * @param {string} signature Outcome of the signature check.
 * @param {string} notRevoked Outcome of the revocation check.
 * @param {string} notExpired Outcome of the expiry check.
 * @return {{signature: string, notRevoked: string, notExpired: string, unchanged: string}}
 */
function checks(signature, notRevoked, notExpired) {
	// The signature covers the content, so "not changed" stands or falls with it.
	return { signature, notRevoked, notExpired, unchanged: signature }
}

/**
 * The page's verdict for one answer of the route.
 *
 * state is one of: valid, expired, revoked, notGenuine, notValid, notFound,
 * unavailable. title and holderName are kept only for a valid or an expired
 * certificate, whatever the answer carries.
 *
 * @param {number} status The HTTP status of the answer (0 when there was none).
 * @param {object|null} body The JSON answer.
 * @param {Date} now The moment of the check.
 * @return {object} The verdict.
 * @spec openspec/changes/certificate-verification-and-key-rotation/specs/certification/spec.md#requirement-the-verification-link-opens-a-page-that-says-in-words-what-was-checked
 */
export function credentialVerdict(status, body, now = new Date()) {
	const answer = body && typeof body === 'object' ? body : {}
	const base = {
		checkedAt: now.toISOString(),
		title: null,
		holderName: null,
		issuerName: null,
		issuedAt: null,
		expiresAt: null,
		revokedAt: null,
		revocationReason: null,
		checks: null,
	}

	if (status === 404 || answer.error === 'not_found') {
		return { ...base, state: 'notFound' }
	}
	if (status !== 200) {
		return { ...base, state: 'unavailable' }
	}
	if (answer.error === 'signature_invalid') {
		return { ...base, state: 'notGenuine', checks: checks(FAILED, PASSED, NOT_RUN) }
	}
	if ('revokedAt' in answer || 'revocationReason' in answer) {
		return {
			...base,
			state: 'revoked',
			revokedAt: answer.revokedAt ?? null,
			revocationReason: answer.revocationReason ?? null,
			checks: checks(NOT_RUN, FAILED, NOT_RUN),
		}
	}
	if (typeof answer.valid !== 'boolean') {
		return { ...base, state: 'unavailable' }
	}

	const details = {
		...base,
		issuerName: answer.issuerName ?? null,
		issuedAt: answer.issuedAt ?? null,
		expiresAt: answer.expiresAt ?? null,
	}
	const named = {
		title: typeof answer.title === 'string' && answer.title !== '' ? answer.title : null,
		holderName: typeof answer.holderName === 'string' && answer.holderName !== '' ? answer.holderName : null,
	}

	if (answer.valid === true) {
		return { ...details, ...named, state: 'valid', checks: checks(PASSED, PASSED, PASSED) }
	}
	if (isPast(answer.expiresAt, now)) {
		return { ...details, ...named, state: 'expired', checks: checks(PASSED, PASSED, FAILED) }
	}

	// Signed and not revoked, but not issued (a draft or a withdrawn record).
	return { ...details, state: 'notValid', checks: checks(PASSED, PASSED, NOT_RUN) }
}
