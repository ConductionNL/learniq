/**
 * Pure helpers for the timetable and SWV exchange settings section and the
 * timetable import dialog (timetable-connection-and-import-screen).
 *
 * Plain ES module (not a .vue SFC) so it is directly importable from a Node
 * test runner without a build step.
 *
 * @spec openspec/changes/timetable-connection-and-import-screen/specs/timetabling/spec.md#requirement-an-administrator-keeps-the-group-code-maps-and-the-swv-receiver-on-the-admin-page
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/** The settings endpoint, relative to generateUrl(). */
export const SETTINGS_URL = '/apps/learniq/api/admin/timetable-exchange'

/** The import endpoint and its access check, relative to generateUrl(). */
export const IMPORT_URL = '/apps/learniq/api/timetable/imports'
export const IMPORT_ACCESS_URL = '/apps/learniq/api/timetable/imports/access'

/** The rostering systems' display names; the ids are the server's. */
export const SOURCE_LABELS = {
	'roster-zermelo': 'Zermelo',
	'roster-untis-oneroster': 'Untis',
	'roster-xedule': 'Xedule',
	'roster-timeedit': 'TimeEdit',
}

/**
 * Turn the server's maps into editable rows per source.
 *
 * @param {Array<string>} sources The source ids, in order.
 * @param {object} groupMaps `{source: {groupCode: cohortId}}`.
 * @return {object} `{source: [{code, cohortId}]}`, one entry per source.
 * @spec openspec/changes/timetable-connection-and-import-screen/specs/timetabling/spec.md#requirement-an-administrator-keeps-the-group-code-maps-and-the-swv-receiver-on-the-admin-page
 */
export function mapsToRows(sources, groupMaps) {
	const rows = {}
	for (const source of sources || []) {
		const map = (groupMaps && groupMaps[source]) || {}
		rows[source] = Object.entries(map).map(([code, cohortId]) => ({
			code,
			cohortId,
		}))
	}
	return rows
}

/**
 * Turn the edited rows back into maps, dropping rows without a code or cohort.
 * A code that appears twice keeps its last cohort.
 *
 * @param {object} rows `{source: [{code, cohortId}]}`.
 * @return {object} `{source: {groupCode: cohortId}}`.
 * @spec openspec/changes/timetable-connection-and-import-screen/specs/timetabling/spec.md#requirement-an-administrator-keeps-the-group-code-maps-and-the-swv-receiver-on-the-admin-page
 */
export function rowsToMaps(rows) {
	const maps = {}
	for (const [source, list] of Object.entries(rows || {})) {
		const map = {}
		for (const row of list || []) {
			const code = String(row?.code ?? '').trim()
			const cohortId = String(row?.cohortId ?? '').trim()
			if (code !== '' && cohortId !== '') {
				map[code] = cohortId
			}
		}
		maps[source] = map
	}
	return maps
}
