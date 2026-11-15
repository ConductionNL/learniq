// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * What one more mark would do to a learner's average: a forecast computed in
 * the browser from the published grades already on the page. Nothing is
 * saved; the caller only renders the result.
 *
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/grading/spec.md#requirement-a-learner-sees-what-one-more-mark-would-do-to-their-average
 */

/** The pass mark of the default Dutch 1-10 scale, used when the plan sets none. */
export const DEFAULT_PASS_MARK = 5.5

/**
 * A finite number from a value, or null.
 *
 * @param {number|string|null|undefined} value The value, a number or a numeric string (comma decimals allowed).
 * @return {number|null}
 */
export function toNumber(value) {
	if (value === null || value === undefined || value === '') {
		return null
	}

	const number = typeof value === 'number' ? value : Number(String(value).trim().replace(',', '.'))
	return Number.isFinite(number) ? number : null
}

/**
 * The weight a grade counts with: its own weight, else its plan component's, else 1.
 *
 * @param {object} entry A GradeEntry.
 * @param {object|null} plan The CurriculumPlan, or null.
 * @return {number}
 */
export function entryWeight(entry, plan) {
	const own = toNumber(entry?.weight)
	if (own !== null) {
		return own
	}

	const component = (plan?.components ?? []).find((c) => c.componentId === entry?.componentId)
	const planWeight = toNumber(component?.weight)
	return planWeight !== null ? planWeight : 1
}

/**
 * The plan's pass mark for the final grade (a pass rule without componentId), else the default.
 *
 * @param {object|null} plan The CurriculumPlan, or null.
 * @return {number}
 */
export function passMark(plan) {
	const rule = (plan?.passRules ?? []).find((r) => r && (r.componentId === null || r.componentId === undefined || r.componentId === ''))
	const minValue = toNumber(rule?.minValue)
	return minValue !== null ? minValue : DEFAULT_PASS_MARK
}

/**
 * The weighted average of grades, rounded to two decimals, or null without weight.
 *
 * @param {Array<{value: number, weight: number}>} marks The marks with their weights.
 * @return {number|null}
 */
function weightedAverage(marks) {
	let sum = 0
	let total = 0
	for (const { value, weight } of marks) {
		sum += value * weight
		total += weight
	}

	return total > 0 ? Math.round((sum / total) * 100) / 100 : null
}

/**
 * The average now and the average with one more mark, and whether that passes.
 *
 * @param {object} input The forecast input.
 * @param {Array<object>} input.entries The learner's published GradeEntries.
 * @param {object|null} input.plan The CurriculumPlan, or null.
 * @param {number|string} input.mark The possible next mark.
 * @param {number|string} input.weight Its weight.
 * @return {{current: number|null, average: number, passMark: number, passes: boolean}|null} Null while the mark or weight is not a usable number.
 */
export function forecastAverage({ entries, plan, mark, weight }) {
	const nextMark = toNumber(mark)
	const nextWeight = toNumber(weight)
	if (nextMark === null || nextWeight === null || nextWeight <= 0) {
		return null
	}

	const marks = []
	for (const entry of entries ?? []) {
		const value = toNumber(entry?.value)
		if (value !== null) {
			marks.push({ value, weight: entryWeight(entry, plan) })
		}
	}

	const current = weightedAverage(marks)
	const average = weightedAverage([...marks, { value: nextMark, weight: nextWeight }])
	const threshold = passMark(plan)

	return { current, average, passMark: threshold, passes: average >= threshold }
}
