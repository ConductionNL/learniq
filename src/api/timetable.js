/**
 * Learniq personal-timetable API.
 *
 * Stateless functions over @nextcloud/axios + generateUrl (no Pinia store,
 * per ADR-004 store-pattern): the personal timetable is a read surface, so a
 * thin fetch helper is all the view needs. The backend resolves the caller's
 * own sessions from cohort membership and RBAC-scopes the result — the client
 * only passes the requested window.
 *
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The query for a time window: only the ends that are given.
 *
 * @param {string} [from] Inclusive ISO 8601 window start.
 * @param {string} [to]   Exclusive ISO 8601 window end.
 *
 * @return {{from?: string, to?: string}} The query.
 */
function windowParams(from, to) {
	const params = {}
	if (from) {
		params.from = from
	}
	if (to) {
		params.to = to
	}
	return params
}

/**
 * A list from the answer, or an empty one.
 *
 * @param {Array<object>|object|string|null} value The answered value.
 *
 * @return {Array<object>} The list.
 */
function listOf(value) {
	return Array.isArray(value) ? value : []
}

/**
 * A window end as the server echoed it, else as requested, else ''.
 *
 * @param {string} [echoed]    The end the server answered with.
 * @param {string} [requested] The end that was asked for.
 *
 * @return {string} The end.
 */
function windowEnd(echoed, requested) {
	return echoed || requested || ''
}

/**
 * The sessions, window and source of a timetable answer.
 *
 * @param {object|null} body      The response body.
 * @param {{from?: string, to?: string}} requested The window that was asked for.
 *
 * @return {{sessions: Array<object>, from: string, to: string, source: string}} The timetable.
 */
function timetableOf(body, requested) {
	const data = body || {}
	return {
		sessions: listOf(data.sessions),
		from: windowEnd(data.from, requested.from),
		to: windowEnd(data.to, requested.to),
		source: data.source || 'learniq',
	}
}

/**
 * Fetch the signed-in caller's own sessions for a time window.
 *
 * When `from`/`to` are omitted the backend defaults to the current ISO week.
 * A caller with no cohorts receives an empty `sessions` list (HTTP 200), never
 * an error. Each session also carries `roomId`/resolved `room` detail,
 * `lifecycle`, `substituteTeacherId`, `changeReasonKind`, and `changeReason`
 * (timetabling-and-substitution). The response's `changes` list carries every
 * Session in the caller's own cohorts whose cancel/substitute-teacher
 * transition occurred today — the "dagrooster" surface — regardless of
 * whether its own `startsAt` falls inside the requested window.
 *
 * @param {string} [from] Inclusive ISO 8601 window start.
 * @param {string} [to]   Exclusive ISO 8601 window end.
 *
 * @return {Promise<{sessions: Array<object>, from: string, to: string, changes: Array<object>, source: string}>} The
 *   ordered session list, the resolved window echoed by the server, today's changes, and the
 *   timetable source (`learniq` or `planninq`).
 */
export async function fetchMyTimetable(from, to) {
	const params = windowParams(from, to)

	const url = generateUrl('/apps/learniq/api/timetable/mine')
	const response = await axios.get(url, { params })

	const timetable = timetableOf(response.data, { from, to })
	return {
		...timetable,
		changes: listOf(response.data?.changes),
	}
}

/**
 * Fetch one cohort's sessions for a time window.
 *
 * The backend reads the cohort with RBAC first (403 when the caller cannot see
 * it) and then asks the current timetable source: planninq's school timetable
 * when planninq is installed, learniq's own Sessions otherwise. Without
 * `from`/`to` the backend returns eight weeks from this week's Monday.
 *
 * @param {string} cohortId The cohort UUID.
 * @param {string} [from]   Inclusive ISO 8601 window start.
 * @param {string} [to]     Exclusive ISO 8601 window end.
 *
 * @return {Promise<{sessions: Array<object>, from: string, to: string, source: string}>} The
 *   ordered sessions, the resolved window and the source they came from.
 * @spec openspec/specs/timetable-source/spec.md#requirement-both-timetable-pages-read-through-the-adapter-req-005
 */
