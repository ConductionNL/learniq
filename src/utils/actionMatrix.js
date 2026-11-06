// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Reading and saving the ADR-023 action-authorization matrix.
 *
 * @spec openspec/architecture/adr-023-action-authorization.md
 */

/**
 * Copy a stored matrix into a plain editable map with one list per action.
 *
 * @param {string[]} actions The declared actions.
 * @param {object} source The matrix as the server sent it.
 * @return {object} `{ '<action>': ['group', ...] }`.
 * @spec openspec/architecture/adr-023-action-authorization.md
 */
export function readMatrix(actions, source) {
	const stored = source && typeof source === 'object' ? source : {}
	const next = {}
	for (const action of actions) {
		next[action] = Array.isArray(stored[action]) ? [...stored[action]] : []
	}
	return next
}

/**
 * The body a save sends: `admin` first, then every ticked group.
 *
 * @param {string[]} actions The declared actions.
 * @param {object} matrix The editable matrix.
 * @return {object} `{ '<action>': ['admin', ...] }`.
 * @spec openspec/architecture/adr-023-action-authorization.md
 */
export function matrixPayload(actions, matrix) {
	const payload = {}
	for (const action of actions) {
		const extra = (matrix[action] || []).filter((group) => group !== 'admin')
		payload[action] = ['admin', ...extra]
	}
	return payload
}

/**
 * Save the matrix. A failed save leaves the caller's matrix untouched and
 * returns the server's reason, or null when it gave none.
 *
 * @param {{ put: (url: string, body: object) => Promise<object> }} client An axios-like client that rejects on a non-2xx status.
 * @param {string} url The action-matrix endpoint.
 * @param {string[]} actions The declared actions.
 * @param {object} matrix The editable matrix.
 * @return {Promise<{ok: true, matrix: object}|{ok: false, error: string|null}>} The outcome.
 * @spec openspec/architecture/adr-023-action-authorization.md
 */
export async function saveActionMatrix(client, url, actions, matrix) {
	try {
		const response = await client.put(url, {
			matrix: matrixPayload(actions, matrix),
		})
		return { ok: true, matrix: readMatrix(actions, response?.data?.matrix) }
	} catch (e) {
		const error = e?.response?.data?.error
		return { ok: false, error: typeof error === 'string' && error !== '' ? error : null }
	}
}
