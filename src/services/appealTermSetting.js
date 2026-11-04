// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The exam board's appeal term: how many days a learner has to appeal a
 * decision of the exam board. An app setting, 42 days unless the school set
 * another term.
 *
 * @spec openspec/specs/exam-board/spec.md#requirement-a-decided-fraudcase-stamps-the-appeal-deadline-from-the-schools-appeal-term
 */

/** The app setting, as /api/settings names it. */
export const APPEAL_TERM_SETTING = 'examBoardAppealDays'

/** The term when the school set none. */
export const DEFAULT_APPEAL_DAYS = 42

/** The longest term the setting accepts. */
export const MAX_APPEAL_DAYS = 365

const DAY_MS = 24 * 60 * 60 * 1000

/**
 * The term a stored or typed value stands for: a whole number from 1 to 365,
 * else null.
 *
 * @param {unknown} raw The value.
 * @return {number|null} The days, or null when the value is no term.
 * @spec openspec/specs/exam-board/spec.md#scenario-the-school-sets-its-own-appeal-term
 */
export function parseAppealDays(raw) {
	const text = String(raw ?? '').trim()
	if (!/^\d{1,3}$/.test(text)) {
		return null
	}
	const days = Number(text)
	return days >= 1 && days <= MAX_APPEAL_DAYS ? days : null
}

/**
 * The last day a decision taken on `decided` can be appealed.
 *
 * @param {Date} decided The day of the decision.
 * @param {number} days The appeal term.
 * @return {Date} The last day.
 * @spec openspec/specs/exam-board/spec.md#scenario-the-school-sets-its-own-appeal-term
 */
export function appealUntil(decided, days) {
	return new Date(decided.getTime() + days * DAY_MS)
}

/**
 * Read the stored term.
 *
 * @param {{http: object, url: string}} endpoint The client and the settings address.
 * @return {Promise<number>} The days, the default when none is stored.
 * @spec openspec/specs/exam-board/spec.md#scenario-the-school-sets-its-own-appeal-term
 */
export async function loadAppealDays(endpoint) {
	const { data } = await endpoint.http.get(endpoint.url)
	return parseAppealDays(data?.[APPEAL_TERM_SETTING]) ?? DEFAULT_APPEAL_DAYS
}

/**
 * Store a term and check the server kept it.
 *
 * @param {{http: object, url: string}} endpoint The client and the settings address.
 * @param {number} days The term.
 * @return {Promise<number>} The stored days.
 * @spec openspec/specs/exam-board/spec.md#scenario-the-school-sets-its-own-appeal-term
 */
export async function saveAppealDays(endpoint, days) {
	const { data } = await endpoint.http.put(endpoint.url, {
		[APPEAL_TERM_SETTING]: String(days),
	})
	const stored = parseAppealDays(data?.config?.[APPEAL_TERM_SETTING])
	if (stored !== days) {
		throw new Error('settings write did not store the appeal term')
	}
	return stored
}
