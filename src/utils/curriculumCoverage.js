// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// curriculum-coverage-matrix-view: the pure builders behind
// CurriculumCoverageMatrixView. They turn a framework's goals and its
// CurriculumCoverage rows (curriculum-coverage-rollup) into the CnDataMatrix
// rows and columns and the gap list. No I/O and no translation: the view
// passes the translated labels in, so every rule is tested with node --test.

/**
 * Lifecycle states that take a goal out of the matrix, as in the rollup.
 */
const INACTIVE = ['archived', 'retired']

/**
 * The subject selections the view offers: all subjects, one subject, or the
 * goals that belong to no subject.
 *
 * @param {string} key 'all', 'none' or a Course UUID.
 * @return {{subjectScope: string, subjectId: string|null}} The selection.
 * @spec openspec/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
export function subjectSelection(key) {
	if (key === 'all' || !key) return { subjectScope: 'all', subjectId: null }
	if (key === 'none') return { subjectScope: 'none', subjectId: null }
	return { subjectScope: 'subject', subjectId: key }
}

/**
 * Whether a coverage row belongs to a subject selection.
 *
 * @param {object} row A CurriculumCoverage row.
 * @param {{subjectScope: string, subjectId: string|null}} selection The selection.
 * @return {boolean} True when it matches.
 * @spec openspec/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
export function rowInSelection(row, selection) {
	if ((row?.subjectScope ?? '') !== selection.subjectScope) return false
	if (selection.subjectScope !== 'subject') return true
	return (row.subjectId ?? null) === selection.subjectId
}

/**
 * The year labels of a set of coverage rows, in natural order ("groep 2"
 * before "groep 10"), without the all-years row.
 *
 * @param {object[]} rows CurriculumCoverage rows.
 * @return {string[]} The labels.
 * @spec openspec/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
export function yearLabels(rows) {
	const years = new Set()
	for (const row of rows) {
		if (typeof row?.year === 'string' && row.year !== '') years.add(row.year)
	}
	return [...years].sort((a, b) =>
		a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }),
	)
}

/**
 * A goal's id: its id, else its uuid, as a string ('' when it has neither).
 *
 * @param {object} goal A Competency row.
 * @return {string} The id.
 * @spec openspec/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
function goalId(goal) {
	return String(goal?.id ?? goal?.uuid ?? '')
}

/**
 * The active goals that carry an id, by id.
 *
 * @param {object[]} goals Competency rows of one framework.
 * @return {Map<string, object>} The goals.
 * @spec openspec/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
function activeGoals(goals) {
	const nodes = new Map()
	for (const goal of goals) {
		const id = goalId(goal)
		if (id && !INACTIVE.includes(goal.lifecycle)) nodes.set(id, goal)
	}
	return nodes
}

/**
 * A goal's sibling order; a goal without one comes last.
 *
 * @param {object} goal A Competency row.
 * @return {number} The order.
 * @spec openspec/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
function orderOf(goal) {
	return goal.order ?? Number.MAX_SAFE_INTEGER
}

/**
 * A goal's code, '' when it has none.
 *
 * @param {object} goal A Competency row.
 * @return {string} The code.
 * @spec openspec/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
function codeOf(goal) {
	return String(goal.code ?? '')
}

/**
 * Siblings by order, then by code in natural order.
 *
 * @param {object} a A Competency row.
 * @param {object} b Another.
 * @return {number} The comparison.
 * @spec openspec/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
function siblingOrder(a, b) {
	const byOrder = orderOf(a) - orderOf(b)
	if (byOrder !== 0) return byOrder
	return codeOf(a).localeCompare(codeOf(b), undefined, { numeric: true })
}

/**
 * The parent a goal hangs under in the tree: an active goal other than
 * itself, '' when it is a root.
 *
 * @param {Map<string, object>} nodes The active goals.
 * @param {string} id The goal.
 * @return {string} The parent id.
 * @spec openspec/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
function parentInTree(nodes, id) {
	const parent = String(nodes.get(id).parentId ?? '')
	return parent && parent !== id && nodes.has(parent) ? parent : ''
}

/**
 * Hang one goal under its parent, or among the roots.
 *
 * @param {{nodes: Map<string, object>, children: Map<string, string[]>, roots: string[]}} run The tree being built.
 * @param {string} id The goal.
 * @return {void}
 * @spec openspec/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
function linkGoal(run, id) {
	const parent = parentInTree(run.nodes, id)
	if (!parent) {
		run.roots.push(id)
		return
	}
	if (!run.children.has(parent)) run.children.set(parent, [])
	run.children.get(parent).push(id)
}

/**
 * The tree node of one goal.
 *
 * @param {object} goal The Competency row.
 * @param {{id: string, depth: number, isLeaf: boolean}} place Where it sits.
 * @return {{id: string, code: string, title: string, depth: number, isLeaf: boolean, parentId: string}} The node.
 * @spec openspec/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
function treeNode(goal, place) {
	return {
		id: place.id,
		code: codeOf(goal),
		title: String(goal.title ?? ''),
		depth: place.depth,
		isLeaf: place.isLeaf,
		parentId: String(goal.parentId ?? ''),
	}
}

/**
 * Add a goal and, depth first, its children to the ordered nodes; a goal
 * already added is not added again.
 *
 * @param {{nodes: Map<string, object>, children: Map<string, string[]>, ordered: object[], seen: Set<string>}} run The tree being built.
 * @param {{id: string, depth: number}} step The goal and its depth.
 * @return {void}
 * @spec openspec/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
function visitGoal(run, step) {
	if (run.seen.has(step.id)) return
	run.seen.add(step.id)
	const kids = run.children.get(step.id) ?? []
	const place = { id: step.id, depth: step.depth, isLeaf: kids.length === 0 }
	run.ordered.push(treeNode(run.nodes.get(step.id), place))
	for (const kid of kids) visitGoal(run, { id: kid, depth: step.depth + 1 })
}

/**
 * The active goals in tree order (depth first, siblings by order then
 * code), each with its depth and whether it is a leaf.
 *
 * @param {object[]} goals Competency rows of one framework.
 * @return {Array<{id: string, code: string, title: string, depth: number, isLeaf: boolean, parentId: string}>} The ordered nodes.
 * @spec openspec/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
export function goalTree(goals) {
	const nodes = activeGoals(goals)
	const run = { nodes, children: new Map(), roots: [], ordered: [], seen: new Set() }
	const sorted = [...nodes.entries()].sort((a, b) => siblingOrder(a[1], b[1]))
	for (const [id] of sorted) linkGoal(run, id)
	for (const root of run.roots) visitGoal(run, { id: root, depth: 0 })
	return run.ordered
}

/**
 * A goal's code and title, as one text.
 *
 * @param {{code: string, title: string}} node A tree node.
 * @return {string} The text.
 * @spec openspec/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
function goalText(node) {
	return [node.code, node.title].filter(Boolean).join(' · ')
}

/**
 * A goal's display label: its code and title, indented by tree depth with
 * non-breaking spaces so the matrix shows the tree.
 *
 * @param {{code: string, title: string, depth: number}} node A tree node.
 * @return {string} The label.
 * @spec openspec/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
export function goalLabel(node) {
	return '    '.repeat(node.depth) + goalText(node)
}

/**
 * The word for a goal's coverage: planned, assessed, both or neither.
 *
 * @param {object} detail The goal's entry from a coverage row's `goals`.
 * @param {object} labels Translated labels: plannedAssessed, planned, assessed, notCovered.
 * @return {string} The word.
 * @spec openspec/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
function coverageWord(detail, labels) {
	if (detail?.planned && detail?.assessed) return labels.plannedAssessed
	if (detail?.planned) return labels.planned
	if (detail?.assessed) return labels.assessed
	return labels.notCovered
}

/**
 * The depth a covered goal shows: the planned depth, else the assessed one;
 * null when the goal is not covered or no depth is known.
 *
 * @param {object} detail The goal's entry from a coverage row's `goals`.
 * @return {string|null} The level id.
 * @spec openspec/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
function shownDepth(detail) {
	if (!detail?.planned && !detail?.assessed) return null
	return detail.plannedDepth ?? detail.assessedDepth ?? null
}

/**
 * The words a cell shows for one goal: planned, assessed, both or neither,
 * with the deepest depth's label when one is known. Words, not colour, so the
 * status reads the same for everyone.
 *
 * @param {object} detail The goal's entry from a coverage row's `goals`.
 * @param {{labels: object, levelLabels: (object|undefined)}} wording Translated labels
 *   (plannedAssessed, planned, assessed, notCovered) and, optionally, levelId to
 *   label from the framework.
 * @return {string} The cell text.
 * @spec openspec/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
export function statusLabel(detail, wording) {
	const text = coverageWord(detail, wording.labels)
	const depth = shownDepth(detail)
	if (depth === null) return text
	const levelLabels = wording.levelLabels ?? {}
	return `${text} (${levelLabels[depth] ?? depth})`
}

/**
 * The matrix columns: one per year, or one for all years when there are none.
 *
 * @param {string[]} years The year labels.
 * @param {object} labels Translated labels (allYears).
 * @return {object[]} The columns.
 * @spec openspec/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
function matrixColumns(years, labels) {
	if (years.length === 0) {
		return [{ key: 'all', label: labels.allYears, type: 'string', readOnly: true }]
	}
	return years.map((year) => ({
		key: `y:${year}`,
		label: year,
		type: 'string',
		readOnly: true,
	}))
}

/**
 * The total row's goal entries, by goal id.
 *
 * @param {object|null} total The selection's all-years row.
 * @return {Map<string, object>} The entries.
 * @spec openspec/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
function goalDetails(total) {
	return new Map((total?.goals ?? []).map((goal) => [goal.competencyId, goal]))
}

/**
 * The goals each year row covers, by year.
 *
 * @param {object[]} selected The selection's coverage rows.
 * @return {Map<string, Set<string>>} The goal ids per year.
 * @spec openspec/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
function yearGoals(selected) {
	return new Map(
		selected
			.filter((row) => typeof row.year === 'string')
			.map((row) => [
				row.year,
				new Set((row.goals ?? []).map((goal) => goal.competencyId)),
			]),
	)
}

/**
 * Show a goal and every ancestor not shown yet.
 *
 * @param {{tree: object[], shown: Set<string>}} view The tree and the goals shown so far.
 * @param {object} node The goal's tree node.
 * @return {void}
 * @spec openspec/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
function showWithAncestors(view, node) {
	view.shown.add(node.id)
	let parent = node.parentId
	while (parent && !view.shown.has(parent)) {
		view.shown.add(parent)
		parent = view.tree.find((candidate) => candidate.id === parent)?.parentId ?? ''
	}
}

/**
 * The goals the matrix shows: every leaf the total row has an entry for,
 * with its ancestors.
 *
 * @param {object[]} tree The goal tree.
 * @param {Map<string, object>} detail The total row's entries.
 * @return {Set<string>} The goal ids.
 * @spec openspec/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
function shownGoalIds(tree, detail) {
	const view = { tree, shown: new Set() }
	for (const node of tree) {
		if (node.isLeaf && detail.has(node.id)) showWithAncestors(view, node)
	}
	return view.shown
}

/**
 * The text of one cell: empty for a heading and outside the goal's years.
 *
 * @param {{detail: Map<string, object>, inYear: Map<string, Set<string>>, wording: object}} run The matrix being built.
 * @param {{node: object, column: object}} cell The row's goal and the column.
 * @return {string} The text.
 * @spec openspec/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
function cellText(run, cell) {
	if (!cell.node.isLeaf) return ''
	const key = cell.column.key
	const year = key.startsWith('y:') ? key.slice(2) : null
	const applies = year === null || run.inYear.get(year)?.has(cell.node.id)
	return applies ? statusLabel(run.detail.get(cell.node.id), run.wording) : ''
}

/**
 * One matrix row.
 *
 * @param {object} node The goal's tree node.
 * @param {{columns: object[], detail: Map<string, object>, inYear: Map<string, Set<string>>, wording: object}} run The matrix being built.
 * @return {object} The row.
 * @spec openspec/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
function matrixRow(node, run) {
	const row = { id: node.id, label: goalLabel(node), isHeading: !node.isLeaf }
	for (const column of run.columns) {
		row[column.key] = cellText(run, { node, column })
	}
	return row
}

/**
 * The selection's all-years row, null when it has none.
 *
 * @param {object[]} selected The selection's coverage rows.
 * @return {object|null} The total row.
 * @spec openspec/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
function selectionTotal(selected) {
	return selected.find((row) => row.year === null || row.year === undefined) ?? null
}

/**
 * What the rows of the matrix are built from: the total row and its goal
 * entries, the columns, the goals per year and the wording.
 *
 * @param {object} input The coverageMatrix input.
 * @param {object[]} selected The selection's coverage rows.
 * @return {{total: (object|null), columns: object[], detail: Map<string, object>, inYear: Map<string, Set<string>>, wording: object}} The run.
 * @spec openspec/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
function matrixRun(input, selected) {
	const total = selectionTotal(selected)
	return {
		total,
		columns: matrixColumns(yearLabels(selected), input.labels),
		detail: goalDetails(total),
		inYear: yearGoals(selected),
		wording: { labels: input.labels, levelLabels: input.levelLabels },
	}
}

/**
 * The CnDataMatrix columns and rows for one framework and subject selection:
 * goals as rows under their domains, years as columns. A cell is filled only
 * in the years the goal applies to; a domain row is a read-only heading.
 *
 * @param {object} input The input.
 * @param {object[]} input.goals Competency rows of the framework.
 * @param {object[]} input.coverageRows CurriculumCoverage rows of the framework.
 * @param {{subjectScope: string, subjectId: string|null}} input.selection Subject selection.
 * @param {object} input.labels Translated labels (see statusLabel, plus allYears).
 * @param {object} [input.levelLabels] levelId to label.
 * @return {{columns: object[], rows: object[], total: object|null}} Matrix input plus the selection's total row.
 * @spec openspec/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
export function coverageMatrix(input) {
	const selected = input.coverageRows.filter((row) =>
		rowInSelection(row, input.selection),
	)
	const run = matrixRun(input, selected)
	const tree = goalTree(input.goals)
	const shown = shownGoalIds(tree, run.detail)
	const rows = tree
		.filter((node) => shown.has(node.id))
		.map((node) => matrixRow(node, run))
	return { columns: run.columns, rows, total: run.total }
}

/**
 * Whether a coverage row is for one year (not the all-years row).
 *
 * @param {object} row A CurriculumCoverage row.
 * @return {boolean} True for a year row.
 * @spec openspec/specs/competency/spec.md#requirement-a-gap-list-names-the-uncovered-goals-per-subject-and-year
 */
