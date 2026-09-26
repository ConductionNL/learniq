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
 * @spec openspec/changes/global-search/specs/dashboard/spec.md#requirement-a-fast-finder-query-builder-splits-one-search-term-into-per-kind-openregister-requests
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

/**
 * Build the per-kind OpenRegister request descriptors for a raw search term.
 * Returns an empty array for a blank/whitespace-only term (never issues a
 * request for "" — an unfiltered `_search=` would return every row, not a
 * search result).
 *
 * @param {string} term Raw, un-trimmed user input.
 * @param {{limit?: number}} [options] `limit` per request (default 8).
 * @return {Array<{kind: 'people'|'cohorts', register: string, schema: string, params: object}>}
 * @spec openspec/changes/global-search/specs/dashboard/spec.md#requirement-a-fast-finder-query-builder-splits-one-search-term-into-per-kind-openregister-requests
 */
export function buildGlobalSearchRequests(term, options = {}) {
	const trimmed = typeof term === 'string' ? term.trim() : ''
	if (!trimmed) {
		return []
	}
	const limit = Number.isFinite(options.limit) && options.limit > 0 ? options.limit : 8
	return [
		{
			kind: 'people',
			register: 'learniq',
			schema: 'learner-profile',
			params: { _search: trimmed, _limit: limit },
		},
		{
			kind: 'cohorts',
			register: 'learniq',
			schema: 'cohort',
			params: { _search: trimmed, _limit: limit },
		},
	]
}

/**
 * Classify a `LearnerProfile`'s search-result kind from its `roles` array.
 * A profile with no `roles` at all — the common seed shape for a plain
 * learner — classifies as `'learner'`, not `'staff'`: the more common case
 * is the safer default when the field is simply unset.
 *
 * @param {string[]|null|undefined} roles The profile's `roles` array.
 * @return {'learner'|'staff'}
 * @spec openspec/changes/global-search/specs/dashboard/spec.md#requirement-search-results-are-grouped-by-kind
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
 * @spec openspec/changes/global-search/specs/dashboard/spec.md#requirement-search-results-are-grouped-by-kind
 */
export function groupGlobalSearchResults(learnerProfileResults = [], cohortResults = []) {
	const learners = []
	const staff = []
	for (const item of learnerProfileResults) {
		const row = {
			id: item.id || item._id || item.uuid || item['@self']?.id,
			label: personResultLabel(item),
			route: { path: `/learner-profiles/${item.id || item._id || item.uuid}` },
		}
		if (classifyPersonKind(item.roles) === 'staff') {
			staff.push(row)
		} else {
			learners.push(row)
		}
	}
	const cohorts = (cohortResults || []).map((item) => ({
		id: item.id || item._id || item.uuid || item['@self']?.id,
		label: item.name || item['@self']?.name || item.id,
		route: { path: `/cohorts/${item.id || item._id || item.uuid}` },
	}))
	return { learners, staff, cohorts }
}
