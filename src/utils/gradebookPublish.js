// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The publish panel of the cohort gradebook: which marks a publish would
 * cover, how they are spread, and what the batch did. Pure functions, pinned
 * by node tests.
 *
 * @spec openspec/specs/grading/spec.md#requirement-a-teacher-previews-and-batch-publishes-a-cohorts-concept-grades
 */

/** Scope value meaning every component of the plan. */
export const ALL_COMPONENTS = '*'

/**
 * Whether a GradeEntry carries a numeric mark.
 *
 * @param {object} entry A GradeEntry.
 * @return {boolean}
 * @spec openspec/specs/grading/spec.md#requirement-a-teacher-previews-and-batch-publishes-a-cohorts-concept-grades
 */
function hasMark(entry) {
	return (
		entry?.value !== null
		&& entry?.value !== undefined
		&& entry?.value !== ''
		&& Number.isFinite(Number(entry.value))
	)
}

/**
 * The live entries in scope: one component, or all of them. Invalidated and
 * revised entries are history, not marks.
 *
 * @param {object[]} entries GradeEntries of the cohort and plan.
 * @param {string} componentId A component id, or ALL_COMPONENTS.
 * @return {object[]}
 * @spec openspec/specs/grading/spec.md#requirement-a-teacher-previews-and-batch-publishes-a-cohorts-concept-grades
 */
export function scopeEntries(entries, componentId) {
	return (entries ?? []).filter(
		(e) =>
			e?.lifecycle !== 'invalidated'
			&& e?.lifecycle !== 'revised'
			&& (componentId === ALL_COMPONENTS || e?.componentId === componentId),
	)
}

/**
 * The concept entries in scope that a publish would send: those with a mark.
 *
 * @param {object[]} scoped Output of scopeEntries().
 * @return {object[]}
 * @spec openspec/specs/grading/spec.md#requirement-a-teacher-previews-and-batch-publishes-a-cohorts-concept-grades
 */
export function publishable(scoped) {
	return (scoped ?? []).filter((e) => e?.lifecycle === 'concept' && hasMark(e))
}

/**
 * One band per whole point from the floor of `low` up to `high`.
 *
 * @param {number} low Lowest value of the range.
 * @param {number} high Highest value of the range.
 * @return {Array<{from: number, to: number}>}
 */
function wholePointBands(low, high) {
	const bands = []
	for (let from = Math.floor(low); from < high; from++) {
		bands.push({ from, to: Math.min(from + 1, high) })
	}
	return bands
}

/**
 * Five equal bands from `low` to `high`; the last one ends exactly on `high`.
 *
 * @param {number} low Lowest value of the range.
 * @param {number} high Highest value of the range.
 * @return {Array<{from: number, to: number}>}
 */
function fiveEqualBands(low, high) {
	const width = (high - low) / 5
	return Array.from({ length: 5 }, (_, i) => ({
		from: low + i * width,
		to: i === 4 ? high : low + (i + 1) * width,
	}))
}

/**
 * Whether two finite bounds form a range, lowest first.
 *
 * @param {number} low Lowest value of the range.
 * @param {number} high Highest value of the range.
 * @return {boolean}
 */
function isOrderedRange(low, high) {
	return Number.isFinite(low) && Number.isFinite(high) && high >= low
}

/**
 * Histogram bands for a scale: one per whole point when the scale spans ten
 * points or fewer (the Dutch 1 to 10), else five equal bands.
 *
 * @param {number} low Lowest value of the range.
 * @param {number} high Highest value of the range.
 * @return {Array<{from: number, to: number}>} Half-open bands; the last one is closed.
 * @spec openspec/specs/grading/spec.md#requirement-a-teacher-previews-and-batch-publishes-a-cohorts-concept-grades
 */
export function bandsFor(low, high) {
	if (!isOrderedRange(low, high)) return []
	const span = high - low
	if (span === 0) return [{ from: low, to: high }]
	if (span <= 10) return wholePointBands(low, high)
	return fiveEqualBands(low, high)
}

/**
 * The spread of an empty scope.
 *
 * @return {{count: number, average: null, lowest: null, highest: null, passing: null, bands: Array}}
 */
