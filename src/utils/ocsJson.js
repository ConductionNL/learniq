// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * JSON requests to the Nextcloud APIs the views write to: the OCS request
 * header, a JSON body, and an error that names what failed and its status.
 */

/** The headers every OCS JSON request carries. */
const OCS_HEADERS = { 'OCS-APIREQUEST': 'true', Accept: 'application/json' }

/**
 * Send a JSON body.
 *
 * @param {string} url The full URL, from generateUrl().
 * @param {{method: string, body: object}} request The HTTP method and the body to serialise.
 * @return {Promise<Response>} The response, whatever its status.
 * @spec openspec/specs/assessment/spec.md
 */
export function sendJson(url, { method, body }) {
	return fetch(url, {
		method,
		headers: { ...OCS_HEADERS, 'Content-Type': 'application/json' },
		body: JSON.stringify(body),
	})
}

/**
 * Send a JSON body and reject unless the response is ok.
 *
 * @param {string} url The full URL, from generateUrl().
 * @param {{method: string, body: object, failure: string}} request The method, the body and the error text before the status.
 * @return {Promise<Response>} The ok response.
 * @spec openspec/specs/assessment/spec.md
 */
export async function sendJsonOrFail(url, { method, body, failure }) {
	const resp = await sendJson(url, { method, body })
	if (!resp.ok) {
		throw new Error(`${failure}: ${resp.status}`)
	}
	return resp
}
