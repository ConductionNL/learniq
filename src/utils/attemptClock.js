// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// test-screen-autosave-and-deadline: the in-app test screen counts down to
// the deadline the server stamped on the attempt (start plus time limit plus
// the learner's extra time), and saves answers while the learner works.
//
// @spec openspec/changes/test-screen-autosave-and-deadline/specs/assessment/spec.md#requirement-the-in-app-test-screen-shows-the-servers-deadline-and-saves-answers-as-the-learner-works

/**
 * Seconds left until the server's deadline, never below zero.
 *
 * The browser clock may be off, so the offset between the server's clock and
 * the browser's (taken from the Date header of a server response) corrects it.
 *
 * @param {string|null|undefined} deadlineAt ISO-8601 deadline stamped by the server
 * @param {number} nowMs The browser's current time in milliseconds
 * @param {number} [serverOffsetMs] Server time minus browser time, in milliseconds
 * @return {number|null} Whole seconds left, or null when the attempt has no deadline
 */
export function secondsUntilDeadline(deadlineAt, nowMs, serverOffsetMs = 0) {
	if (typeof deadlineAt !== 'string' || deadlineAt === '') {
		return null
	}
	const deadlineMs = Date.parse(deadlineAt)
	if (Number.isNaN(deadlineMs)) {
		return null
	}
	return Math.max(0, Math.floor((deadlineMs - (nowMs + serverOffsetMs)) / 1000))
}

/**
 * Server time minus browser time, from a response's Date header.
 *
 * @param {string|null|undefined} dateHeader The HTTP Date header
 * @param {number} nowMs The browser's time when the response arrived
 * @return {number} The offset in milliseconds, 0 when the header is missing
 */
export function serverOffsetMs(dateHeader, nowMs) {
	const serverMs = typeof dateHeader === 'string' ? Date.parse(dateHeader) : NaN
	return Number.isNaN(serverMs) ? 0 : serverMs - nowMs
}

/**
 * The responses payload the screen saves: one row per item, unscored.
 *
 * @param {Array<{uuid: string}>} items The items shown
 * @param {Object<string, unknown>} responses The answers by item uuid
 * @return {Array<{itemId: string, response: {value: unknown}, autoScore: null, manualScore: null}>} The rows
 */
export function responsesPayload(items, responses) {
	return items.map((item) => ({
		itemId: item.uuid,
		response: { value: responses[item.uuid] ?? null },
		autoScore: null,
		manualScore: null,
	}))
}

/**
 * The answers stored on an attempt, by item uuid, so a resumed attempt shows
 * what autosave already kept.
 *
 * @param {object|null|undefined} result The AssessmentResult
 * @return {Object<string, unknown>} The answers by item uuid
 */
export function answersFromResult(result) {
	const answers = {}
	for (const row of Array.isArray(result?.responses) ? result.responses : []) {
		if (
			row
			&& typeof row.itemId === 'string'
			&& row.response
			&& row.response.value !== null
			&& row.response.value !== undefined
		) {
			answers[row.itemId] = row.response.value
		}
	}
	return answers
}