function emptyDistribution() {
	return {
		count: 0,
		average: null,
		lowest: null,
		highest: null,
		passing: null,
		bands: [],
	}
}

/**
 * A scale bound when it is set and numeric, else the given fallback.
 *
 * @param {number|string|null|undefined} bound The scale's `min` or `max`.
 * @param {number} fallback The lowest or highest mark.
 * @return {number}
 */
function scaleBound(bound, fallback) {
	return Number.isFinite(Number(bound)) && bound !== null
		? Number(bound)
		: fallback
}

/**
 * Each band with the number of marks in it; the last band is closed.
 *
 * @param {number[]} values The marks.
 * @param {Array<{from: number, to: number}>} bands Output of bandsFor().
 * @return {Array<{from: number, to: number, count: number}>}
 */
function countBands(values, bands) {
	return bands.map((band, i, all) => ({
		...band,
		count: values.filter((v) =>
			i === all.length - 1
				? v >= band.from && v <= band.to
				: v >= band.from && v < band.to,
		).length,
	}))
}

/**
 * The counted bands over the scale, widened to the marks outside it.
 *
 * @param {number[]} values The marks; at least one.
 * @param {object|null} scale The plan's GradeScale.
 * @return {Array<{from: number, to: number, count: number}>}
 */
function bandsOver(values, scale) {
	const lowest = Math.min(...values)
	const highest = Math.max(...values)
	const low = scaleBound(scale?.min, lowest)
	const high = scaleBound(scale?.max, highest)
	return countBands(values, bandsFor(Math.min(low, lowest), Math.max(high, highest)))
}

/**
 * How many marks reach the pass threshold, or null without one.
 *
 * @param {number[]} values The marks.
 * @param {object|null} scale The plan's GradeScale.
 * @return {number|null}
 */
function passingCount(values, scale) {
	const threshold = Number(scale?.passThreshold)
	const hasThreshold =
		scale?.passThreshold !== null
		&& scale?.passThreshold !== undefined
		&& Number.isFinite(threshold)
	return hasThreshold ? values.filter((v) => v >= threshold).length : null
}

/**
 * The mean of the marks, rounded to two decimals.
 *
 * @param {number[]} values The marks; at least one.
 * @return {number}
 */
function roundedAverage(values) {
	const average = values.reduce((sum, v) => sum + v, 0) / values.length
	return Math.round(average * 100) / 100
}

/**
 * How the marks in scope are spread. Concept and published marks both count:
 * the teacher previews the whole column, not only what is still to send.
 *
 * @param {object[]} scoped Output of scopeEntries().
 * @param {object|null} scale The plan's GradeScale (`min`, `max`, `passThreshold`).
 * @return {{count: number, average: number|null, lowest: number|null, highest: number|null, passing: number|null, bands: Array<{from: number, to: number, count: number}>}}
 * @spec openspec/specs/grading/spec.md#requirement-a-teacher-previews-and-batch-publishes-a-cohorts-concept-grades
 */
export function distribution(scoped, scale = null) {
	const values = (scoped ?? []).filter(hasMark).map((e) => Number(e.value))
	if (values.length === 0) return emptyDistribution()
	return {
		count: values.length,
		average: roundedAverage(values),
		lowest: Math.min(...values),
		highest: Math.max(...values),
		passing: passingCount(values, scale),
		bands: bandsOver(values, scale),
	}
}

/**
 * What a batch did, from one outcome per sent entry.
 *
 * @param {Array<{entry: object, ok: boolean, reason?: string}>} outcomes One per entry, in send order.
 * @return {{published: number, refused: Array<{learnerId: string, reason: string}>}}
 * @spec openspec/specs/grading/spec.md#requirement-a-teacher-previews-and-batch-publishes-a-cohorts-concept-grades
 */
export function publishReport(outcomes) {
	const list = outcomes ?? []
	return {
		published: list.filter((o) => o.ok).length,
		refused: list
			.filter((o) => !o.ok)
			.map((o) => ({
				learnerId: o.entry?.learnerId ?? '',
				reason: o.reason ?? '',
			})),
	}
}
