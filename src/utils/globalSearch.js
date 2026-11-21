/**
 * Pure query-builder + result-classifier for the People dashboard's global
 * fast-finder (global-search, finding G-new-1 — learniq scored 0/3 on
 * usability.md's "global search scope" criterion; gibbon's header Fast
 * Finder reaches a pupil profile in 2 clicks, journeys.md J4).
 *
 * learniq has no dedicated Staff schema (grepped `lib/Settings/
 * learniq_register.json`: only `LearnerProfile`, `Cohort`, and friends) — a
 * staff member IS a `LearnerProfile` whose `roles` array holds a non-learner
 * role (`instructor`, `hr`, `manager`, `compliance-officer`, `admin`,
 * `mentor`, `principal`, `inspector`; `parent` is also non-learner but is
 * excluded from "Staff" here — a guardian is not a staff member). So this
 * module issues exactly two OpenRegister queries (`learner-profile`,
 * `cohort`, both `x-openregister.searchable: true`) via the platform's
 * `_search` query param (`ObjectsController`), then classifies each
 * `learner-profile` hit as "Learners" or "Staff" client-side by its `roles`
 * array — avoiding any assumption about how OpenRegister's generic filter
 * syntax handles an array-contains-one-of-N-values query, which is not
 * documented and was not verified against a live instance in this change.
 *
 * Plain ES module (not a .vue SFC) so it is directly importable from a Node
 * test runner without an SFC compile step, same pattern as
 * `src/utils/courseOrder.js`.
 *
 * @spec openspec/specs/dashboard/spec.md#requirement-a-fast-finder-query-builder-splits-one-search-term-into-per-kind-openregister-requests
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/** Roles that make a `LearnerProfile` a "Staff" search result, not a "Learner" one. */
export const STAFF_ROLES = Object.freeze([
	'instructor',
	'hr',
	'manager',
	'compliance-officer',
	'admin',
	'mentor',
	'principal',
	'inspector',
])

/** The schemas the fast-finder searches, in result order. */
const SEARCH_TARGETS = Object.freeze([
	{ kind: 'people', schema: 'learner-profile' },
	{ kind: 'cohorts', schema: 'cohort' },
])

/**
 * Build the per-kind OpenRegister request descriptors for a raw search term.
 * Returns an empty array for a blank/whitespace-only term (never issues a
 * request for "" — an unfiltered `_search=` would return every row, not a
 * search result).
 *
 * @param {string} term Raw, un-trimmed user input.
 * @param {{limit?: number}} [options] `limit` per request (default 8).
 * @return {Array<{kind: 'people'|'cohorts', register: string, schema: string, params: object}>}
 * @spec openspec/specs/dashboard/spec.md#requirement-a-fast-finder-query-builder-splits-one-search-term-into-per-kind-openregister-requests
 */
export function buildGlobalSearchRequests(term, options = {}) {
	const trimmed = typeof term === 'string' ? term.trim() : ''
	if (!trimmed) {
		return []
	}
	const limit = searchLimit(options)
	return SEARCH_TARGETS.map(({ kind, schema }) => ({
		kind,
		register: 'learniq',
		schema,
		params: { _search: trimmed, _limit: limit },
	}))
}

/**
 * The per-request limit: the caller's when it is a positive number, else 8.
 *
 * @param {{limit?: number}} options The search options.
 * @return {number}
 */
function searchLimit(options) {
	return Number.isFinite(options.limit) && options.limit > 0 ? options.limit : 8
}

/**
 * Classify a `LearnerProfile`'s search-result kind from its `roles` array.
 * A profile with no `roles` at all — the common seed shape for a plain
 * learner — classifies as `'learner'`, not `'staff'`: the more common case
 * is the safer default when the field is simply unset.
 *
 * @param {string[]|null|undefined} roles The profile's `roles` array.
 * @return {'learner'|'staff'}
 * @spec openspec/specs/dashboard/spec.md#requirement-a-fast-finder-query-builder-splits-one-search-term-into-per-kind-openregister-requests
 */
export function classifyPersonKind(roles) {
	if (!Array.isArray(roles) || roles.length === 0) {
		return 'learner'
	}
	return roles.some((role) => STAFF_ROLES.includes(role)) ? 'staff' : 'learner'
}

/**
 * Display label for a `LearnerProfile` search result: full name when set,
 * otherwise the Nextcloud user id — never the raw object UUID. Same
 * precedence as `PeopleDashboard.vue`'s `learnerName()`.
 *
 * @param {object} item A learner-profile object.
 * @return {string}
 * @spec exclude Presentation-only helper composing a display label from object fields; no behavioural spec requirement.
 */
export function personResultLabel(item) {
	const full = [item?.givenName, item?.familyName].filter(Boolean).join(' ').trim()
	return full || item?.ncUserId || item?.['@self']?.name || item?.id || ''
}

/**
 * Group raw OpenRegister search responses into the three UI-facing result
 * groups (Learners / Staff / Cohorts), each row carrying a `label` and a
 * `route` the caller can navigate to directly.
 *
 * @param {object[]} learnerProfileResults Raw `learner-profile` search hits.
 * @param {object[]} cohortResults Raw `cohort` search hits.
 * @return {{learners: object[], staff: object[], cohorts: object[]}}
 * @spec openspec/specs/dashboard/spec.md#requirement-a-fast-finder-query-builder-splits-one-search-term-into-per-kind-openregister-requests
 */
export function groupGlobalSearchResults(
	learnerProfileResults = [],
	cohortResults = [],
) {
	const learners = []
	const staff = []
	for (const item of learnerProfileResults) {
		const group = classifyPersonKind(item.roles) === 'staff' ? staff : learners
		group.push(personRow(item))
	}
	const cohorts = (cohortResults || []).map(cohortRow)
	return { learners, staff, cohorts }
}

/**
 * A search hit's id, whichever key OpenRegister returned it under.
 *
 * @param {object} item A search hit.
 * @return {string|undefined}
 */
function resultId(item) {
	return item.id || item._id || item.uuid || item['@self']?.id
}

/**
 * The id a search hit's route names.
 *
 * @param {object} item A search hit.
 * @return {string|undefined}
 */
function routeId(item) {
	return item.id || item._id || item.uuid
}

/**
 * The result row of a learner-profile hit.
 *
 * @param {object} item A learner-profile hit.
 * @return {{id: string, label: string, route: {path: string}}}
 */
function personRow(item) {
	return {
		id: resultId(item),
		label: personResultLabel(item),
		route: { path: `/learner-profiles/${routeId(item)}` },
	}
}

/**
 * The result row of a cohort hit.
 *
 * @param {object} item A cohort hit.
 * @return {{id: string, label: string, route: {path: string}}}
 */
function cohortRow(item) {
	return {
		id: resultId(item),
		label: item.name || item['@self']?.name || item.id,
		route: { path: `/cohorts/${routeId(item)}` },
	}
}
