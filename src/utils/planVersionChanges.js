// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// What changed between a learning plan and the version it replaces: the
// goals and support measures that were added, removed or changed, matched on
// their stable ids (goalId, measureId). Anything the two versions share
// unchanged is left out. Pure, tested in tests/unit-js/planVersionChanges.test.mjs.

/** The two lists compared, with the id that matches an item across versions. */
const LISTS = [
	{ list: 'goals', key: 'goalId', kind: 'goal' },
	{ list: 'supportMeasures', key: 'measureId', kind: 'measure' },
]

/**
 * A value with its object keys sorted, so key order never counts as a change.
 *
 * @param {unknown} value Any JSON value.
 * @return {unknown} The same value with sorted keys.
 */
function canonical(value) {
	if (Array.isArray(value)) {
		return value.map(canonical)
	}

	if (value && typeof value === 'object') {
		return Object.keys(value).sort().reduce((out, key) => {
			out[key] = canonical(value[key])
			return out
		}, {})
	}

	return value
}

/**
 * The items of one list keyed by their id; an item without an id is skipped,
 * since nothing can tell which earlier item it is.
 *
 * @param {object|null} plan The plan.
 * @param {string} list `goals` or `supportMeasures`.
 * @param {string} key `goalId` or `measureId`.
 * @return {Map<string, object>} The items by id, in their order.
 */
function byId(plan, list, key) {
	const items = Array.isArray(plan?.[list]) ? plan[list] : []
	const out = new Map()
	for (const item of items) {
		const id = item && typeof item === 'object' ? String(item[key] ?? '') : ''
		if (id !== '' && !out.has(id)) {
			out.set(id, item)
		}
	}

	return out
}

/**
 * The goals and support measures that differ between a plan and the version
 * it replaces.
 *
 * Each change carries its `kind` (`goal` or `measure`), its `change`
 * (`added`, `removed` or `changed`), its id and description, and for a change
 * the earlier description. Added and changed items come in the current
 * version's order, removed ones after them in the earlier version's order.
 *
 * @param {object|null} current The plan being read.
 * @param {object|null} previous The version it replaces.
 * @return {Array<{kind: string, change: string, id: string, description: string, before?: string}>} The changes.
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/learning-plan/spec.md#scenario-a-coordinator-sees-what-changed-between-two-versions
 */
export function planVersionChanges(current, previous) {
	const changes = []
	for (const { list, key, kind } of LISTS) {
		const now = byId(current, list, key)
		const before = byId(previous, list, key)

		for (const [id, item] of now) {
			const description = String(item.description ?? '')
			if (!before.has(id)) {
				changes.push({ kind, change: 'added', id, description })
				continue
			}

			const earlier = before.get(id)
			if (JSON.stringify(canonical(item)) !== JSON.stringify(canonical(earlier))) {
				changes.push({ kind, change: 'changed', id, description, before: String(earlier.description ?? '') })
			}
		}

		for (const [id, item] of before) {
			if (!now.has(id)) {
				changes.push({ kind, change: 'removed', id, description: String(item.description ?? '') })
			}
		}
	}

	return changes
}