function isYearRow(row) {
	return typeof row.year === 'string' && row.year !== ''
}

/**
 * The goal texts for a list of goal ids; an unknown id shows as itself.
 *
 * @param {string[]|undefined} ids The goal ids.
 * @param {Map<string, string>} names Goal id to text.
 * @return {string[]} The texts.
 * @spec openspec/specs/competency/spec.md#requirement-a-gap-list-names-the-uncovered-goals-per-subject-and-year
 */
function goalTextsOf(ids, names) {
	return (ids ?? []).map((id) => names.get(id) ?? id)
}

/**
 * The subject a gap section names.
 *
 * @param {object} row A CurriculumCoverage row.
 * @param {{subjectNames: object, labels: object}} run The gap list being built.
 * @return {string} The subject's name, its id, or no subject.
 * @spec openspec/specs/competency/spec.md#requirement-a-gap-list-names-the-uncovered-goals-per-subject-and-year
 */
function gapSubject(row, run) {
	if (row.subjectScope === 'none') return run.labels.noSubject
	return run.subjectNames[row.subjectId] ?? row.subjectId ?? run.labels.noSubject
}

/**
 * The key of a gap section.
 *
 * @param {object} row A CurriculumCoverage row.
 * @return {string} `scope|subject|year`.
 * @spec openspec/specs/competency/spec.md#requirement-a-gap-list-names-the-uncovered-goals-per-subject-and-year
 */
