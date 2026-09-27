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
 * @spec openspec/changes/curriculum-coverage-matrix-view/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
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
 * @spec openspec/changes/curriculum-coverage-matrix-view/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
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
 * @spec openspec/changes/curriculum-coverage-matrix-view/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
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
 * The active goals in tree order (depth first, siblings by order then
 * code), each with its depth and whether it is a leaf.
 *
 * @param {object[]} goals Competency rows of one framework.
 * @return {Array<{id: string, code: string, title: string, depth: number, isLeaf: boolean, parentId: string}>} The ordered nodes.
 * @spec openspec/changes/curriculum-coverage-matrix-view/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
export function goalTree(goals) {
	const nodes = new Map()
	for (const goal of goals) {
		const id = String(goal?.id ?? goal?.uuid ?? '')
		if (id && !INACTIVE.includes(goal.lifecycle)) nodes.set(id, goal)
	}
	const sibling = (a, b) => {
		const byOrder =
			(a.order ?? Number.MAX_SAFE_INTEGER)
			- (b.order ?? Number.MAX_SAFE_INTEGER)
		if (byOrder !== 0) return byOrder
		return String(a.code ?? '').localeCompare(String(b.code ?? ''), undefined, {
			numeric: true,
		})
	}
	const children = new Map()
	const roots = []
	for (const [id, goal] of [...nodes.entries()].sort((a, b) =>
		sibling(a[1], b[1]),
	)) {
		const parent = String(goal.parentId ?? '')
		if (parent && parent !== id && nodes.has(parent)) {
			if (!children.has(parent)) children.set(parent, [])
			children.get(parent).push(id)
		} else {
			roots.push(id)
		}
	}
	const ordered = []
	const seen = new Set()
	const visit = (id, depth) => {
		if (seen.has(id)) return
		seen.add(id)
		const goal = nodes.get(id)
		const kids = children.get(id) ?? []
		ordered.push({
			id,
			code: String(goal.code ?? ''),
			title: String(goal.title ?? ''),
			depth,
			isLeaf: kids.length === 0,
			parentId: String(goal.parentId ?? ''),
		})
		for (const kid of kids) visit(kid, depth + 1)
	}
	for (const root of roots) visit(root, 0)
	return ordered
}

/**
 * A goal's display label: its code and title, indented by tree depth with
 * non-breaking spaces so the matrix shows the tree.
 *
 * @param {{code: string, title: string, depth: number}} node A tree node.
 * @return {string} The label.
 * @spec openspec/changes/curriculum-coverage-matrix-view/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
export function goalLabel(node) {
	const text = [node.code, node.title].filter(Boolean).join(' · ')
	return '    '.repeat(node.depth) + text
}

/**
 * The words a cell shows for one goal: planned, assessed, both or neither,
 * with the deepest depth's label when one is known. Words, not colour, so the
 * status reads the same for everyone.
 *
 * @param {object} detail The goal's entry from a coverage row's `goals`.
 * @param {object} labels Translated labels: plannedAssessed, planned, assessed, notCovered.
 * @param {object} [levelLabels] levelId to label, from the framework.
 * @return {string} The cell text.
 * @spec openspec/changes/curriculum-coverage-matrix-view/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
export function statusLabel(detail, labels, levelLabels = {}) {
	let text = labels.notCovered
	if (detail?.planned && detail?.assessed) text = labels.plannedAssessed
	else if (detail?.planned) text = labels.planned
	else if (detail?.assessed) text = labels.assessed
	const depth = detail?.plannedDepth ?? detail?.assessedDepth ?? null
	if (depth === null || (!detail?.planned && !detail?.assessed)) return text
	return `${text} (${levelLabels[depth] ?? depth})`
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
 * @spec openspec/changes/curriculum-coverage-matrix-view/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
export function coverageMatrix({
	goals,
	coverageRows,
	selection,
	labels,
	levelLabels = {},
}) {
	const selected = coverageRows.filter((row) => rowInSelection(row, selection))
	const total =
		selected.find((row) => row.year === null || row.year === undefined) ?? null
	const years = yearLabels(selected)
	const columns =
		years.length > 0
			? years.map((year) => ({
					key: `y:${year}`,
					label: year,
					type: 'string',
					readOnly: true,
				}))
			: [
					{
						key: 'all',
						label: labels.allYears,
						type: 'string',
						readOnly: true,
					},
				]
	const detail = new Map(
		(total?.goals ?? []).map((goal) => [goal.competencyId, goal]),
	)
	const inYear = new Map(
		selected
			.filter((row) => typeof row.year === 'string')
			.map((row) => [
				row.year,
				new Set((row.goals ?? []).map((goal) => goal.competencyId)),
			]),
	)
	const tree = goalTree(goals)
	const shown = new Set()
	for (const node of tree) {
		if (!node.isLeaf || !detail.has(node.id)) continue
		shown.add(node.id)
		let parent = node.parentId
		while (parent && !shown.has(parent)) {
			shown.add(parent)
			parent =
				tree.find((candidate) => candidate.id === parent)?.parentId ?? ''
		}
	}
	const rows = []
	for (const node of tree) {
		if (!shown.has(node.id)) continue
		const row = { id: node.id, label: goalLabel(node), isHeading: !node.isLeaf }
		for (const column of columns) {
			if (!node.isLeaf) {
				row[column.key] = ''
				continue
			}
			const year = column.key.startsWith('y:') ? column.key.slice(2) : null
			const applies = year === null || inYear.get(year)?.has(node.id)
			row[column.key] = applies
				? statusLabel(detail.get(node.id), labels, levelLabels)
				: ''
		}
		rows.push(row)
	}
	return { columns, rows, total }
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
 * @spec openspec/changes/curriculum-coverage-matrix-view/specs/competency/spec.md#requirement-a-gap-list-names-the-uncovered-goals-per-subject-and-year
 */
