// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The learners a guardian may act for: the caller's linked children (by
 * `parentIds`) plus the caller's own profile (an 18+ learner acting for
 * themselves), merged by Nextcloud user id.
 *
 * Pure: the transport and the URL builder are passed in, so the module runs
 * under `node --test` with nothing mocked.
 *
 * @spec openspec/specs/parent-conferences/spec.md#scenario-a-linked-guardian-can-submit-a-signup-for-their-own-child
 * @spec openspec/specs/school-structure/spec.md#requirement-frontend-is-declarative-with-one-named-subject-choice-picker-exception
 */

const CHILDREN =
	'/apps/openregister/api/objects/learniq/learner-profile?parentIds={uid}&_limit=50'
const SELF =
	'/apps/openregister/api/objects/learniq/learner-profile?ncUserId={uid}&_limit=1'

/**
 * The rows of an OpenRegister list answer, or none when it holds no list.
 *
 * @param {{data: object}} response The axios-shaped answer.
 * @return {object[]}
 */
function rowsOf(response) {
	return (
		(response.data && (response.data.results || response.data.objects)) || []
	)
}

/**
 * Read the caller's linked children and own profile, one per user id.
 *
 * @param {{get: function(string): Promise<object>, urlFor: function(string, object): string}} http The transport and the URL builder.
 * @param {string} uid The caller's user id.
 * @return {Promise<object[]>} The learner profiles.
 */
export async function fetchLinkedLearners({ get, urlFor }, uid) {
	const [childrenResp, selfResp] = await Promise.all([
		get(urlFor(CHILDREN, { uid })),
		get(urlFor(SELF, { uid })),
	])
	const byId = new Map()
	;[...rowsOf(childrenResp), ...rowsOf(selfResp)].forEach((l) =>
		byId.set(l.ncUserId, l),
	)
	return Array.from(byId.values())
}
