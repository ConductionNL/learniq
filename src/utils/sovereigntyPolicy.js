/**
 * The SovereigntyPolicy singleton as the AI-processing disclosure page reads
 * and writes it: the record shape the page keeps, the edit form it seeds and
 * the payload it saves.
 *
 * Plain ES module (not a .vue SFC) so it is directly importable from a Node
 * test runner without a build step.
 *
 * @spec openspec/specs/ai-locality-guarantee/spec.md#requirement-the-system-shall-let-a-school-declare-an-ai-processing-locality-policy
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/** The tier a school has when it never set one (the schema default). */
export const DEFAULT_POLICY_TIER = 'eu-hosted-allowed'

/**
 * The first record of a collection read, or null when there is none.
 *
 * @param {Array|null|undefined} results What the collection fetch resolved to.
 * @return {object|null} The first record.
 */
export function firstRecord(results) {
	return Array.isArray(results) && results.length > 0 ? results[0] : null
}

/**
 * The id of a stored record: its own id, else the one under `@self`.
 *
 * @param {object} record A stored record.
 * @return {string|null} The id.
 */
export function recordId(record) {
	return record.id ?? (record['@self'] && record['@self'].id) ?? null
}

/**
 * The policy state the page keeps, read from a stored record.
 *
 * @param {object} record A stored SovereigntyPolicy.
 * @return {{id: string|null, policy: string, rationale: string|null, setBy: string|null, setAt: string|null}} The state.
 */
export function policyFromRecord(record) {
	return {
		id: recordId(record),
		policy: record.policy || DEFAULT_POLICY_TIER,
		rationale: record.rationale || null,
		setBy: record.setBy || null,
		setAt: record.setAt || null,
	}
}

/**
 * The edit form seeded from the policy state.
 *
 * @param {{policy: string, rationale: string|null}} policy The policy state.
 * @return {{policy: string, rationale: string}} The form.
 */
export function policyFormFrom(policy) {
	return {
		policy: policy.policy,
		rationale: policy.rationale || '',
	}
}

/**
 * The payload that saves the edit form, carrying the id of the record it updates.
 *
 * @param {{policy: string, rationale: string}} form The edit form.
 * @param {string|null} id The id of the stored policy, if any.
 * @return {{policy: string, rationale: string|null, id?: string}} The payload.
 */
export function policyPayload(form, id) {
	const payload = {
		policy: form.policy,
		rationale: form.rationale || null,
	}
	if (id) {
		payload.id = id
	}
	return payload
}

/**
 * Who set the policy and when, as a saved record reports it.
 *
 * @param {object} saved The saved record.
 * @return {{setBy: string|null, setAt: string|null}} The stamp.
 */
function setStamp(saved) {
	return {
		setBy: saved.setBy ?? null,
		setAt: saved.setAt ?? null,
	}
}

/**
 * The policy state after a save: the saved record, falling back to what was sent.
 *
 * @param {object} saved The saved record.
 * @param {{id: string|null, policy: string, rationale: string|null}} sent The previous id and the sent tier and rationale.
 * @return {{id: string|null, policy: string, rationale: string|null, setBy: string|null, setAt: string|null}} The state.
 */
export function policyFromSaved(saved, sent) {
	const stamp = setStamp(saved)
	return {
		id: saved.id ?? sent.id,
		policy: saved.policy || sent.policy,
		rationale: saved.rationale ?? sent.rationale,
		setBy: stamp.setBy,
		setAt: stamp.setAt,
	}
}
