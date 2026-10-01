// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The conformance table on the accessibility page (governance-wcag-evidence-report).
// Pure helpers, no Vue and no Nextcloud imports, so node --test covers them.
// The publish guard checks the same fail-needs-a-limitation rule again
// (AccessibilityStatementPublishGuard); these only keep the dialog honest.

/**
 * The results a criterion can have, in the order the dialog offers them.
 *
 * @type {string[]}
 */
export const RESULTS = ['pass', 'fail', 'not-applicable', 'not-tested']

/**
 * The criterion number a free-text reference starts with ("2.1.1 Keyboard" gives "2.1.1").
 *
 * @param {*} reference The stored reference.
 * @return {string|null} The number, or null when there is none.
 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-per-criterion-conformance-record
 */
export function criterionNumber(reference) {
	if (typeof reference !== 'string') {
		return null
	}
	const match = reference.match(/^\s*(\d+\.\d+\.\d+)/)
	return match ? match[1] : null
}

/**
 * The limitations a failing criterion can link: the statement's own, for the
 * same criterion, not yet fixed.
 *
 * @param {string} criterion The criterion number.
 * @param {Array<object>} limitations The statement's limitations.
 * @return {Array<object>} The limitations to offer.
 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-per-criterion-conformance-record
 */
export function limitationsFor(criterion, limitations) {
	if (!Array.isArray(limitations)) {
		return []
	}
	return limitations.filter(
		(limitation) =>
			limitation
			&& criterionNumber(limitation.wcagCriterion) === criterion
			&& limitation.lifecycle !== 'fixed',
	)
}

/**
 * Whether the dialog must warn that this result blocks publishing.
 *
 * @param {string} result The chosen result.
 * @param {string|null|undefined} limitationId The linked limitation.
 * @return {boolean} True for a failure with no limitation.
 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#scenario-a-failure-needs-a-limitation
 */
export function needsLimitation(result, limitationId) {
	return result === 'fail' && !limitationId
}

/**
 * The stored record of a criterion: the latest by test date.
 *
 * @param {string} criterion The criterion number.
 * @param {Array<object>} records The statement's AccessibilityCriterionResult rows.
 * @return {object|null} The record.
 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-per-criterion-conformance-record
 */
export function recordFor(criterion, records) {
	if (!Array.isArray(records)) {
		return null
	}
	let latest = null
	for (const record of records) {
		if (!record || criterionNumber(record.wcagCriterion) !== criterion) {
			continue
		}
		if (latest === null || String(record.testedOn ?? '') >= String(latest.testedOn ?? '')) {
			latest = record
		}
	}
	return latest
}

/**
 * Counts per result over the table rows, and the total.
 *
 * @param {Array<object>} rows The table rows (each with a `result`).
 * @return {object} `{ pass, fail, 'not-applicable', 'not-tested', total }`.
 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#scenario-untested-criteria-are-visible
 */
export function summarise(rows) {
	const summary = { pass: 0, fail: 0, 'not-applicable': 0, 'not-tested': 0, total: 0 }
	for (const row of Array.isArray(rows) ? rows : []) {
		const result = RESULTS.includes(row?.result) ? row.result : 'not-tested'
		summary[result]++
		summary.total++
	}
	return summary
}

/**
 * The object the dialog saves: only the schema's own fields, empty ones left out.
 *
 * @param {object} form The dialog values: result, method, evidenceReference, testedOn, limitationId.
 * @param {object} context `{ statementId, tenantId, criterion, level }`.
 * @return {object} The AccessibilityCriterionResult payload.
 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#scenario-an-owner-records-results
 */
export function resultPayload(form, context) {
	const payload = {
		accessibilityStatementId: context.statementId,
		wcagCriterion: context.criterion,
		level: context.level,
		result: RESULTS.includes(form.result) ? form.result : 'not-tested',
		tenant_id: context.tenantId,
	}
	for (const field of ['method', 'evidenceReference', 'testedOn']) {
		const value = typeof form[field] === 'string' ? form[field].trim() : ''
		if (value !== '') {
			payload[field] = value
		}
	}
	if (payload.result === 'fail' && form.limitationId) {
		payload.limitationId = form.limitationId
	}
	return payload
}
