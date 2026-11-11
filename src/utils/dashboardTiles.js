/**
 * Dashboard tiles: the widget declarations CnDashboardPage takes, built the
 * same way on every dashboard that shows them.
 *
 * Pure functions, tested through tests/unit-js/dashboardConfigs.test.mjs.
 *
 * @spec openspec/specs/dashboard/spec.md#requirement-per-role-group-gated-dashboard-menu-items
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/**
 * The count tiles more than one dashboard shows, by widget id: what they
 * count, how they are coloured and where a click goes.
 *
 * Declared, not written. `type: 'stat'` resolves to the shared CnStatWidget,
 * which aggregates SERVER-side; the wrappers this replaces each did their own
 * fetch and swallowed failure into a zero. `schema` is the OpenRegister SLUG.
 */
const COUNT_TILES = {
	'kpi-courses': { variant: 'primary', path: '/courses', schema: 'course' },
	'kpi-cohorts': { path: '/cohorts', schema: 'cohort' },
	'kpi-learners': {
		variant: 'success',
		path: '/learner-profiles',
		schema: 'learner-profile',
	},
	'kpi-active-enrolments': {
		variant: 'primary',
		path: '/enrolments',
		schema: 'enrolment',
		filter: { lifecycle: 'active' },
	},
	'kpi-open-flags': {
		variant: 'warning',
		path: '/attendance/flags',
		schema: 'attendance-flag',
		filter: { lifecycle: 'open' },
	},
	'kpi-regulations': { path: '/compliance/regulations', schema: 'regulation' },
	'kpi-attestations': {
		variant: 'success',
		path: '/compliance/attestations',
		schema: 'attestation',
	},
	'kpi-external-training': {
		variant: 'success',
		path: '/compliance/external-training',
		schema: 'external-training-record',
	},
}

/**
 * A stat tile: its title is its label.
 *
 * @param {string} id The widget id.
 * @param {{label: string}} content The tile's content.
 * @return {{id: string, title: string, type: string, content: object}} The widget.
 * @spec openspec/specs/dashboard/spec.md#requirement-per-role-group-gated-dashboard-menu-items
 */
export function statTile(id, content) {
	return { id, title: content.label, type: 'stat', content }
}

/**
 * The aggregation source of a count tile.
 *
 * @param {{schema: string, filter: (object|undefined)}} tile The tile's entry in COUNT_TILES.
 * @return {object} The source.
 * @spec openspec/specs/dashboard/spec.md#requirement-per-role-group-gated-dashboard-menu-items
 */
function countSource(tile) {
	const source = { register: 'learniq', schema: tile.schema, metric: 'count' }
	if (tile.filter !== undefined) {
		source.filter = { ...tile.filter }
	}
	return source
}

/**
 * One of the shared count tiles, under its translated label.
 *
 * @param {string} id A key of COUNT_TILES.
 * @param {string} label The translated label.
 * @return {{id: string, title: string, type: string, content: object}} The widget.
 * @spec openspec/specs/dashboard/spec.md#requirement-per-role-group-gated-dashboard-menu-items
 */
export function countTile(id, label) {
	const tile = COUNT_TILES[id]
	const content = { label }
	if (tile.variant !== undefined) {
		content.variant = tile.variant
	}
	content.clickRoute = { path: tile.path }
	content.source = countSource(tile)
	return statTile(id, content)
}

/**
 * A tile the page fills from its own slot.
 *
 * @param {string} id The widget id.
 * @param {string} title The translated title.
 * @return {{id: string, title: string, type: string}} The widget.
 * @spec openspec/specs/dashboard/spec.md#requirement-per-role-group-gated-dashboard-menu-items
 */
export function customTile(id, title) {
	return { id, title, type: 'custom' }
}

/**
 * Copies of a layout's tiles, so the grid can move a tile without changing
 * the declaration it came from.
 *
 * @param {Array<object>} tiles The layout.
 * @return {Array<object>} The copies.
 * @spec openspec/specs/dashboard/spec.md#requirement-per-role-group-gated-dashboard-menu-items
 */
export function freshLayout(tiles) {
	return tiles.map((tile) => ({ ...tile }))
}
