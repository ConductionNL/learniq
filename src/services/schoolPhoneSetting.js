// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The school's phone number the public certificate page shows
// (certificate-verification-and-key-rotation). Mirrors
// lib/Service/Settings/SchoolPhone.php: what the server reads back as no
// number, the field refuses before saving.
//
// @spec openspec/changes/certificate-verification-and-key-rotation/specs/certification/spec.md#requirement-the-verification-link-opens-a-page-that-says-in-words-what-was-checked

export const SCHOOL_PHONE_SETTING = 'schoolPhone'

export const MAX_PHONE_LENGTH = 32

/**
 * A typed value as a phone number, '' for an empty field, or null when it is no phone number.
 *
 * @param {string} raw The field's value.
 * @return {string|null}
 */
export function parseSchoolPhone(raw) {
	const text = String(raw ?? '').trim()
	if (text === '') {
		return ''
	}
	if (text.length > MAX_PHONE_LENGTH || !/^\+?[0-9 ().-]+$/.test(text) || !/[0-9]/.test(text)) {
		return null
	}
	return text
}

/**
 * Read the stored number.
 *
 * @param {{http: object, url: string}} endpoint The settings endpoint.
 * @return {Promise<string>}
 */
export async function loadSchoolPhone(endpoint) {
	const { data } = await endpoint.http.get(endpoint.url)
	return parseSchoolPhone(data?.[SCHOOL_PHONE_SETTING]) ?? ''
}

/**
 * Store the number ('' clears it); throws when the server did not store it.
 *
 * @param {{http: object, url: string}} endpoint The settings endpoint.
 * @param {string} phone A value parseSchoolPhone() accepted.
 * @return {Promise<string>}
 */
export async function saveSchoolPhone(endpoint, phone) {
	const { data } = await endpoint.http.put(endpoint.url, { [SCHOOL_PHONE_SETTING]: phone })
	const stored = String(data?.config?.[SCHOOL_PHONE_SETTING] ?? '')
	if (stored !== phone) {
		throw new Error('settings write did not store the school phone number')
	}
	return stored
}
