// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Who answered a permission request yes, who answered no and who did not
 * answer at all (permission-slips).
 *
 * Pure functions, so the split is pinned by node tests and the section that
 * renders it stays thin. The children asked are the request's `learnerRefs`
 * (learner profile uuids); each has at most one PermissionResponse per
 * request, and when two are found the latest answer counts.
 *
 * @spec openspec/changes/permission-slips/specs/portal-contribution/spec.md#requirement-a-teacher-sees-who-has-not-answered
 */

import { listRows, objectsUrl, oneObject } from './customPages.js'

/** The answers a PermissionResponse can carry. */
export const ANSWERS = ['yes', 'no']

/**
 * A value as a list of distinct non-empty strings, in order.
 *
 * @param {unknown} value An array of strings, or anything else.
 * @return {string[]}
 */
function refsOf(value) {
	if (!Array.isArray(value)) {
		return []
	}
	const seen = new Set()
	for (const v of value) {
		if (typeof v === 'string' && v !== '') {
			seen.add(v)
		}
	}
	return [...seen]
}

/**
 * The time of an answer, for picking the latest; 0 when it has none.
 *
 * @param {object} response A PermissionResponse.
 * @return {number}
 */
function answeredTime(response) {
	const time = Date.parse(response?.answeredAt ?? '')
	return Number.isNaN(time) ? 0 : time
}

/**
 * The latest answer per child, for the children the request asked.
 *
 * @param {object} request The PermissionRequest.
 * @param {object[]} responses Its PermissionResponses.
 * @return {Map<string, object>} learnerRef -> response.
 * @spec openspec/changes/permission-slips/specs/portal-contribution/spec.md#requirement-a-teacher-sees-who-has-not-answered
 */
export function latestAnswers(request, responses) {
	const requestId = request?.id ?? request?.uuid ?? ''
	const asked = new Set(refsOf(request?.learnerRefs))
	const latest = new Map()
	for (const response of responses ?? []) {
		if (!countsFor(response, { requestId, asked })) {
			continue
		}
		const earlier = latest.get(response.learnerRef)
		if (!earlier || answeredTime(response) >= answeredTime(earlier)) {
			latest.set(response.learnerRef, response)
		}
	}
	return latest
}

/**
 * Whether a response is a real answer to this request for a child it asked.
 *
 * @param {object} response A PermissionResponse.
 * @param {{requestId: string, asked: Set<string>}} scope The request's id ('' accepts any request) and the children it asked.
 * @return {boolean}
 */
function countsFor(response, { requestId, asked }) {
	if (!response || !ANSWERS.includes(response.answer)) {
		return false
	}
	if (requestId !== '' && response.permissionRequestId !== requestId) {
		return false
	}
	return asked.has(response.learnerRef)
}

/**
 * The children asked, split by their latest answer, in the order asked.
 *
 * @param {string[]} asked The children the request asked.
 * @param {Map<string, object>} latest learnerRef -> latest response.
 * @return {{yes: string[], no: string[], notAnswered: string[]}}
 */
function splitByAnswer(asked, latest) {
	const split = { yes: [], no: [], notAnswered: [] }
	for (const ref of asked) {
		const answer = latest.get(ref)?.answer
		split[answer === 'yes' || answer === 'no' ? answer : 'notAnswered'].push(ref)
	}
	return split
}

/**
 * The outcome of a request: the children asked, split into answered yes,
 * answered no and not answered, each in the order the request names them.
 * A child nobody answered for counts as refused unless the request says
 * silence is not a refusal; `treatSilenceAsRefusal` defaults to true.
 *
 * @param {object} request The PermissionRequest.
 * @param {object[]} responses Its PermissionResponses.
 * @return {{yes: string[], no: string[], notAnswered: string[], asked: number, answered: number, silenceRefuses: boolean, refused: number, permitted: number}}
 * @spec openspec/changes/permission-slips/specs/portal-contribution/spec.md#requirement-a-teacher-sees-who-has-not-answered
 * @spec openspec/changes/permission-slips/specs/portal-contribution/spec.md#requirement-silence-is-not-permission
 */
export function permissionOutcome(request, responses) {
	const asked = refsOf(request?.learnerRefs)
	const { yes, no, notAnswered } = splitByAnswer(asked, latestAnswers(request, responses))
	const silenceRefuses = request?.treatSilenceAsRefusal !== false
	return {
		yes,
		no,
		notAnswered,
		asked: asked.length,
		answered: yes.length + no.length,
		silenceRefuses,
		refused: no.length + (silenceRefuses ? notAnswered.length : 0),
		permitted: yes.length + (silenceRefuses ? 0 : notAnswered.length),
	}
}

/**
 * Read a request and its answers through `getJson(path, params)`, which
 * answers the decoded body of an app-relative path. The request keeps the
 * asked id when the body has none.
 *
 * @param {string} requestId The PermissionRequest uuid.
 * @param {(path: string, params?: object) => Promise<unknown>} getJson Reads one path.
 * @return {Promise<{request: object, responses: object[]}>}
 * @spec openspec/changes/permission-slips/specs/portal-contribution/spec.md#requirement-a-teacher-sees-who-has-not-answered
 */
export async function loadPermissionAnswers(requestId, getJson) {
	const request = oneObject(await getJson(objectsUrl('permission-request', requestId)))
	const responses = listRows(
		await getJson(objectsUrl('permission-response'), { permissionRequestId: requestId, _limit: 1000 }),
	)
	return { request: { ...request, id: request.id ?? requestId }, responses }
}
