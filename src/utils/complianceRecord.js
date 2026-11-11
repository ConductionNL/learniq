// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The school's Compliance singleton as the admin settings page reads and
 * writes it: the privacyconvenant agreement, the processor agreement link and
 * the privacybijsluiter link and version. The page keeps the edit form; these
 * functions turn a stored object into that form and the form back into the
 * object OpenRegister's generic object endpoint saves.
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-the-school-records-its-privacyconvenant-agreement-and-privacybijsluiter
 */

// The slugs privacy-governance-surfaces declares in
// lib/Settings/learniq_register.json, verbatim (the resolver lowercases both
// sides, so casing is irrelevant, but a structural difference is not).
const REGISTER = 'learniq'
const COMPLIANCE_SCHEMA = 'compliance'
export const COMPLIANCE_TYPE = `${REGISTER}-${COMPLIANCE_SCHEMA}`

/**
 * Register the Compliance object type on the store, when the store can.
 *
 * @param {object} store The object store.
 * @return {void}
 */
function registerComplianceType(store) {
	if (typeof store.registerObjectType === 'function') {
		store.registerObjectType(
			COMPLIANCE_TYPE,
			COMPLIANCE_SCHEMA,
			REGISTER,
		)
	}
}

/**
 * The first Compliance objects the store holds; a failing read is none.
 *
 * @param {object} store The object store.
 * @return {Promise<Array<object>>|Array} The objects.
 */
function fetchCompliance(store) {
	return typeof store.fetchCollection === 'function'
		? store
			.fetchCollection(COMPLIANCE_TYPE, { _limit: 1 })
			.catch(() => [])
		: []
}

/**
 * The first entry of a result list, or null.
 *
 * @param {Array<object>|object|null} results What the store answered.
 * @return {object|null} The first object.
 */
function firstOf(results) {
	return Array.isArray(results) && results.length > 0 ? results[0] : null
}

/**
 * The stored Compliance singleton, or null when there is none yet.
 *
 * @param {object} store The object store.
 * @return {Promise<object|null>} The object.
 */
export async function readCompliance(store) {
	registerComplianceType(store)

	return firstOf(await fetchCompliance(store))
}

/**
 * The id of a stored Compliance object, from `id` or `@self.id`.
 *
 * @param {object} existing The stored object.
 * @return {string|null} The id.
 */
export function complianceIdOf(existing) {
	return existing.id
		?? (existing['@self'] && existing['@self'].id)
		?? null
}

/**
 * The edit form for a stored Compliance object.
 *
 * @param {object} existing The stored object.
 * @return {{privacyconvenantSigned: boolean, verwerkersovereenkomstUrl: string, privacybijsluiterUrl: string, privacybijsluiterVersion: string}} The form.
 */
export function complianceFormOf(existing) {
	return {
		privacyconvenantSigned: !!existing.privacyconvenantSigned,
		verwerkersovereenkomstUrl:
			existing.verwerkersovereenkomstUrl || '',

		privacybijsluiterUrl: existing.privacybijsluiterUrl || '',
		privacybijsluiterVersion:
			existing.privacybijsluiterVersion || '',
	}
}

/**
 * Whether the agreement is signed, and since when (now, when it is).
 *
 * @param {object} form The edit form.
 * @return {{privacyconvenantSigned: boolean, privacyconvenantSignedAt: string|null}} The fields.
 */
function signatureFields(form) {
	return {
		privacyconvenantSigned: !!form.privacyconvenantSigned,

		privacyconvenantSignedAt: form.privacyconvenantSigned
			? new Date().toISOString()
			: null,
	}
}

/**
 * The document links and version, null when left empty.
 *
 * @param {object} form The edit form.
 * @return {{verwerkersovereenkomstUrl: string|null, privacybijsluiterUrl: string|null, privacybijsluiterVersion: string|null}} The fields.
 */
function documentFields(form) {
	return {
		verwerkersovereenkomstUrl: form.verwerkersovereenkomstUrl || null,

		privacybijsluiterUrl: form.privacybijsluiterUrl || null,

		privacybijsluiterVersion: form.privacybijsluiterVersion || null,
	}
}

/**
 * Who reviewed the record, and when (now).
 *
 * @param {{uid: string}|null} currentUser The signed-in user.
 * @return {{lastReviewedAt: string, lastReviewedBy: string|null}} The fields.
 */
function reviewFields(currentUser) {
	return {
		lastReviewedAt: new Date().toISOString(),
		lastReviewedBy: currentUser ? currentUser.uid : null,
	}
}

/**
 * The Compliance object to save for an edit form, reviewed by a user now.
 *
 * @param {object} form The edit form.
 * @param {{uid: string}|null} currentUser The signed-in user.
 * @return {object} The object, without an id.
 */
export function compliancePayload(form, currentUser) {
	return {
		...signatureFields(form),
		...documentFields(form),
		...reviewFields(currentUser),
	}
}

/**
 * Save a Compliance object through the store, when the store can.
 *
 * @param {object} store The object store.
 * @param {object} payload The object to save.
 * @return {Promise<object|null>} The saved object.
 */
export async function storeCompliance(store, payload) {
	return typeof store.saveObject === 'function'
		? await store.saveObject(COMPLIANCE_TYPE, payload)
		: null
}
