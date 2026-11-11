/**
 * Portfolio evidence: reading the portfolio's objects, the entry a learner
 * adds, the records the evidence picker offers, and the words both
 * portfolio screens use.
 *
 * The caller builds every URL (with `generateUrl`), so this module stays
 * free of the Nextcloud libraries and a Node test can import it.
 *
 * @spec openspec/specs/assignments/spec.md#scenario-a-reviewer-completes-an-assigned-peerreview
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/**
 * Read one object; a refusal throws with its status.
 *
 * `notFound` is set HERE and only here — on the single-object lookup — not
 * wherever a 404 happens to surface. A 404 from a LIST does not mean "this
 * record is absent": an empty result set says that, with HTTP 200. A 404
 * there means the schema did not resolve, which is a broken register and
 * must stay loud. Flagging the object lookup specifically keeps the quiet
 * branch to the one case that is genuinely expected input.
 *
 * @param {string} url The object's URL.
 * @param {string} schema The schema, for the message.
 * @return {Promise<object>} The object, `{}` when the answer is empty.
 * @spec openspec/specs/assignments/spec.md#scenario-a-reviewer-completes-an-assigned-peerreview
 */
export async function lookupObject(url, schema) {
	const resp = await fetch(url, {
		headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
	})
	if (!resp.ok) {
		const err = new Error(`${schema} fetch failed: ${resp.status}`)
		err.status = resp.status
		err.notFound = resp.status === 404
		throw err
	}
	const json = await resp.json()
	return json.object ?? json ?? {}
}

/**
 * Read a list of objects; a refused read is an empty list.
 *
 * @param {string} url The list's URL.
 * @return {Promise<Array<object>>} The objects.
 * @spec openspec/specs/assignments/spec.md#scenario-a-reviewer-completes-an-assigned-peerreview
 */
export async function lookupList(url) {
	const resp = await fetch(url, {
		headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
	})
	if (!resp.ok) {
		return []
	}
	const json = await resp.json()
	return json.results ?? json.objects ?? (Array.isArray(json) ? json : [])
}

/**
 * What a portfolio screen says when the portfolio did not load: a missing
 * portfolio is ordinary input (a stale bookmark), anything else a fault.
 *
 * @param {unknown} err The failure.
 * @param {(app: string, text: string) => string} t Translates.
 * @return {string} The message.
 * @spec openspec/specs/assignments/spec.md#scenario-a-reviewer-completes-an-assigned-peerreview
 */
export function portfolioLoadError(err, t) {
	if (err?.notFound === true) {
		return t('learniq', 'This portfolio no longer exists, or you do not have access to it.')
	}
	return t('learniq', 'Failed to load portfolio. Please try again.')
}

/**
 * The words for an evidence kind; an unknown kind shows as itself.
 *
 * @param {string} kind The evidence kind.
 * @param {(app: string, text: string) => string} t Translates.
 * @return {string} The label.
 * @spec openspec/specs/assignments/spec.md#scenario-a-reviewer-completes-an-assigned-peerreview
 */
export function evidenceKindLabel(kind, t) {
	const labels = {
		file: t('learniq', 'File'),
		submission: t('learniq', 'Submission'),
		'werkproces-assessment': t('learniq', 'Werkproces assessment'),
		'external-training-record': t('learniq', 'External training record'),
		credential: t('learniq', 'Credential'),
		reflection: t('learniq', 'Reflection'),
	}
	return labels[kind] ?? kind
}

/** The field of a PortfolioEntry that names the record it refers to, by kind. */
const REFERENCE_FIELDS = new Map([
	['submission', 'submissionId'],
	['werkproces-assessment', 'werkprocesAssessmentId'],
	['external-training-record', 'externalTrainingRecordId'],
	['credential', 'credentialId'],
])

/**
 * What an entry carries for its kind: a reflection its text, any other kind
 * with a record the record's id, anything else nothing.
 *
 * @param {{evidenceKind: string, referenceId: string, reflectionText: string}} newEntry The form.
 * @return {object} The fields.
 * @spec openspec/specs/assignments/spec.md#scenario-a-reviewer-completes-an-assigned-peerreview
 */
function evidenceFields(newEntry) {
	const kind = newEntry.evidenceKind
	if (kind === 'reflection') {
		return { reflectionText: newEntry.reflectionText }
	}
	if (!REFERENCE_FIELDS.has(kind)) {
		return {}
	}
	return { [REFERENCE_FIELDS.get(kind)]: newEntry.referenceId }
}

/**
 * The PortfolioEntry to create from the form: a reflection carries its text,
 * any other kind the record it refers to.
 *
 * @param {{evidenceKind: string, referenceId: string, title: string, sectionId: string, reflectionText: string}} newEntry The form.
 * @param {{portfolioId: string, learnerId: string, tenantId: string}} owner The portfolio, its learner and tenant.
 * @return {object} The entry.
 * @spec openspec/specs/assignments/spec.md#scenario-a-reviewer-completes-an-assigned-peerreview
 */
export function entryBody(newEntry, owner) {
	return {
		portfolioId: owner.portfolioId,
		learnerId: owner.learnerId,
		title: newEntry.title,
		evidenceKind: newEntry.evidenceKind,
		sectionId: newEntry.sectionId || null,
		tenant_id: owner.tenantId,
		...evidenceFields(newEntry),
	}
}

/**
 * An empty evidence form.
 *
 * @return {{evidenceKind: string, referenceId: string, title: string, sectionId: string, reflectionText: string}} The form.
 * @spec openspec/specs/assignments/spec.md#scenario-a-reviewer-completes-an-assigned-peerreview
 */
export function emptyEntry() {
	return {
		evidenceKind: 'submission',
		referenceId: '',
		title: '',
		sectionId: '',
		reflectionText: '',
	}
}

/**
 * The records the evidence picker offers, by kind: the schema, the field that
 * holds the learner's user id, and the label of a row.
 */
export const PICKER_SOURCES = new Map([
	['submission', {
		schema: 'Submission',
		learnerField: 'learnerIds',
		label: (r) => (r.feedbackText ? `${r.assignmentId} — ${r.feedbackText}` : (r.assignmentId ?? r.id)),
	}],
	['werkproces-assessment', {
		schema: 'werkproces-assessment',
		learnerField: 'learnerId',
		label: (r) => r.werkprocesLabel ?? r.werkprocesCode ?? r.id,
	}],
	// ExternalTrainingRecord.learnerId is the LearnerProfile uuid; the
	// learner's user id is on learnerUserId.
	['external-training-record', {
		schema: 'external-training-record',
		learnerField: 'learnerUserId',
		label: (r) => r.title ?? r.providerName ?? r.id,
	}],
	// Credential.learnerId is the LearnerProfile uuid; the learner's user id
	// is on learnerUserId.
	['credential', {
		schema: 'Credential',
		learnerField: 'learnerUserId',
		label: (r) => r.title ?? r.id,
	}],
])

/**
 * The label a reviewer sees for a referenced record: its title, name or
 * feedback, else its id.
 *
 * @param {object} referenced The record.
 * @param {string} refId Its id.
 * @return {string} The label.
 * @spec openspec/specs/assignments/spec.md#scenario-a-reviewer-completes-an-assigned-peerreview
 */
export function referencedTitle(referenced, refId) {
	return referenced.title ?? referenced.name ?? referenced.feedbackText ?? refId
}
