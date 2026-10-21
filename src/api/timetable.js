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

