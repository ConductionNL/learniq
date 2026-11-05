/**
 * The detail page as the boards draw it, declared once.
 *
 * A `type: "detail"` page that carries `config.board` is expanded here into
 * the nextcloud-vue detail config the boards share: one tab strip holding the
 * page's own cards, the record history as the last tab and as the last side
 * card, no Follow control, no per-card Actions menus and no object sidebar.
 * The page keeps its own widgets, side column, pills and header actions; this
 * only adds what every such page repeats.
 *
 * `config.board`:
 * - `tabs`: the tabs before Historie, each `{ label, widgetIds }` (a stack of
 *   cards) or `{ label, widgetId }`; an entry may carry `countFrom`.
 * - `history`: `false` for none, else `{ tab?, card?, actionLabels?,
 *   changeLabels?, valueLabels? }`; `tab` and `card` default to true.
 *
 * Plain ES module (not a .vue SFC) so it is directly importable from a Node
 * test runner without a build step.
 *
 * @spec openspec/changes/detail-pages-follow-the-boards/specs/nextcloud-app/spec.md#requirement-a-detail-page-follows-its-board
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

export const BOARD_TABS_ID = 'board-tabs'
export const BOARD_HISTORY_ID = 'board-history'
export const BOARD_HISTORY_CARD_ID = 'board-history-card'
export const BOARD_HISTORY_TITLE = 'Record history'

/**
 * The audit-trail widget that draws the record history.
 *
 * @param {string} id The widget id.
 * @param {object} history The page's `board.history` block.
 * @param {number} maxDisplay How many entries it shows.
 * @return {object} The widget definition.
 * @spec openspec/changes/detail-pages-follow-the-boards/specs/nextcloud-app/spec.md#requirement-a-detail-page-follows-its-board
 */
function historyWidget(id, history, maxDisplay) {
	const content = { excludeActions: ['read'], maxDisplay, layout: 'stacked' }
	for (const key of ['actionLabels', 'changeLabels', 'valueLabels']) {
		if (history[key]) {
			content[key] = history[key]
		}
	}
	return { id, type: 'audit-trail', title: BOARD_HISTORY_TITLE, icon: 'History', content }
}

/**
 * Expand one page's `config.board` into its detail config.
 *
 * @param {object} page A manifest page.
 * @return {object} The page, expanded when it declares `config.board`, else as given.
 * @spec openspec/changes/detail-pages-follow-the-boards/specs/nextcloud-app/spec.md#requirement-a-detail-page-follows-its-board
 */
export function expandBoardDetail(page) {
	const board = page?.config?.board
	if (page?.type !== 'detail' || !board || typeof board !== 'object') {
		return page
	}
	const config = { ...page.config }
	delete config.board
	delete config.sidebar
	const history = board.history === false ? null : { tab: true, card: true, ...(board.history || {}) }
	const widgets = [...(config.widgets || [])]
	const tabs = (board.tabs || []).map((tab) => ({ ...tab }))
	if (history?.tab) {
		widgets.push(historyWidget(BOARD_HISTORY_ID, history, 50))
		tabs.push({ widgetId: BOARD_HISTORY_ID, label: BOARD_HISTORY_TITLE })
	}
	widgets.push({
		id: BOARD_TABS_ID,
		type: 'tabs',
		title: 'Panels',
		content: { ariaLabel: 'Details', tabs },
	})
	const sideColumn = [...(config.sideColumn || [])]
	if (history?.card) {
		sideColumn.push(historyWidget(BOARD_HISTORY_CARD_ID, history, 5))
	}
	return {
		...page,
		config: {
			follow: false,
			showWidgetActions: false,
			...config,
			widgets,
			sideColumn,
			layout: [{ id: '1', widgetId: BOARD_TABS_ID, gridX: 0, gridY: 0, gridWidth: 12, gridHeight: 18, showTitle: false }],
		},
	}
}

/**
 * Expand every board detail page of a manifest or manifest fragment.
 *
 * @param {object} manifest A manifest or a `manifest.d` fragment.
 * @return {object} A copy whose board detail pages are expanded.
 * @spec openspec/changes/detail-pages-follow-the-boards/specs/nextcloud-app/spec.md#requirement-a-detail-page-follows-its-board
 */
export function expandBoardDetailPages(manifest) {
	if (!manifest || !Array.isArray(manifest.pages)) {
		return manifest
	}
	return { ...manifest, pages: manifest.pages.map(expandBoardDetail) }
}
