// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// A detail page follows its board: `config.board` expands into one tab strip
// with the record history last, a history side card, no Follow control, no
// per-card Actions menus and no object sidebar; the drawn detail pages hold
// that expansion (scripts/build-board-detail.mjs) and no longer show the
// generic Data and Related cards.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	BOARD_HISTORY_CARD_ID,
	BOARD_HISTORY_ID,
	BOARD_TABS_ID,
	expandBoardDetail,
	expandBoardDetailPages,
} from '../../src/utils/boardDetail.js'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const readJson = (path) => JSON.parse(readFileSync(resolve(root, path), 'utf8'))

const pages = [...readJson('src/manifest.json').pages]
for (const file of readdirSync(resolve(root, 'src/manifest.d')).filter((f) => f.endsWith('.json'))) {
	pages.push(...(readJson(`src/manifest.d/${file}`).pages ?? []))
}
const byId = new Map(pages.map((page) => [page.id, page]))

// The detail pages the zuiddrecht school boards draw (lane LQB-B, G2 and G7).
const BOARD_PAGES = [
	'ApplicationDetail', 'AttendanceFlagDetail', 'AttendanceRecordDetail', 'AttendanceThresholdDetail',
	'BpvPlacementDetail', 'BsaDecisionDetail', 'BsaTrajectoryDetail', 'ConcernReportDetail',
	'DataSubjectRequestDetail', 'DossierNoteDetail', 'ExamPeriodDetail', 'FeeItemDetail', 'LearnerProfileDetail',
	'AssessmentDetail', 'CohortDetail', 'CourseDetail', 'GradeEntryDetail', 'GradeScaleDetail', 'GroupPlanDetail',
	'ItemBankDetail', 'LearningPlanDetail', 'SessionDetail',
]

const sample = {
	id: 'ThingDetail',
	type: 'detail',
	config: {
		register: 'learniq',
		schema: 'thing',
		sidebar: { enabled: true },
		widgets: [{ id: 'facts', type: 'facts', title: 'Thing' }],
		sideColumn: [{ id: 'side', type: 'facts', title: 'Linked' }],
		board: { tabs: [{ label: 'Overview', widgetIds: ['facts'] }] },
	},
}

test('a board page gets one tab strip with the history as the last tab', () => {
	const { config } = expandBoardDetail(sample)
	assert.deepEqual(config.layout.map((cell) => cell.widgetId), [BOARD_TABS_ID])
	const strip = config.widgets.find((widget) => widget.id === BOARD_TABS_ID)
	assert.deepEqual(strip.content.tabs.map((tab) => tab.widgetIds?.[0] ?? tab.widgetId), ['facts', BOARD_HISTORY_ID])
	const history = config.widgets.find((widget) => widget.id === BOARD_HISTORY_ID)
	assert.equal(history.type, 'audit-trail')
	assert.deepEqual(history.content.excludeActions, ['read'])
})

test('the history is also the last side card, after the page own cards', () => {
	const { config } = expandBoardDetail(sample)
	assert.deepEqual(config.sideColumn.map((widget) => widget.id), ['side', BOARD_HISTORY_CARD_ID])
})

test('a board page has no Follow control, no card menus and no sidebar, and keeps its board key', () => {
	const { config } = expandBoardDetail(sample)
	assert.equal(config.follow, false)
	assert.equal(config.showWidgetActions, false)
	assert.equal('sidebar' in config, false)
	assert.deepEqual(config.board, sample.config.board)
})

test('expanding twice gives the same page', () => {
	const once = expandBoardDetail(sample)
	assert.deepEqual(expandBoardDetail(once), once)
})

test('history false draws neither the tab nor the card', () => {
	const page = { ...sample, config: { ...sample.config, board: { tabs: [], history: false } } }
	const { config } = expandBoardDetail(page)
	assert.equal(config.widgets.some((widget) => widget.type === 'audit-trail'), false)
	assert.deepEqual(config.sideColumn.map((widget) => widget.id), ['side'])
})

test('a page without config.board, or not a detail page, is left as it is', () => {
	const plain = { id: 'X', type: 'detail', config: { widgets: [] } }
	assert.equal(expandBoardDetail(plain), plain)
	const index = { id: 'Y', type: 'index', config: { board: { tabs: [] } } }
	assert.equal(expandBoardDetail(index), index)
	assert.deepEqual(expandBoardDetailPages({ pages: [plain] }).pages, [plain])
})

for (const id of BOARD_PAGES) {
	test(`${id} follows its board`, () => {
		const page = byId.get(id)
		assert.ok(page, `${id} exists`)
		assert.deepEqual(expandBoardDetail(page), page, 'the fragment holds the expansion (node scripts/build-board-detail.mjs)')
		const { config } = page
		const ids = new Set(config.widgets.map((widget) => widget.id))
		assert.equal(config.widgets.some((widget) => widget.type === 'data' || widget.type === 'related'), false, 'no generic Data or Related card')
		const strip = config.widgets.find((widget) => widget.id === BOARD_TABS_ID)
		for (const tab of strip.content.tabs) {
			for (const widgetId of tab.widgetIds ?? [tab.widgetId]) {
				assert.ok(ids.has(widgetId), `tab ${tab.label} names ${widgetId}`)
			}
		}
		for (const entry of config.sideColumn) {
			assert.ok(typeof entry !== 'string' || ids.has(entry), `side card ${entry} is declared`)
		}
		assert.equal(config.follow, false)
		assert.ok(config.statusPill || config.typePill || config.breadcrumb, 'header pills or a breadcrumb')
	})
}

test('the decision pages carry a What now card per stage (G7)', () => {
	for (const id of ['BsaDecisionDetail', 'AttendanceFlagDetail']) {
		const { config } = byId.get(id)
		assert.equal(config.stageField, 'lifecycle')
		assert.ok(Object.keys(config.nextStep.stages).length > 0, `${id} names a stage`)
	}
})