export async function fetchCohortTimetable(cohortId, from, to) {
	const params = windowParams(from, to)

	const url = generateUrl('/apps/learniq/api/timetable/cohort/{cohortId}', {
		cohortId,
	})
	const response = await axios.get(url, { params })

	return timetableOf(response.data, { from, to })
}

/**
 * Whether a session is a learniq Session that can be opened and managed, as
 * opposed to a lesson from planninq's school timetable.
 *
 * @param {object} session A session from either timetable endpoint.
 *
 * @return {boolean} True for a learniq Session.
 * @spec openspec/specs/timetable-source/spec.md#requirement-both-timetable-pages-read-through-the-adapter-req-005
 */
export function isLearniqSession(session) {
	return Boolean(session?.id) && (session.source ?? 'learniq') === 'learniq'
}

/**
 * Fetch another group's, teacher's or room's timetable, as far as the school's
 * visibility policy allows the caller (timetabling-visibility-rules). A refusal
 * is an HTTP 403 error with the reason in `error`.
 *
 * @param {string} kind   `cohort`, `teacher` or `room`.
 * @param {string} id     The cohort UUID, teacher user id or room UUID.
 * @param {string} [from] Inclusive ISO 8601 window start.
 * @param {string} [to]   Exclusive ISO 8601 window end.
 *
 * @return {Promise<{sessions: Array<object>, from: string, to: string, source: string}>} The lessons.
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
 */
export async function fetchTimetableOf(kind, id, from, to) {
	const params = { kind, id, ...windowParams(from, to) }

	const response = await axios.get(generateUrl('/apps/learniq/api/timetable/of'), {
		params,
	})
	return timetableOf(response.data, { from, to })
}

/**
 * Fetch the signed-in caller's own standby blocks for a window
 * (timetabling-standby-slots). A caller without standby hours gets an empty
 * list; a failing read is an empty list too, so the lessons still show.
 *
 * @param {string} from Inclusive ISO 8601 window start.
 * @param {string} to   Exclusive ISO 8601 window end.
 *
 * @return {Promise<Array<{slotId: string, date: string, startsAt: string, endsAt: string}>>} The blocks.
 * @spec openspec/specs/timetabling/spec.md#requirement-a-coordinator-plans-standby-hours
 */
export async function fetchMyStandby(from, to) {
	try {
		const response = await axios.get(
			generateUrl('/apps/learniq/api/standby/mine'),
			{
				params: { from, to },
			},
		)
		return Array.isArray(response.data?.standby) ? response.data.standby : []
	} catch {
		return []
	}
}

/**
 * Whether the signed-in user has a calendar feed address.
 *
 * @return {Promise<boolean>} True when an address exists.
 * @spec openspec/changes/attendance-timetable-calendar-feed/specs/timetable-calendar-feed/spec.md#requirement-calendar-subscription-feed
 */
export async function fetchCalendarFeedStatus() {
	const response = await axios.get(generateUrl('/apps/learniq/api/timetable/feed'))
	return response.data?.exists === true
}

/**
 * Make a new calendar feed address; an earlier one stops working.
 *
 * @return {Promise<{url: string, webcalUrl: string}>} The new address, shown once.
 * @spec openspec/changes/attendance-timetable-calendar-feed/specs/timetable-calendar-feed/spec.md#requirement-revoking-the-feed-address
 */
export async function createCalendarFeed() {
	const response = await axios.post(
		generateUrl('/apps/learniq/api/timetable/feed'),
	)
	return {
		url: String(response.data?.url ?? ''),
		webcalUrl: String(response.data?.webcalUrl ?? ''),
	}
}

/**
 * Remove the calendar feed address.
 *
 * @return {Promise<void>}
 * @spec openspec/changes/attendance-timetable-calendar-feed/specs/timetable-calendar-feed/spec.md#requirement-revoking-the-feed-address
 */
export async function removeCalendarFeed() {
	await axios.delete(generateUrl('/apps/learniq/api/timetable/feed'))
}