function sectionKey(row) {
	return `${row.subjectScope}|${row.subjectId ?? ''}|${row.year ?? ''}`
}

/**
 * The gap section of one coverage row, null when it has no gap.
 *
 * @param {object} row A CurriculumCoverage row.
 * @param {{names: Map<string, string>, subjectNames: object, labels: object}} run The gap list being built.
 * @return {object|null} The section.
 * @spec openspec/specs/competency/spec.md#requirement-a-gap-list-names-the-uncovered-goals-per-subject-and-year
 */
function gapSection(row, run) {
	const notCovered = goalTextsOf(row.uncoveredIds, run.names)
	const plannedNotAssessed = goalTextsOf(row.plannedNotAssessedIds, run.names)
	if (notCovered.length === 0 && plannedNotAssessed.length === 0) return null
	return {
		key: sectionKey(row),
		subject: gapSubject(row, run),
		year: isYearRow(row) ? row.year : run.labels.allYears,
		notCovered,
		plannedNotAssessed,
	}
}

/**
 * Gap sections by subject, then by year in natural order.
 *
 * @param {object} a A section.
 * @param {object} b Another.
 * @return {number} The comparison.
 * @spec openspec/specs/competency/spec.md#requirement-a-gap-list-names-the-uncovered-goals-per-subject-and-year
 */
