// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Unit tests for the Reports card filter (company-segment-menu-gating).
// Run via `node --test tests/unit-js/` (package.json's `test:js-unit`).

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	applyReportCardGates,
	reportCardPasses,
} from '../../src/utils/reportCardGates.js'

const SCHOOL_ONLY = {
	'workspace.chosenSegment': { notIn: ['corporate'] },
}

/**
 * A manifest with one Reports page and one other page.
 *
 * @param {object} workspace The runtime workspace.
 * @return {object} The manifest.
 */
function manifest(workspace) {
	return {
		runtime: { user: { primaryRole: 'admin' }, workspace },
		pages: [
			{
				id: 'Reports',
				type: 'reports',
				config: {
					cards: [
						{ id: 'AttendanceFlags', visibleIf: SCHOOL_ONLY },
						{ id: 'FinalGrades' },
						{
							id: 'BpvVisitReports',
							visibleIf: {
								'workspace.segment': { in: ['mbo', 'corporate'] },
								...SCHOOL_ONLY,
							},
						},
						{ id: 'SkillsGapDashboard' },
					],
				},
			},
			{
				id: 'Courses',
				type: 'index',
				config: { cards: [{ id: 'x', visibleIf: SCHOOL_ONLY }] },
			},
		],
	}
}

/**
 * The card ids the Reports page keeps.
 *
 * @param {object} result A filtered manifest.
 * @return {Array<string>} Card ids in order.
 */
function cardIds(result) {
	return result.pages.find((p) => p.id === 'Reports').config.cards.map((c) => c.id)
}

test('a chosen company loses the school-only cards and keeps the order of the rest', () => {
	const result = applyReportCardGates(
		manifest({ segment: 'corporate', chosenSegment: 'corporate' }),
	)
	assert.deepEqual(cardIds(result), ['FinalGrades', 'SkillsGapDashboard'])
})

test('an install that never chose keeps every card', () => {
	const result = applyReportCardGates(
		manifest({ segment: 'corporate', chosenSegment: null }),
	)
	assert.deepEqual(cardIds(result), [
		'AttendanceFlags',
		'FinalGrades',
		'BpvVisitReports',
		'SkillsGapDashboard',
	])
})

test('segment gates on cards combine with AND, as in the menu', () => {
	assert.deepEqual(
		cardIds(
			applyReportCardGates(manifest({ segment: 'mbo', chosenSegment: 'mbo' })),
		),
		['AttendanceFlags', 'FinalGrades', 'BpvVisitReports', 'SkillsGapDashboard'],
	)
	assert.deepEqual(
		cardIds(
			applyReportCardGates(manifest({ segment: 'po', chosenSegment: 'po' })),
		),
		['AttendanceFlags', 'FinalGrades', 'SkillsGapDashboard'],
	)
})

test('pages of another type and ungated cards are left alone', () => {
	const input = manifest({ segment: 'corporate', chosenSegment: 'corporate' })
	const result = applyReportCardGates(input)
	assert.equal(
		result.pages.find((p) => p.id === 'Courses'),
		input.pages.find((p) => p.id === 'Courses'),
	)
	assert.equal(reportCardPasses({ id: 'x' }, null), true)
	assert.equal(reportCardPasses({ id: 'x', visibleIf: null }, null), true)
})

test('the input manifest is not mutated', () => {
	const input = manifest({ segment: 'corporate', chosenSegment: 'corporate' })
	applyReportCardGates(input)
	assert.equal(input.pages[0].config.cards.length, 4)
})

test('a manifest without pages is returned as is', () => {
	assert.equal(applyReportCardGates(null), null)
	const bare = { runtime: {} }
	assert.equal(applyReportCardGates(bare), bare)
})
