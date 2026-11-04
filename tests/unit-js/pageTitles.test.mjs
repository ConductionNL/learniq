// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Every page names itself with one level-1 heading, as the drawn boards do:
// the app runs the board look, so nextcloud-vue's index, detail and dashboard
// pages render their title as an <h1>, and a custom page opens with
// CnPageHeader instead of a bare <h2>.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const readJson = (path) => JSON.parse(readFileSync(resolve(root, path), 'utf8'))

const manifest = readJson('src/manifest.json')
const pages = [...manifest.pages]
for (const file of readdirSync(resolve(root, 'src/manifest.d')).filter((f) => f.endsWith('.json'))) {
	pages.push(...(readJson(`src/manifest.d/${file}`).pages ?? []))
}

/**
 * The first heading a component's template draws: a heading element or a
 * nextcloud-vue page component that draws the page title itself.
 *
 * @param {string} component Component name under src/views.
 * @return {string|null} Tag name, or null when the template has no heading.
 */
function firstHeading(component) {
	const source = readFileSync(resolve(root, `src/views/${component}.vue`), 'utf8')
	const template = source.slice(0, source.indexOf('\n<script'))
	const match = template.match(/<(h[1-6]|CnPageHeader|CnDashboardPage|CnDetailPage|CnIndexPage)\b/)
	return match ? match[1] : null
}

test('the app runs the board look', () => {
	assert.equal(manifest.look, 'board')
})

test('no custom page names itself with an <h2>', () => {
	const components = [...new Set(pages.filter((p) => p.type === 'custom').map((p) => p.component))]
	assert.ok(components.length > 50, 'the custom pages are found')
	const offenders = components.filter((c) => firstHeading(c) === 'h2')
	assert.deepEqual(offenders, [])
})

test('an index page shows its title, except where its board draws none', () => {
	// On the board look CnIndexPage clips its <h1> unless `showTitle` is set;
	// the learniq list boards draw the title above the list. LqCertificaten
	// and LqExZaken draw none.
	const untitled = new Set(['Credentials', 'FraudCases'])
	const lists = pages.filter((p) => p.type === 'index')
	assert.ok(lists.length > 100, 'the index pages are found')
	const offenders = lists
		.filter((p) => (p.config?.showTitle === true) === untitled.has(p.id))
		.map((p) => p.id)
	assert.deepEqual(offenders, [])
})
