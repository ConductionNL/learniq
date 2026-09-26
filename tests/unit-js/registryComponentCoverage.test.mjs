// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// registry-component-fix: 14 type:"custom" manifest pages named a shared
// @conduction/nextcloud-vue component (CnDataMatrix, CnWizardDialog,
// CnRichSubmitDialog, CnExportWizard, CnSignatureCapture, CnTimelineView,
// CnStructuredDocReview, CnRelationshipGraph) by its exact export string, but
// src/registry.js never registered any of the eight — CnPageRenderer's
// resolveCustomComponent() has no fallback onto the library's own export
// catalogue, so every one of those 14 routes mounted an empty page body
// (learniq-defect-triage.md, entry 1; verified live in the browser for
// CohortTimetable, which rendered "This page is empty").
//
// This test reads src/registry.js as plain text rather than importing it:
// the module imports .vue single-file components, which plain `node --test`
// (no Vue compiler in this test lane) cannot load. Every kind:"page" entry
// in this file follows one literal shape, `Name: page(Name),` — the same
// static-text-match technique tests/unit-js/connectionRegistry.test.mjs
// already uses to check icons.js. A key extracted this way is exactly what
// CnPageRenderer looks up at runtime (the `registry` prop's own keys), so
// the check is faithful to the real resolution path, not an approximation of it.
//
// @spec openspec/changes/registry-component-fix/specs/component-registry/spec.md#requirement-every-type-custom-manifest-pages-component-must-be-registered
// @spec openspec/changes/registry-component-fix/specs/component-registry/spec.md#requirement-a-regression-test-must-fail-when-a-custom-page-names-an-unregistered-component

import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { describe, test } from 'node:test'
import { fileURLToPath } from 'node:url'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..')
const readJson = (relative) => JSON.parse(fs.readFileSync(path.join(ROOT, relative), 'utf8'))

/**
 * Extract the set of `kind: "page"` registry keys from a registry.js-shaped
 * source string. Every page entry in this file is `Name: page(Name),` (or
 * `'kebab-name': page(Name),` for the rare quoted key) — the `page()` helper
 * is the sole producer of `kind: "page"` entries in this file.
 *
 * @param {string} source The registry.js file contents.
 * @return {Set<string>} The registered page keys.
 */
function extractPageKeys(source) {
	const keys = new Set()
	const re = /^\s*(?:'([^']+)'|"([^"]+)"|([A-Za-z0-9_]+))\s*:\s*page\(/gm
	let match
	while ((match = re.exec(source)) !== null) {
		keys.add(match[1] ?? match[2] ?? match[3])
	}
	return keys
}

/**
 * Collect every `type: "custom"` page declared across the manifest and its
 * fragments, the same set CnAppRoot assembles at runtime.
 *
 * @return {Array<{id: string, component: string, source: string}>}
 */
function collectCustomPages() {
	const docs = [{ source: 'src/manifest.json', doc: readJson('src/manifest.json') }]
	for (const name of fs.readdirSync(path.join(ROOT, 'src/manifest.d')).filter((n) => n.endsWith('.json'))) {
		docs.push({ source: `src/manifest.d/${name}`, doc: readJson(`src/manifest.d/${name}`) })
	}
	return docs.flatMap(({ source, doc }) =>
		(doc.pages ?? [])
			.filter((p) => p.type === 'custom')
			.map((p) => ({ id: p.id, component: p.component, source })),
	)
}

describe('registry component coverage', () => {
	test('every type:"custom" page names a component registered as kind:"page"', () => {
		const registrySource = fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')
		const registeredKeys = extractPageKeys(registrySource)
		const customPages = collectCustomPages()

		assert.ok(customPages.length > 0, 'expected at least one type:"custom" manifest page to check against')

		const missing = customPages.filter((p) => !registeredKeys.has(p.component))
		assert.deepEqual(
			missing.map((p) => `${p.id} (${p.source}) -> ${p.component}`),
			[],
			'every type:"custom" page component must be registered as kind:"page" in src/registry.js',
		)
	})

	test('the eight previously-unregistered shared components are now registered', () => {
		const registrySource = fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')
		const registeredKeys = extractPageKeys(registrySource)
		const expected = [
			'CnDataMatrix',
			'CnWizardDialog',
			'CnRichSubmitDialog',
			'CnExportWizard',
			'CnSignatureCapture',
			'CnTimelineView',
			'CnStructuredDocReview',
			'CnRelationshipGraph',
		]
		for (const name of expected) {
			assert.ok(registeredKeys.has(name), `${name} must be registered as kind:"page"`)
			assert.match(
				registrySource,
				new RegExp(`import\\s*\\{[^}]*\\b${name}\\b[^}]*\\}\\s*from\\s*'@conduction/nextcloud-vue'`, 's'),
				`${name} must be imported from @conduction/nextcloud-vue`,
			)
		}
	})

	test('a fixture registry missing one component is caught by the same check used above', () => {
		// This does not mutate the real registry; it proves the extraction +
		// diff logic itself would fail loudly on the exact defect class found
		// in learniq-defect-triage.md entry 1, rather than only ever matching
		// against a registry.js that already has the fix applied.
		const fixtureSource = `
			import { CnDataMatrix } from '@conduction/nextcloud-vue'
			export default {
				CnDataMatrix: page(CnDataMatrix),
			}
		`
		const fixtureCustomPages = [
			{ id: 'GradebookView', component: 'CnDataMatrix', source: 'fixture' },
			{ id: 'CohortTimetable', component: 'CnTimelineView', source: 'fixture' },
		]
		const registeredKeys = extractPageKeys(fixtureSource)
		const missing = fixtureCustomPages.filter((p) => !registeredKeys.has(p.component))

		assert.deepEqual(
			missing.map((p) => p.component),
			['CnTimelineView'],
			'the fixture must reproduce a caught miss for the unregistered component',
		)
	})
})
