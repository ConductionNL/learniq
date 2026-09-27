// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// learniq#947: fourteen custom pages opened as "This page is empty" because
// their manifest `component` named a library component (CnDataMatrix,
// CnWizardDialog, ...) that src/registry.js never registered. CnPageRenderer
// resolves a custom page's component ONLY against the app registry and, when
// the name is missing, logs a console warning and renders the empty state.
// Nothing failed. This test fails instead: every custom page's component (or
// its `slots.main`, the other authoring style CnPageRenderer accepts) must be
// a `page(...)` entry in the registry.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const read = (p) => readFileSync(resolve(root, p), 'utf8')

/**
 * Every manifest file the app bundles: the base manifest and its fragments.
 *
 * @return {Array<{file: string, manifest: object}>} Parsed manifests.
 */
function manifests() {
	const fragments = readdirSync(resolve(root, 'src/manifest.d'))
		.filter((f) => f.endsWith('.json'))
		.map((f) => `src/manifest.d/${f}`)
	return ['src/manifest.json', ...fragments].map((file) => ({
		file,
		manifest: JSON.parse(read(file)),
	}))
}

/**
 * The names registered as `kind: "page"` entries in src/registry.js.
 *
 * @return {Set<string>} Registered page component names.
 */
function registeredPages() {
	const source = read('src/registry.js')
	return new Set([...source.matchAll(/^\s*(\w+):\s*page\(/gm)].map((m) => m[1]))
}

test('the registry parser sees the registry (positive control)', () => {
	const pages = registeredPages()
	assert.ok(pages.size > 40, `only ${pages.size} page entries parsed`)
	assert.ok(pages.has('TakeAssessmentView'))
})

test('every custom page names a component the registry has', () => {
	const pages = registeredPages()
	const missing = []
	let checked = 0
	for (const { file, manifest } of manifests()) {
		for (const page of manifest.pages ?? []) {
			if (page.type !== 'custom') continue
			const name = page.component || page.slots?.main
			checked++
			if (!name || !pages.has(name)) {
				missing.push(`${file} ${page.id} -> ${name ?? '(none)'}`)
			}
		}
	}
	assert.ok(checked > 40, `only ${checked} custom pages found; is the manifest read?`)
	assert.deepEqual(missing, [], 'these custom pages would render "This page is empty"')
})
