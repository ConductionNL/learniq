// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// A Rubric as CnChoiceMatrix rows with one pick per row, and the picks back
// as the marking's selected levels.
// @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-28

/**
 * One CnChoiceMatrix row per criterion; its levels are the row's options.
 *
 * @param {object|null} rubric A Rubric with `criteria`.
 * @return {Array<object>} `{ id, label, weight, options }` rows.
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-28
 */
export function rubricRows(rubric) {
	return (rubric?.criteria ?? []).map((criterion) => ({
		id: criterion.criterionId,
		label: criterion.label,
		weight: criterion.weight,
		options: (criterion.levels ?? []).map((level) => ({
			key: level.levelId,
			label: level.label,
			description: level.description,
			points: level.points,
		})),
	}))
}

/**
 * The picks CnChoiceMatrix shows for the selected levels.
 *
 * @param {Record<string, {levelId: string}>} selectedLevels criterionId to selection.
 * @return {Record<string, string>} criterionId to levelId.
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-28
 */
export function rubricPicks(selectedLevels) {
	return Object.fromEntries(
		Object.entries(selectedLevels ?? {}).map(([criterionId, sel]) => [criterionId, sel.levelId]),
	)
}

/**
 * The selected levels, with their points, for the picks of CnChoiceMatrix.
 * A pick of a level the rubric no longer has is dropped.
 *
 * @param {object|null} rubric A Rubric with `criteria`.
 * @param {Record<string, string>} picks criterionId to levelId.
 * @return {Record<string, {levelId: string, points: number}>}
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-28
 */
export function levelsFromPicks(rubric, picks) {
	const selected = {}
	for (const criterion of rubric?.criteria ?? []) {
		const level = (criterion.levels ?? []).find((one) => one.levelId === picks?.[criterion.criterionId])
		if (level) selected[criterion.criterionId] = { levelId: level.levelId, points: level.points }
	}
	return selected
}
