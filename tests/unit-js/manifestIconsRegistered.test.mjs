// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Every `icon` the manifests name must be in src/icons.js: CnAppNav and the
// page headers resolve an icon by name through that registry, and a name that
// is not registered renders no icon at all, not a fallback (ADR-077 rule 3).
// ArrowRightCircleOutline (people.json) and AccountMultiple (the course
// participants menu entry) were named but missing.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { test } from 'node:test'

const ROOT = new URL('../../', import.meta.url)

/**
 * The names src/icons.js exports in its default object.
 *
 * @return {Set<string>} The registered icon names.
 */
function registeredIcons () {
	const source = readFileSync(new URL('src/icons.js', ROOT), 'utf8')
	const body = source.slice(source.indexOf('export default {'))
	return new Set([...body.matchAll(/^\t(\w+),$/gm)].map((m) => m[1]))
}

/**
 * Collect every string `icon` value in a manifest tree.
 *
 * @param {*} node A manifest value.
 * @param {Set<string>} out The names found so far.
 * @return {Set<string>} The names found.
 */
function collectIcons (node, out) {
	if (Array.isArray(node)) {
		node.forEach((item) => collectIcons(item, out))
	} else if (node && typeof node === 'object') {
		for (const [key, value] of Object.entries(node)) {
			if (key === 'icon' && typeof value === 'string') {
				out.add(value)
			} else {
				collectIcons(value, out)
			}
		}
	}
	return out
}

const manifests = ['src/manifest.json', ...readdirSync(new URL('src/manifest.d/', ROOT))
	.filter((f) => f.endsWith('.json'))
	.map((f) => `src/manifest.d/${f}`)]

test('every icon a manifest names is registered', () => {
	const registered = registeredIcons()
	const missing = []
	for (const file of manifests) {
		const icons = collectIcons(JSON.parse(readFileSync(new URL(file, ROOT), 'utf8')), new Set())
		for (const icon of icons) {
			if (!registered.has(icon)) {
				missing.push(`${file}: ${icon}`)
			}
		}
	}
	assert.deepEqual(missing, [])
})

test('the two icons that were missing are registered', () => {
	const registered = registeredIcons()
	assert.ok(registered.has('ArrowRightCircleOutline'))
	assert.ok(registered.has('AccountMultiple'))
})