function sectionOrder(a, b) {
	return (
		a.subject.localeCompare(b.subject)
		|| a.year.localeCompare(b.year, undefined, { numeric: true })
	)
}

/**
 * The gap list: per subject and year, the goals nothing refers to and the
 * goals taught but never tested. Year rows are used when the framework has
 * years, else the all-years rows. Sections with no gap are left out.
 *
 * @param {object} input The input.
 * @param {object[]} input.goals Competency rows of the framework.
 * @param {object[]} input.coverageRows CurriculumCoverage rows of the framework.
 * @param {object} input.subjectNames Course UUID to name.
 * @param {object} input.labels Translated labels: noSubject, allYears.
 * @return {Array<{key: string, subject: string, year: string, notCovered: string[], plannedNotAssessed: string[]}>} The sections.
 * @spec openspec/specs/competency/spec.md#requirement-a-gap-list-names-the-uncovered-goals-per-subject-and-year
 */
export function gapList({ goals, coverageRows, subjectNames, labels }) {
	const names = new Map(goalTree(goals).map((node) => [node.id, goalText(node)]))
	const perSubject = coverageRows.filter(
		(row) => row.subjectScope === 'subject' || row.subjectScope === 'none',
	)
	const hasYears = perSubject.some((row) => isYearRow(row))
	const run = { names, subjectNames, labels }
	const sections = perSubject
		.filter((row) => isYearRow(row) === hasYears)
		.map((row) => gapSection(row, run))
		.filter((section) => section !== null)
	return sections.sort(sectionOrder)
}

