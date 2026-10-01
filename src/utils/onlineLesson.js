// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

const HTTPS = /^https:\/\/[^\s/?#]+\S*$/

/**
 * The link a lesson's Join action opens: its https meeting link, or ''.
 *
 * The server stores and projects only https links; this check keeps the page
 * safe on its own as well (timetabling-online-lesson-link).
 *
 * @param {object|null} session A projected session.
 * @return {string} The link, or '' for no Join action.
 * @spec openspec/changes/timetabling-online-lesson-link/specs/timetable-online-lesson-link/spec.md#requirement-online-meeting-link-on-a-lesson
 */
export function joinUrl(session) {
	const link = session?.onlineMeetingUrl
	return typeof link === 'string' && HTTPS.test(link) ? link : ''
}
