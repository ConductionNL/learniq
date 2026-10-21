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
// @spec openspec/specs/component-registry/spec.md#requirement-every-type-custom-manifest-pages-component-must-be-registered
// @spec openspec/specs/component-registry/spec.md#requirement-a-regression-test-must-fail-when-a-custom-page-names-an-unregistered-component

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
			// A page that draws library widgets in its body names no component.
			.filter((p) => p.component !== undefined || !(p.widgets ?? []).some((w) => w.slot === 'body'))
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

	test('no registry key shadows a library component or widget that no manifest names', () => {
		// The eight shared components were registered for pages that now mount
		// thin learniq views, and `audit-trail` shadowed nc-vue's own built-in
		// widget of that name (the consumer registry wins over the catalogue).
		const registrySource = fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')
		const registeredKeys = extractPageKeys(registrySource)
		const retired = [
			'CnDataMatrix',
			'CnWizardDialog',
			'CnRichSubmitDialog',
			'CnExportWizard',
			'CnSignatureCapture',
			'CnTimelineView',
			'CnStructuredDocReview',
			'CnRelationshipGraph',
		]
		const named = new Set(collectCustomPages().map((p) => p.component))
		for (const name of retired) {
			assert.equal(named.has(name), false, `${name} is named by a custom page again: register it, or point the page at a view`)
			assert.equal(registeredKeys.has(name), false, `${name} must not be registered: no page names it`)
		}
		assert.doesNotMatch(registrySource, /['"]audit-trail['"]\s*:/, 'the audit-trail key would shadow nc-vue\'s built-in widget')
		assert.doesNotMatch(registrySource, /from '@conduction\/nextcloud-vue'/, 'the registry imports no library component directly')
	})

	test('a fixture registry missing one component is caught by the same check used above', () => {
		// This does not mutate the real registry; it proves the extraction +
		// diff logic itself would fail loudly on the exact defect class found
		// in learniq-defect-triage.md entry 1, rather than only ever matching
		// against a registry.js that already has the fix applied.
		const fixtureSource = `
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

describe('body section coverage', () => {
	// assignment-missing-submissions-view: a `config.bodyWidgets` entry names a
	// registry component the same way a custom page does, and CnBodySections
	// renders an inline error, not the section, when the name is missing.
	test('every bodyWidgets component is registered with kind:"section"', () => {
		const registrySource = fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')
		const docs = [readJson('src/manifest.json')]
		for (const name of fs.readdirSync(path.join(ROOT, 'src/manifest.d')).filter((n) => n.endsWith('.json'))) {
			docs.push(readJson(`src/manifest.d/${name}`))
		}
		const sections = docs.flatMap((doc) =>
			(doc.pages ?? []).flatMap((p) => (p.config?.bodyWidgets ?? []).map((w) => ({ page: p.id, component: w.component }))),
		)
		const isSection = (name) => new RegExp(`^\\s*${name}:\\s*\\{\\s*\\n\\s*kind:\\s*'section'`, 'm').test(registrySource)

		assert.ok(sections.length > 0, 'expected at least one bodyWidgets entry to check')
		assert.equal(isSection('NotARegisteredSection'), false, 'the check must be able to fail')
		assert.deepEqual(
			sections.filter((s) => !isSection(s.component)).map((s) => `${s.page} -> ${s.component}`),
			[],
			'every bodyWidgets component must be registered as kind:"section" in src/registry.js',
		)
	})
})