/**
 * The subjects with coverage rows, by name.
 *
 * @param {object[]} coverageRows CurriculumCoverage rows of the framework.
 * @param {object} subjectNames Course UUID to name.
 * @return {Array<{id: string, label: string}>} The options.
 * @spec openspec/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
function namedSubjects(coverageRows, subjectNames) {
	const subjects = new Set()
	for (const row of coverageRows) {
		if (row.subjectScope === 'subject' && row.subjectId) subjects.add(row.subjectId)
	}
	const named = [...subjects].map((id) => ({ id, label: subjectNames[id] ?? id }))
	named.sort((a, b) => a.label.localeCompare(b.label))
	return named
}

/**
 * The subject choices for the view's filter: all subjects, each subject
 * that has coverage rows (by name), and no subject when such rows exist.
 *
 * @param {object[]} coverageRows CurriculumCoverage rows of the framework.
 * @param {{subjectNames: object, labels: object}} naming Course UUID to name, and the
 *   translated labels: allSubjects, noSubject.
 * @return {Array<{id: string, label: string}>} The options.
 * @spec openspec/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
export function subjectOptions(coverageRows, naming) {
	const options = [{ id: 'all', label: naming.labels.allSubjects }]
	options.push(...namedSubjects(coverageRows, naming.subjectNames))
	if (coverageRows.some((row) => row.subjectScope === 'none')) {
		options.push({ id: 'none', label: naming.labels.noSubject })
	}
	return options
}

/**
 * The distinct subject ids of a framework's coverage rows, to load names for.
 *
 * @param {object[]} coverageRows CurriculumCoverage rows.
 * @return {string[]} Course UUIDs.
 * @spec openspec/specs/competency/spec.md#requirement-a-gap-list-names-the-uncovered-goals-per-subject-and-year
 */
export function subjectIds(coverageRows) {
	return [
		...new Set(
			coverageRows
				.filter((row) => row.subjectScope === 'subject' && row.subjectId)
				.map((row) => row.subjectId),
		),
	]
}
