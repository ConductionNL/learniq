// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Pure helpers for the bulk external-training screen (learniq#952).
 *
 * ExternalTrainingBulkRecordView records one external training for many
 * learners through ExternalTrainingController. The URL builders and the
 * payload builder live here so they run under `node --test` without an SFC
 * compile step; the view passes the URLs through `generateUrl`.
 *
 * @spec openspec/specs/external-training-recording/spec.md
 */

/** The bulk route, ExternalTrainingController::bulkRecord. */
export const BULK_URL = '/apps/learniq/api/external-training/bulk'

/** The shared training fields the controller refuses to do without. */
const REQUIRED_TRAINING_FIELDS = ['title', 'provider', 'completedAt']

/** Optional shared fields, sent only when filled in. */
const OPTIONAL_TRAINING_FIELDS = ['regulationSlug', 'evidenceNote']

/**
 * The credential route for one verified record, ExternalTrainingController::issueCredential.
 *
 * @param {string} recordId UUID of the external-training record.
 * @return {string} The app-relative URL.
 * @spec openspec/specs/external-training-recording/spec.md
 */
export function credentialUrl(recordId) {
	return `/apps/learniq/api/external-training/${encodeURIComponent(recordId)}/credential`
}

/**
 * The coverage route for one learner and regulation, ExternalTrainingController::learnerCoverage.
 *
 * @param {string} learnerId LearnerProfile UUID.
 * @param {string} regulationSlug Regulation slug.
 * @return {string} The app-relative URL.
 * @spec openspec/specs/external-training-recording/spec.md
 */
export function coverageUrl(learnerId, regulationSlug) {
	return (
		'/apps/learniq/api/external-training/coverage'
		+ `?learnerId=${encodeURIComponent(learnerId)}`
		+ `&regulationSlug=${encodeURIComponent(regulationSlug)}`
	)
}

/**
 * Read a learner's object id, whichever key OpenRegister returned it under.
 *
 * @param {object} learner A LearnerProfile object.
 * @return {string} The UUID, or ''.
 * @spec openspec/specs/external-training-recording/spec.md
 */
export function learnerId(learner) {
	return String(learner?.id ?? learner?.uuid ?? '')
}

/**
 * Turn a date input value (YYYY-MM-DD) into the schema's date-time format.
 *
 * @param {string} value The date input value.
 * @return {string} An ISO date-time, or '' when empty or unparseable.
 * @spec openspec/specs/external-training-recording/spec.md
 */
function toDateTime(value) {
	const trimmed = String(value ?? '').trim()
	if (trimmed === '') {
		return ''
	}
	const parsed = new Date(trimmed)
	return Number.isNaN(parsed.getTime()) ? '' : parsed.toISOString()
}

/**
 * Name what is still missing before the batch can be posted.
 *
 * @param {{learners: object[], training: object}} input The picked learners and the form.
 * @return {string[]} The missing field names, 'learners' first; empty when complete.
 * @spec openspec/specs/external-training-recording/spec.md
 */
export function bulkMissingFields({ learners, training }) {
	const missing = []
	if (!Array.isArray(learners) || learners.length === 0) {
		missing.push('learners')
	}
	for (const field of REQUIRED_TRAINING_FIELDS) {
		if (String(training?.[field] ?? '').trim() === '') {
			missing.push(field)
		}
	}
	return missing
}

/**
 * Build the body for POST /api/external-training/bulk.
 *
 * Every learner is sent once. The tenant comes from the picked learners, the
 * submitter is set by the server from the session and is never sent.
 *
 * @param {{learners: object[], training: object}} input The picked learners and the form.
 * @return {{learnerIds: string[], training: object}} The request body.
 * @spec openspec/specs/external-training-recording/spec.md
 */
export function buildBulkPayload({ learners, training }) {
	const learnerIds = [
		...new Set((learners || []).map(learnerId).filter((id) => id !== '')),
	]
	const shared = {
		title: String(training.title ?? '').trim(),
		provider: String(training.provider ?? '').trim(),
		kind: training.kind || 'classroom',
		completedAt: toDateTime(training.completedAt),
	}
	const validUntil = toDateTime(training.validUntil)
	if (validUntil !== '') {
		shared.validUntil = validUntil
	}
	for (const field of OPTIONAL_TRAINING_FIELDS) {
		const value = String(training[field] ?? '').trim()
		if (value !== '') {
			shared[field] = value
		}
	}
	const tenant = (learners || []).find((l) => l?.tenant_id)?.tenant_id
	if (tenant) {
		shared.tenant_id = tenant
	}
	return { learnerIds, training: shared }
}
