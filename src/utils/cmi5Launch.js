/**
 * cmi5 AU (Assignable Unit) launch-URL builder — the five launch query
 * parameters the cmi5 spec requires an LMS to append when opening an AU:
 * `endpoint`, `fetch`, `actor`, `activityId`, `registration`.
 *
 * Closes the cmi5 half of finding 5.6 alongside `scorm12Runtime.js`'s SCORM
 * 1.2 half. Unlike SCORM, a cmi5 AU talks directly to the LRS over xAPI once
 * launched (using the `fetch` URL to redeem a short-lived auth token) — the
 * player's only job is constructing this URL correctly and opening it; no
 * client-side statement-proxying shim is needed for cmi5 the way SCORM 1.2's
 * `window.API` object is.
 *
 * This module builds the URL from a launch-token response shape; it does not
 * itself call the launch endpoint (`POST /api/lessons/{id}/cmi5-launch`,
 * cmi5-xapi-lrs-ingest); that HTTP call lives in `LessonPlayer.vue`, which
 * degrades gracefully when the endpoint 404s/503s (see that file and the
 * spec's "cmi5 lesson gracefully degrades" scenario).
 *
 * Plain ES module (not a .vue SFC) so it is directly importable from a Node
 * test runner without an SFC compile step, same pattern as
 * `src/utils/courseOrder.js`.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-run-cmi5--xapi-natively-with-scorm-shim
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/** The launch-token fields a cmi5 launch needs, in the order they are checked. */
const CMI5_LAUNCH_FIELDS = [
	'endpoint',
	'fetchUrl',
	'actor',
	'activityId',
	'registration',
]

/** The origin a relative AU launch URL is resolved against, and stripped again. */
const PLACEHOLDER_ORIGIN = 'https://placeholder.invalid'

/**
 * Build a cmi5 AU launch URL from a launch-token response.
 *
 * @param {string} auLaunchUrl The AU's own launch URL (the base to append cmi5 query params to).
 * @param {{endpoint: string, fetchUrl: string, actor: object, activityId: string, registration: string}} launch
 *  `endpoint` — the LRS xAPI endpoint the AU MUST use. `fetchUrl` — a
 *  short-lived URL the AU fetches to redeem its actual auth token (cmi5's
 *  indirect-token-passing requirement — a bearer token is never placed
 *  directly in the launch URL, which browser history/referrers can leak).
 *  `actor` — an xAPI Agent object (JSON, stringified into the URL).
 *  `activityId` — the cmi5 AU's activity IRI. `registration` — a UUID
 *  identifying this specific launch attempt.
 * @return {string} The AU launch URL with all five cmi5 query parameters appended.
 * @spec openspec/specs/course-management/spec.md#requirement-run-cmi5--xapi-natively-with-scorm-shim
 */
export function buildCmi5LaunchUrl(auLaunchUrl, launch) {
	if (!auLaunchUrl) {
		throw new Error('buildCmi5LaunchUrl: auLaunchUrl is required')
	}
	assertLaunchComplete(launch)

	const url = new URL(auLaunchUrl, PLACEHOLDER_ORIGIN + '/')
	appendCmi5Params(url, launch)
	return launchUrlText(url, auLaunchUrl)
}

/**
 * Throw for the first launch field that is missing, null or blank.
 *
 * @param {object|null} launch The launch-token response.
 * @throws {Error} When a field is missing.
 */
function assertLaunchComplete(launch) {
	for (const field of CMI5_LAUNCH_FIELDS) {
		if (isLaunchFieldMissing(launch, field)) {
			throw new Error(`buildCmi5LaunchUrl: launch.${field} is required`)
		}
	}
}

/**
 * Whether a launch field is absent, null or blank (or there is no launch).
 *
 * @param {object|null} launch The launch-token response.
 * @param {string} field The field name.
 * @return {boolean}
 */
function isLaunchFieldMissing(launch, field) {
	return (
		!launch
		|| launch[field] === undefined
		|| launch[field] === null
		|| launch[field] === ''
	)
}

/**
 * Append the five cmi5 launch parameters to the URL.
 *
 * @param {URL} url The AU launch URL.
 * @param {object} launch The launch-token response.
 */
function appendCmi5Params(url, launch) {
	url.searchParams.set('endpoint', launch.endpoint)
	url.searchParams.set('fetch', launch.fetchUrl)
	url.searchParams.set('actor', JSON.stringify(launch.actor))
	url.searchParams.set('activityId', launch.activityId)
	url.searchParams.set('registration', launch.registration)
}

/**
 * The URL as text. auLaunchUrl may be relative (a path on this app) or
 * absolute (an externally-hosted AU); only fall back to the
 * placeholder-stripped relative form when the input itself was relative.
 *
 * @param {URL} url The AU launch URL with its parameters.
 * @param {string} auLaunchUrl The URL as given.
 * @return {string}
 */
function launchUrlText(url, auLaunchUrl) {
	const isAbsolute = /^[a-z][a-z0-9+.-]*:\/\//i.test(auLaunchUrl)
	if (isAbsolute) {
		return url.toString()
	}
	return url.toString().replace(PLACEHOLDER_ORIGIN, '')
}
