// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Reach a fixture's lifecycle state the way OpenRegister allows it.
 *
 * OpenRegister refuses a create in any state but the lifecycle's initial one
 * (422 `lifecycle-invalid-initial`). A fixture that needs a published lesson
 * or an active group plan is therefore created in the initial state and then
 * walked through the declared transitions.
 */

/**
 * The lifecycle declaration of a schema, by its slug.
 *
 * @param {object} register The parsed learniq_register.json.
 * @param {string} slug The schema slug.
 * @return {object|null} The `x-openregister-lifecycle` block, or null.
 */
export function lifecycleOf(register, slug) {
	const schemas = register?.components?.schemas ?? {}
	for (const schema of Object.values(schemas)) {
		if (schema?.slug === slug) {
			return schema['x-openregister-lifecycle'] ?? null
		}
	}
	return null
}

/**
 * The shortest list of transition names from one state to another.
 *
 * @param {object|null} lifecycle A lifecycle declaration.
 * @param {string} from The current state.
 * @param {string} to The wanted state.
 * @return {string[]|null} The actions in order, [] when already there, null when unreachable.
 */
export function lifecyclePath(lifecycle, from, to) {
	if (from === to) {
		return []
	}
	const transitions = Object.entries(lifecycle?.transitions ?? {})
	const queue = [[from, []]]
	const seen = new Set([from])
	while (queue.length > 0) {
		const [state, path] = queue.shift()
		for (const [action, t] of transitions) {
			const sources = Array.isArray(t?.from) ? t.from : [t?.from]
			if (!sources.includes(state) || typeof t?.to !== 'string' || seen.has(t.to)) {
				continue
			}
			const next = [...path, action]
			if (t.to === to) {
				return next
			}
			seen.add(t.to)
			queue.push([t.to, next])
		}
	}
	return null
}
