// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The school's own part of the AI processing disclosure
 * (ai-disclosure-for-every-user): an introduction, a contact for questions
 * and an explanation per AI feature slug, kept as one AiDisclosureText
 * object. Every signed-in user reads it; only an admin or compliance officer
 * edits it, and the server refuses anyone else's write.
 *
 * @spec openspec/changes/ai-disclosure-for-every-user/specs/ai-locality-guarantee/spec.md
 */

import { recordId } from './sovereigntyPolicy.js'

/** The roles that get the edit mode, as DashboardRoleService resolves them. */
export const DISCLOSURE_EDITOR_ROLES = ['admin', 'compliance-officer']

/**
 * Whether a user with this primary role may edit the disclosure.
 *
 * @param {string} primaryRole The resolved role (initial state `primaryRole`).
 * @return {boolean} True for an admin or compliance officer only.
 * @spec openspec/changes/ai-disclosure-for-every-user/specs/ai-locality-guarantee/spec.md
 */
export function canEditDisclosure(primaryRole) {
	return DISCLOSURE_EDITOR_ROLES.includes(primaryRole)
}

/**
 * A text field of a record, or null when empty or not a string.
 *
 * @param {unknown} value The stored value.
 * @return {string|null} The text.
 */
function textOrNull(value) {
	return typeof value === 'string' && value.trim() !== '' ? value : null
}

/**
 * The per-feature notes, keeping only non-empty strings.
 *
 * @param {unknown} notes The stored map of feature slug to explanation.
 * @return {Object<string, string>} The notes.
 */
function cleanNotes(notes) {
	const clean = {}
	if (notes === null || typeof notes !== 'object' || Array.isArray(notes)) {
		return clean
	}
	for (const [slug, note] of Object.entries(notes)) {
		const text = textOrNull(note)
		if (text !== null) {
			clean[slug] = text.trim()
		}
	}
	return clean
}

/**
 * The page state for the school's text, read from a stored record, or the
 * empty state when there is none yet.
 *
 * @param {object|null} record A stored AiDisclosureText.
 * @return {{id: string|null, introduction: string|null, contact: string|null, featureNotes: Object<string, string>}} The state.
 * @spec openspec/changes/ai-disclosure-for-every-user/specs/ai-locality-guarantee/spec.md
 */
export function disclosureTextFromRecord(record) {
	if (!record || typeof record !== 'object') {
		return { id: null, introduction: null, contact: null, featureNotes: {} }
	}
	return {
		id: recordId(record),
		introduction: textOrNull(record.introduction),
		contact: textOrNull(record.contact),
		featureNotes: cleanNotes(record.featureNotes),
	}
}

/**
 * The edit form, seeded from the page state, with a field for every listed
 * feature so an admin can explain a feature that has no note yet.
 *
 * @param {object} text The page state.
 * @param {Array<{slug: string}>} features The composed features.
 * @return {{introduction: string, contact: string, featureNotes: Object<string, string>}} The form.
 */
export function disclosureTextForm(text, features) {
	const featureNotes = {}
	for (const feature of Array.isArray(features) ? features : []) {
		if (feature && typeof feature.slug === 'string' && feature.slug !== '') {
			featureNotes[feature.slug] = text.featureNotes[feature.slug] || ''
		}
	}
	for (const [slug, note] of Object.entries(text.featureNotes)) {
		if (!(slug in featureNotes)) {
			featureNotes[slug] = note
		}
	}
	return {
		introduction: text.introduction || '',
		contact: text.contact || '',
		featureNotes,
	}
}

/**
 * The object to save from the form: empty fields become null, empty notes
 * are left out, and the id is added only when the record exists.
 *
 * @param {object} form The edit form.
 * @param {string|null} id The stored record's id.
 * @return {object} The payload.
 * @spec openspec/changes/ai-disclosure-for-every-user/specs/ai-locality-guarantee/spec.md
 */
export function disclosureTextPayload(form, id) {
	const payload = {
		introduction: textOrNull(form.introduction)?.trim() ?? null,
		contact: textOrNull(form.contact)?.trim() ?? null,
		featureNotes: cleanNotes(form.featureNotes),
	}
	if (id) {
		payload.id = id
	}
	return payload
}

/**
 * The school's explanation of one feature, or null.
 *
 * @param {object} text The page state.
 * @param {string} slug The feature slug.
 * @return {string|null} The explanation.
 */
export function featureNote(text, slug) {
	return (text && text.featureNotes && text.featureNotes[slug]) || null
}
