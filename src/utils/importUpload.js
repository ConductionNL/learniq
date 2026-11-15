/**
 * The file-import screens (course packages, learning record bundles): the
 * picked file, the upload and the report the server answers with.
 *
 * The caller builds the URL (with `generateUrl`), so this module stays free
 * of the Nextcloud libraries and a Node test can import it.
 *
 * @spec openspec/specs/course-management/spec.md
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/**
 * The screen state after a file input changed: the picked file, or none.
 *
 * @param {Event} event The input's change event.
 * @return {{selectedFile: (File|null), selectedFileName: string, uploadError: null}} The state.
 * @spec openspec/specs/course-management/spec.md
 */
export function pickedFileState(event) {
	const file = event.target.files?.[0] ?? null
	return {
		selectedFile: file,
		selectedFileName: file ? file.name : '',
		uploadError: null,
	}
}

/**
 * The screen state of a fresh start.
 *
 * @return {object} The state.
 * @spec openspec/specs/course-management/spec.md
 */
export function emptyImportState() {
	return {
		report: null,
		selectedFile: null,
		selectedFileName: '',
		outcomeFilter: 'all',
		uploadError: null,
	}
}

/**
 * The report's entries with one outcome, or all of them.
 *
 * @param {object|null} report The import report.
 * @param {string} outcomeFilter An outcome, or `all`.
 * @return {Array<object>} The entries.
 * @spec openspec/specs/course-management/spec.md
 */
export function entriesWithOutcome(report, outcomeFilter) {
	const entries = report?.entries ?? []
	if (outcomeFilter === 'all') return entries
	return entries.filter((entry) => entry.outcome === outcomeFilter)
}

/**
 * Post a file as form data with the request token; a refusal throws with
 * the server's reason.
 *
 * @param {string} url The import endpoint.
 * @param {FormData} formData The file and its fields.
 * @return {Promise<object>} The import report.
 * @spec openspec/specs/course-management/spec.md
 */
export async function postImportFile(url, formData) {
	const resp = await fetch(url, {
		method: 'POST',
		headers: {
			'OCS-APIREQUEST': 'true',
			requesttoken: window.OC?.requestToken ?? '',
		},
		body: formData,
	})

	const json = await resp.json()
	if (!resp.ok) {
		throw new Error(json.error ?? `Import failed: ${resp.status}`)
	}
	return json
}
