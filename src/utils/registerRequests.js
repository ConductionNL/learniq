/**
 * Reading and writing OpenRegister objects from a page with `fetch`, and the
 * rubric selections the marking screens send.
 *
 * The caller builds the URL (with `generateUrl`), so this module stays free
 * of the Nextcloud libraries and a Node test can import it.
 *
 * @spec openspec/specs/assignments/spec.md#scenario-a-reviewer-completes-an-assigned-peerreview
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/**
 * The headers of a JSON read.
 *
 * @return {object} The headers.
 * @spec openspec/specs/assignments/spec.md#scenario-a-reviewer-completes-an-assigned-peerreview
 */
function readHeaders() {
	return { 'OCS-APIREQUEST': 'true', Accept: 'application/json' }
}

/**
 * The headers of a JSON write.
 *
 * @return {object} The headers.
 * @spec openspec/specs/assignments/spec.md#scenario-a-reviewer-completes-an-assigned-peerreview
 */
export function writeHeaders() {
	return {
		'OCS-APIREQUEST': 'true',
		Accept: 'application/json',
		'Content-Type': 'application/json',
	}
}

/**
 * Read one object. The object is the answer's `object`, else the answer.
 *
 * @param {string} url The object's URL.
 * @return {Promise<{ok: boolean, status: number, object: (object|undefined)}>} What came back.
 * @spec openspec/specs/assignments/spec.md#scenario-a-reviewer-completes-an-assigned-peerreview
 */
export async function readObject(url) {
	const resp = await fetch(url, { headers: readHeaders() })
	if (!resp.ok) {
		return { ok: false, status: resp.status, object: undefined }
	}
	const json = await resp.json()
	return { ok: true, status: resp.status, object: json.object ?? json }
}

/**
 * Send a JSON body.
 *
 * @param {string} url The URL.
 * @param {{method: string, body: object}} request The method and body.
 * @return {Promise<Response>} The response.
 * @spec openspec/specs/assignments/spec.md#scenario-a-reviewer-completes-an-assigned-peerreview
 */
export function sendJson(url, request) {
	return fetch(url, {
		method: request.method,
		headers: writeHeaders(),
		body: JSON.stringify(request.body),
	})
}

/**
 * Send a JSON body and throw when it is refused.
 *
 * @param {string} url The URL.
 * @param {{method: string, body: object, failure: string}} request The method,
 *   the body and the message a refusal throws (followed by the status).
 * @return {Promise<Response>} The response.
 * @spec openspec/specs/assignments/spec.md#scenario-a-reviewer-completes-an-assigned-peerreview
 */
export async function sendJsonOrThrow(url, request) {
	const resp = await sendJson(url, request)
	if (!resp.ok) {
		throw new Error(`${request.failure}: ${resp.status}`)
	}
	return resp
}

/**
 * The rubric scores of the selected levels.
 *
 * @param {Record<string, {levelId: string, points: number}>} selectedLevels Criterion id to level.
 * @return {Array<{criterionId: string, levelId: string, points: number}>} The scores.
 * @spec openspec/specs/assignments/spec.md#scenario-a-reviewer-completes-an-assigned-peerreview
 */
export function rubricScoresOf(selectedLevels) {
	return Object.entries(selectedLevels).map(([criterionId, sel]) => ({
		criterionId,
		levelId: sel.levelId,
		points: sel.points,
	}))
}

/**
 * The sum of the points of the selected levels.
 *
 * @param {Record<string, {points: number}>} selectedLevels Criterion id to level.
 * @return {number} The total.
 * @spec openspec/specs/assignments/spec.md#scenario-a-reviewer-completes-an-assigned-peerreview
 */
export function rubricPoints(selectedLevels) {
	return Object.values(selectedLevels).reduce(
		(sum, sel) => sum + (sel.points || 0),
		0,
	)
}

/** The `submit` transition, refused with the status. */
export const SUBMIT_TRANSITION = Object.freeze({
	method: 'POST',
	body: Object.freeze({ action: 'submit' }),
	failure: 'Submit transition failed',
})

/**
 * Read an object a page cannot do without; a refusal throws.
 *
 * @param {string} url The object's URL.
 * @param {string} failure The message a refusal throws (followed by the status).
 * @return {Promise<object>} The object, `{}` when the answer is empty.
 * @spec openspec/specs/assignments/spec.md#scenario-a-reviewer-completes-an-assigned-peerreview
 */
export async function readRequiredObject(url, failure) {
	const read = await readObject(url)
	if (!read.ok) {
		throw new Error(`${failure}: ${read.status}`)
	}
	return read.object ?? {}
}

/**
 * Read the first row of a list. A refusal reads nothing; an answer without
 * rows reads null.
 *
 * @param {string} url The list's URL.
 * @return {Promise<{ok: boolean, row: (object|null|undefined)}>} What came back.
 * @spec openspec/specs/assignments/spec.md#scenario-marksubmissionview-shows-peer-and-self-assessment-as-read-only-context
 */
export async function readFirstRow(url) {
	const resp = await fetch(url, { headers: readHeaders() })
	if (!resp.ok) {
		return { ok: false, row: undefined }
	}
	const json = await resp.json()
	const results =
		json.results ?? json.objects ?? (Array.isArray(json) ? json : [])
	return { ok: true, row: results.length > 0 ? results[0] : null }
}

/**
 * Whether every criterion of a rubric has a selected level. A missing rubric,
 * or one without criteria, is complete. A client-side hint only: the server
 * enforces this via RubricScoresCompletionGuard regardless.
 *
 * @param {object|null} rubric The rubric.
 * @param {Record<string, object|null>} selectedLevels Criterion id to level.
 * @return {boolean}
 * @spec openspec/specs/assignments/spec.md#scenario-a-reviewer-completes-an-assigned-peerreview
 */
export function rubricComplete(rubric, selectedLevels) {
	if (!rubric || !rubric.criteria || rubric.criteria.length === 0) {
		return true
	}
	return rubric.criteria.every(
		(c) => (selectedLevels[c.criterionId] ?? null) !== null,
	)
}

/**
 * Put saved rubric scores back into the selected levels, in place.
 *
 * @param {Record<string, {levelId: string, points: number}>} selectedLevels Criterion id to level.
 * @param {Array<{criterionId: string, levelId: string, points: number}>} scores The saved scores.
 * @return {void}
 * @spec openspec/specs/assignments/spec.md#scenario-a-reviewer-completes-an-assigned-peerreview
 */
export function restoreLevels(selectedLevels, scores) {
	for (const score of scores) {
		selectedLevels[score.criterionId] = {
			levelId: score.levelId,
			points: score.points,
		}
	}
}