export function gapList({ goals, coverageRows, subjectNames, labels }) {
	const names = new Map(
		goalTree(goals).map((node) => [
			node.id,
			[node.code, node.title].filter(Boolean).join(' · '),
		]),
	)
	const perSubject = coverageRows.filter(
		(row) => row.subjectScope === 'subject' || row.subjectScope === 'none',
	)
	const hasYears = perSubject.some(
		(row) => typeof row.year === 'string' && row.year !== '',
	)
	const sections = []
	for (const row of perSubject) {
		const isYearRow = typeof row.year === 'string' && row.year !== ''
		if (isYearRow !== hasYears) continue
		const notCovered = (row.uncoveredIds ?? []).map((id) => names.get(id) ?? id)
		const plannedNotAssessed = (row.plannedNotAssessedIds ?? []).map(
			(id) => names.get(id) ?? id,
		)
		if (notCovered.length === 0 && plannedNotAssessed.length === 0) continue
		const subject =
			row.subjectScope === 'none'
				? labels.noSubject
				: (subjectNames[row.subjectId] ?? row.subjectId ?? labels.noSubject)
		sections.push({
			key: `${row.subjectScope}|${row.subjectId ?? ''}|${row.year ?? ''}`,
			subject,
			year: isYearRow ? row.year : labels.allYears,
			notCovered,
			plannedNotAssessed,
		})
	}
	return sections.sort(
		(a, b) =>
			a.subject.localeCompare(b.subject)
			|| a.year.localeCompare(b.year, undefined, { numeric: true }),
	)
}

/**
 * The subject choices for the view's filter: all subjects, each subject
 * that has coverage rows (by name), and no subject when such rows exist.
 *
 * @param {object[]} coverageRows CurriculumCoverage rows of the framework.
 * @param {object} subjectNames Course UUID to name.
 * @param {object} labels Translated labels: allSubjects, noSubject.
 * @return {Array<{id: string, label: string}>} The options.
 * @spec openspec/changes/curriculum-coverage-matrix-view/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
 */
export function subjectOptions(coverageRows, subjectNames, labels) {
	const subjects = new Set()
	let hasNone = false
	for (const row of coverageRows) {
		if (row.subjectScope === 'subject' && row.subjectId)
			subjects.add(row.subjectId)
		if (row.subjectScope === 'none') hasNone = true
	}
	const options = [{ id: 'all', label: labels.allSubjects }]
	const named = [...subjects].map((id) => ({ id, label: subjectNames[id] ?? id }))
	named.sort((a, b) => a.label.localeCompare(b.label))
	options.push(...named)
	if (hasNone) options.push({ id: 'none', label: labels.noSubject })
	return options
}

/**
 * The distinct subject ids of a framework's coverage rows, to load names for.
 *
 * @param {object[]} coverageRows CurriculumCoverage rows.
 * @return {string[]} Course UUIDs.
 * @spec openspec/changes/curriculum-coverage-matrix-view/specs/competency/spec.md#requirement-a-gap-list-names-the-uncovered-goals-per-subject-and-year
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
