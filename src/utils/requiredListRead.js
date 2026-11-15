// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Read a list a screen cannot do without: a refusal throws with the given
 * message and the status; the rows are the answer's `results`, else its
 * `objects`, else the answer itself.
 *
 * The caller builds the URL (with `generateUrl`), so this module stays free
 * of the Nextcloud libraries and a Node test can import it.
 *
 * @param {string} url The list's URL.
 * @param {string} failure The message a refusal throws (followed by the status).
 * @return {Promise<Array<object>|object>} The rows.
 * @spec openspec/specs/pupil-dossier/spec.md#scenario-the-timeline-view-merges-notes-incidents-check-ins-and-the-care-chain
 */
export async function readRequiredList(url, failure) {
	const resp = await fetch(url, {
		headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
	})
	if (!resp.ok) throw new Error(`${failure}: ${resp.status}`)
	const json = await resp.json()
	return json.results ?? json.objects ?? json ?? []
}
