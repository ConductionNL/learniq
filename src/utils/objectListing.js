// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Reading Open Register object collections the way the list views do: an OCS
 * JSON GET, and the rows from whichever list shape the API answers with.
 */

/** The headers every OCS JSON read carries. */
const OCS_HEADERS = { 'OCS-APIREQUEST': 'true', Accept: 'application/json' }

/**
 * GET a URL as OCS JSON.
 *
 * @param {string} url The full URL, from generateUrl().
 * @return {Promise<Response>} The response, whatever its status.
 * @spec openspec/specs/enrolment/spec.md#scenario-a-coordinator-reviews-pending-applications-on-the-review-board
 */
export function readJson(url) {
	return fetch(url, { headers: { ...OCS_HEADERS } })
}

/**
 * GET several URLs at once, in order.
 *
 * @param {string[]} urls The full URLs, from generateUrl().
 * @return {Promise<Response[]>} The responses, in the order of the URLs.
 * @spec openspec/specs/enrolment/spec.md#scenario-a-coordinator-reviews-pending-applications-on-the-review-board
 */
export function readAll(urls) {
	return Promise.all(urls.map((url) => readJson(url)))
}

/**
 * The rows of a collection answer: `results`, else `objects`, else the answer.
 *
 * @param {object|Array} json The parsed answer.
 * @return {Array|object}
 * @spec openspec/specs/enrolment/spec.md#scenario-a-coordinator-reviews-pending-applications-on-the-review-board
 */
export function listOf(json) {
	return json.results ?? json.objects ?? json ?? []
}

/**
 * Reject unless a response is ok.
 *
 * @param {Response} resp The response.
 * @param {string} failure The error text before the status.
 * @return {void}
 * @spec openspec/specs/enrolment/spec.md#scenario-a-coordinator-reviews-pending-applications-on-the-review-board
 */
export function assertOk(resp, failure) {
	if (!resp.ok) {
		throw new Error(`${failure}: ${resp.status}`)
	}
}

/**
 * The rows of a response, read as an empty answer when it is not ok.
 *
 * @param {Response} resp The response.
 * @return {Promise<Array|object>}
 * @spec openspec/specs/course-evaluation/spec.md#scenario-a-coordinator-opens-the-course-quality-report-and-sees-the-score-trend
 */
export async function listOrEmpty(resp) {
	const json = resp.ok ? await resp.json() : {}
	return listOf(json)
}

/**
 * Run a view's load: raise its `loading` flag and clear its `error`, run the
 * read, hand a failure to the view, and lower the flag again whatever happened.
 *
 * @param {{loading: boolean, error: string|null}} vm The view.
 * @param {{read: function(): Promise<void>, fail: function(Error): void}} steps The read, and what to do with its error.
 * @return {Promise<void>}
 * @spec openspec/specs/enrolment/spec.md#scenario-a-coordinator-reviews-pending-applications-on-the-review-board
 */
export async function runLoad(vm, { read, fail }) {
	vm.loading = true
	vm.error = null

	try {
		await read()
	} catch (err) {
		fail(err)
	} finally {
		vm.loading = false
	}
}
