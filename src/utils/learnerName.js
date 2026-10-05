// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The `learnerName` list cell: a pupil as a teacher reads them, by the name
 * on their learner profile, never by Nextcloud user id
 * (lists-read-pupil-names).
 *
 * The cell resolves the row's `learnerRef` (the profile uuid) when the row
 * carries one, and otherwise looks the profile up by the row's user id
 * (`ncUserId` on the profile). The fallback covers the schemas that store
 * only a user id and the rows saved without a `learnerRef`. A pupil without a
 * profile keeps the user id, so the cell never goes blank.
 *
 * Pure: the fetch and the URL builder are passed in, so the module runs
 * under `node --test` with nothing mocked.
 *
 * @spec openspec/changes/lists-read-pupil-names/specs/school-structure/spec.md#requirement-every-staff-list-reads-a-pupil-by-name
 */

const BY_REF = '/apps/openregister/api/objects/learniq/learner-profile/{ref}'
const BY_USER =
	'/apps/openregister/api/objects/learniq/learner-profile?ncUserId={uid}&_limit=1'

/**
 * A value as a list of non-empty strings.
 *
 * @param {unknown} value A string, an array of strings, or nothing.
 * @return {string[]}
 */
function asList(value) {
	const list = Array.isArray(value) ? value : [value]
	return list.filter((v) => typeof v === 'string' && v !== '')
}

/**
 * The lookups one cell makes: per pupil, the profile uuid from the row's
 * reference field (empty when the row has none) and the user id.
 *
 * @param {unknown} value The cell value: a user id, or an array of them.
 * @param {object|null} row The row.
 * @param {string} refField The row field holding the profile uuid(s); '' when the schema has none.
 * @return {Array<{ref: string, userId: string}>}
 * @spec openspec/changes/lists-read-pupil-names/specs/school-structure/spec.md#requirement-every-staff-list-reads-a-pupil-by-name
 */
export function learnerLookups(value, row, refField) {
	const userIds = asList(value)
	const refs = refField && row ? asList(row[refField]) : []
	if (userIds.length === 0) {
		return refs.map((ref) => ({ ref, userId: '' }))
	}
	// Refs pair with user ids by position only when the row has one for each;
	// a partial list cannot say which pupil a ref belongs to.
	const paired = refs.length === userIds.length
	return userIds.map((userId, i) => ({ ref: paired ? refs[i] : '', userId }))
}

/**
 * A learner profile's name: given and family name, else OpenRegister's
 * computed name.
 *
 * @param {object|null} profile The profile object.
 * @return {string} The name, or '' when it has none.
 * @spec openspec/changes/lists-read-pupil-names/specs/school-structure/spec.md#requirement-every-staff-list-reads-a-pupil-by-name
 */
export function profileName(profile) {
	if (!profile || typeof profile !== 'object') {
		return ''
	}
	const full = [profile.givenName, profile.familyName]
		.filter((p) => typeof p === 'string' && p.trim() !== '')
		.join(' ')
		.trim()
	if (full !== '') {
		return full
	}
	const computed = profile['@self'] && profile['@self'].name
	return typeof computed === 'string' ? computed : ''
}

/**
 * A resolver that names pupils, fetching each profile at most once.
 *
 * @param {object} deps The dependencies.
 * @param {(url: string) => Promise<object>} deps.getJson Fetch a URL, resolve to the JSON body.
 * @param {(path: string, params: object) => string} deps.urlFor Build an app URL (Nextcloud's generateUrl).
 * @return {{nameOf: (lookup: {ref: string, userId: string}) => Promise<string>}}
 * @spec openspec/changes/lists-read-pupil-names/specs/school-structure/spec.md#requirement-every-staff-list-reads-a-pupil-by-name
 */
export function createLearnerNameResolver({ getJson, urlFor }) {
	const cache = new Map()

	const once = (key, load) => {
		if (!cache.has(key)) {
			cache.set(
				key,
				load().catch(() => ''),
			)
		}
		return cache.get(key)
	}

	const byRef = (ref) =>
		once(`ref:${ref}`, async () =>
			profileName(await getJson(urlFor(BY_REF, { ref }))),
		)

	const byUser = (uid) =>
		once(`uid:${uid}`, async () => {
			const body = await getJson(urlFor(BY_USER, { uid }))
			const hits = (body && (body.results || body.objects)) || []
			return profileName(hits[0])
		})

	return {
		async nameOf({ ref, userId }) {
			const viaRef = ref ? await byRef(ref) : ''
			if (viaRef !== '') {
				return viaRef
			}
			const viaUser = userId ? await byUser(userId) : ''
			return viaUser !== '' ? viaUser : userId || ref
		},
	}
}
