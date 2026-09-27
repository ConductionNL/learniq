// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// curriculum-coverage-matrix-view: the builders behind the coverage matrix and
// the gap list, pinned against CurriculumCoverage rows shaped exactly as
// CurriculumCoverageCalculator writes them.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	coverageMatrix,
	gapList,
	goalLabel,
	goalTree,
	rowInSelection,
	statusLabel,
	subjectIds,
	subjectOptions,
	subjectSelection,
	yearLabels,
} from '../../src/utils/curriculumCoverage.js'

const LABELS = {
	plannedAssessed: 'Planned and assessed',
	planned: 'Planned',
	assessed: 'Assessed',
	notCovered: 'Not covered',
	allYears: 'All years',
	allSubjects: 'All subjects',
	noSubject: 'No subject',
}

// Domain D1 (rekenen) holds K1 and K2; K3 is a root goal with no subject.
const GOALS = [
	{ id: 'k3', code: 'K3', title: 'Meten', order: 2 },
	{ id: 'k2', code: 'K2', title: 'Breuken', parentId: 'd1', order: 2 },
	{ id: 'd1', code: 'D1', title: 'Getallen', order: 1 },
	{ id: 'k1', code: 'K1', title: 'Tellen', parentId: 'd1', order: 1 },
	{
		id: 'kx',
		code: 'KX',
		title: 'Oud',
		parentId: 'd1',
		order: 3,
		lifecycle: 'archived',
	},
]

const K1 = {
	competencyId: 'k1',
	planned: true,
	assessed: true,
	plannedDepth: 'master',
	assessedDepth: 'practise',
	plannedRefs: 3,
	assessedRefs: 1,
}
const K2 = {
	competencyId: 'k2',
	planned: true,
	assessed: false,
	plannedDepth: null,
	assessedDepth: null,
	plannedRefs: 1,
	assessedRefs: 0,
}
const K3 = {
	competencyId: 'k3',
	planned: false,
	assessed: false,
	plannedDepth: null,
	assessedDepth: null,
	plannedRefs: 0,
	assessedRefs: 0,
}

function row(year, subjectScope, subjectId, goals) {
	return {
		frameworkId: 'fw',
		year,
		subjectScope,
		subjectId,
		goals,
		uncoveredIds: goals
			.filter((g) => !g.planned && !g.assessed)
			.map((g) => g.competencyId),
		plannedNotAssessedIds: goals
			.filter((g) => g.planned && !g.assessed)
			.map((g) => g.competencyId),
	}
}

// K1 and K2 apply to groep 5 only; K3 applies to every year.
const ROWS = [
	row(null, 'all', null, [K1, K2, K3]),
	row(null, 'subject', 'rekenen', [K1, K2]),
	row(null, 'none', null, [K3]),
	row('groep 10', 'all', null, [K3]),
	row('groep 10', 'none', null, [K3]),
	row('groep 5', 'all', null, [K1, K2, K3]),
	row('groep 5', 'subject', 'rekenen', [K1, K2]),
	row('groep 5', 'none', null, [K3]),
]

test('a subject key maps to a selection that picks the matching rows', () => {
	assert.deepEqual(subjectSelection('all'), {
		subjectScope: 'all',
		subjectId: null,
	})
	assert.deepEqual(subjectSelection('none'), {
		subjectScope: 'none',
		subjectId: null,
	})
	assert.deepEqual(subjectSelection('rekenen'), {
		subjectScope: 'subject',
		subjectId: 'rekenen',
	})
	assert.equal(rowInSelection(ROWS[1], subjectSelection('rekenen')), true)
	assert.equal(rowInSelection(ROWS[1], subjectSelection('other')), false)
	assert.equal(rowInSelection(ROWS[2], subjectSelection('all')), false)
})

test('year labels sort naturally and skip the all-years row', () => {
	assert.deepEqual(yearLabels(ROWS), ['groep 5', 'groep 10'])
})

test('the goal tree is depth first, sibling ordered, without archived goals', () => {
	const tree = goalTree(GOALS)
	assert.deepEqual(
		tree.map((n) => [n.id, n.depth, n.isLeaf]),
		[
			['d1', 0, false],
			['k1', 1, true],
			['k2', 1, true],
			['k3', 0, true],
		],
	)
	assert.equal(goalLabel(tree[1]), '    K1 · Tellen')
})

test('a cell says planned, assessed, both or neither in words, with the depth label', () => {
	assert.equal(
		statusLabel(K1, LABELS, { master: 'Beheerst' }),
		'Planned and assessed (Beheerst)',
	)
	assert.equal(statusLabel(K2, LABELS), 'Planned')
	assert.equal(
		statusLabel({ assessed: true, assessedDepth: 'practise' }, LABELS),
		'Assessed (practise)',
	)
	assert.equal(statusLabel(K3, LABELS), 'Not covered')
})

