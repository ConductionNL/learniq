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
	const params = {}
	if (from) {
		params.from = from
	}
	if (to) {
		params.to = to
	}

	const url = generateUrl('/apps/learniq/api/timetable/mine')
	const response = await axios.get(url, { params })

	const data = response.data || {}
	return {
		sessions: Array.isArray(data.sessions) ? data.sessions : [],
		from: data.from || from || '',
		to: data.to || to || '',
		changes: Array.isArray(data.changes) ? data.changes : [],
		source: data.source || 'learniq',
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
 * @spec openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-both-timetable-pages-read-through-the-adapter-req-005
 */
export async function fetchCohortTimetable(cohortId, from, to) {
	const params = {}
	if (from) {
		params.from = from
	}
	if (to) {
		params.to = to
	}

	const url = generateUrl('/apps/learniq/api/timetable/cohort/{cohortId}', {
		cohortId,
	})
	const response = await axios.get(url, { params })

	const data = response.data || {}
	return {
		sessions: Array.isArray(data.sessions) ? data.sessions : [],
		from: data.from || from || '',
		to: data.to || to || '',
		source: data.source || 'learniq',
	}
}

/**
 * Whether a session is a learniq Session that can be opened and managed, as
 * opposed to a lesson from planninq's school timetable.
 *
 * @param {object} session A session from either timetable endpoint.
 *
 * @return {boolean} True for a learniq Session.
 * @spec openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-both-timetable-pages-read-through-the-adapter-req-005
 */
export function isLearniqSession(session) {
	return Boolean(session?.id) && (session.source ?? 'learniq') === 'learniq'
}