test("the matrix shows goals under their domain, years as columns, cells only in a goal's years", () => {
	const { columns, rows, total } = coverageMatrix({
		goals: GOALS,
		coverageRows: ROWS,
		selection: subjectSelection('all'),
		labels: LABELS,
	})
	assert.deepEqual(
		columns.map((c) => c.key),
		['y:groep 5', 'y:groep 10'],
	)
	assert.equal(
		columns.every((c) => c.readOnly === true),
		true,
	)
	assert.deepEqual(
		rows.map((r) => r.id),
		['d1', 'k1', 'k2', 'k3'],
	)
	assert.equal(rows[0].isHeading, true)
	assert.equal(rows[0]['y:groep 5'], '')
	assert.equal(rows[1]['y:groep 5'], 'Planned and assessed (master)')
	assert.equal(rows[1]['y:groep 10'], '', 'K1 does not apply to groep 10')
	assert.equal(rows[3]['y:groep 10'], 'Not covered')
	assert.equal(total, ROWS[0])
})

test('a subject selection narrows the rows; a framework without years gets one column', () => {
	const rekenen = coverageMatrix({
		goals: GOALS,
		coverageRows: ROWS,
		selection: subjectSelection('rekenen'),
		labels: LABELS,
	})
	assert.deepEqual(
		rekenen.rows.map((r) => r.id),
		['d1', 'k1', 'k2'],
	)
	assert.deepEqual(
		rekenen.columns.map((c) => c.key),
		['y:groep 5'],
	)

	const flat = coverageMatrix({
		goals: GOALS,
		coverageRows: [row(null, 'all', null, [K3])],
		selection: subjectSelection('all'),
		labels: LABELS,
	})
	assert.deepEqual(flat.columns, [
		{ key: 'all', label: 'All years', type: 'string', readOnly: true },
	])
	assert.deepEqual(
		flat.rows.map((r) => [r.id, r.all]),
		[['k3', 'Not covered']],
	)
})

test('no coverage rows give an empty matrix, not an error', () => {
	const empty = coverageMatrix({
		goals: GOALS,
		coverageRows: [],
		selection: subjectSelection('all'),
		labels: LABELS,
	})
	assert.deepEqual(empty.rows, [])
	assert.equal(empty.total, null)
})

test('the gap list names uncovered and untested goals per subject and year', () => {
	const sections = gapList({
		goals: GOALS,
		coverageRows: ROWS,
		subjectNames: { rekenen: 'Rekenen' },
		labels: LABELS,
	})
	assert.deepEqual(
		sections.map((s) => [s.subject, s.year, s.notCovered, s.plannedNotAssessed]),
		[
			['No subject', 'groep 5', ['K3 · Meten'], []],
			['No subject', 'groep 10', ['K3 · Meten'], []],
			['Rekenen', 'groep 5', [], ['K2 · Breuken']],
		],
	)
})

test('without years the gap list uses the all-years rows', () => {
	const sections = gapList({
		goals: GOALS,
		coverageRows: [
			row(null, 'all', null, [K2]),
			row(null, 'subject', 'rekenen', [K2]),
		],
		subjectNames: {},
		labels: LABELS,
	})
	assert.deepEqual(
		sections.map((s) => [s.subject, s.year]),
		[['rekenen', 'All years']],
	)
})

test('subject options list all, each named subject, and no subject', () => {
	assert.deepEqual(subjectOptions(ROWS, { rekenen: 'Rekenen' }, LABELS), [
		{ id: 'all', label: 'All subjects' },
		{ id: 'rekenen', label: 'Rekenen' },
		{ id: 'none', label: 'No subject' },
	])
	assert.deepEqual(subjectIds(ROWS), ['rekenen'])
})

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')
const json = (path) => JSON.parse(readFileSync(resolve(ROOT, path), 'utf8'))

test('the page is a menu entry and not a Reports card', () => {
	const learning = json('src/manifest.d/learning.json')
	const page = learning.pages.find((p) => p.id === 'CurriculumCoverageMatrix')
	assert.equal(page.type, 'custom')
	assert.equal(page.route, '/curriculum/coverage')
	assert.equal(page.component, 'CurriculumCoverageMatrixView')

	const group = learning.menu.find((m) => m.id === 'GroupLearning')
	const entry = group.children.find((c) => c.route === 'CurriculumCoverageMatrix')
	const curriculum = group.children.find((c) => c.id === 'Curriculum')
	assert.ok(entry, 'a Learning menu entry routes to the page')
	assert.equal(entry.order, curriculum.order + 1, 'it sits right after Curriculum')

	const reports = json('src/manifest.json').pages.find((p) => p.id === 'Reports')
	assert.equal(
		reports.config.cards.some((c) => c.route === 'CurriculumCoverageMatrix'),
		false,
	)

	const registry = readFileSync(resolve(ROOT, 'src/registry.js'), 'utf8')
	assert.match(
		registry,
		/CurriculumCoverageMatrixView: page\(CurriculumCoverageMatrixView\)/,
	)
})
