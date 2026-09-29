// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// An integration card that reads `titleLabel` shows its manifest title.
// Run via `node --test tests/unit-js/` (package.json's `test:js-unit`).

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { applyIntegrationTitles } from '../../src/utils/integrationTitles.js'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
const page = (widgets) => ({ pages: [{ id: 'P', type: 'detail', config: { widgets } }] })

test('the contacts leaf gets its manifest title as titleLabel', () => {
	const manifest = page([{ id: 'lp-contact', type: 'integration', integrationId: 'contacts', title: 'Contact card' }])
	applyIntegrationTitles(manifest)
	assert.deepEqual(manifest.pages[0].config.widgets[0].props, { titleLabel: 'Contact card' })
})

test('an explicit titleLabel and other props are kept', () => {
	const manifest = page([{ type: 'integration', integrationId: 'contacts', title: 'Contact card', props: { titleLabel: 'Own', limit: 3 } }])
	applyIntegrationTitles(manifest)
	assert.deepEqual(manifest.pages[0].config.widgets[0].props, { titleLabel: 'Own', limit: 3 })

	const withOther = page([{ type: 'integration', integrationId: 'contacts', title: 'Contact card', props: { limit: 3 } }])
	applyIntegrationTitles(withOther)
	assert.deepEqual(withOther.pages[0].config.widgets[0].props, { limit: 3, titleLabel: 'Contact card' })
})

test('cards that read title, untitled widgets and other types are untouched', () => {
	const widgets = [
		{ type: 'integration', integrationId: 'talk', title: 'Class space' },
		{ type: 'integration', integrationId: 'contacts' },
		{ type: 'data', integrationId: 'contacts', title: 'Identity' },
	]
	const manifest = page(widgets.map((w) => ({ ...w })))
	applyIntegrationTitles(manifest)
	assert.deepEqual(manifest.pages[0].config.widgets, widgets)
	assert.doesNotThrow(() => applyIntegrationTitles({ pages: [{ id: 'X' }, null] }))
})

test('the shipped LearnerProfileDetail contact leaf shows "Contact card"', () => {
	const people = JSON.parse(read('../../src/manifest.d/people.json'))
	const manifest = { pages: people.pages.map((p) => JSON.parse(JSON.stringify(p))) }
	applyIntegrationTitles(manifest)
	const detail = manifest.pages.find((p) => p.id === 'LearnerProfileDetail')
	const leaf = detail.config.widgets.find((w) => w.id === 'lp-contact')
	assert.equal(leaf.props.titleLabel, leaf.title)
	assert.equal(leaf.title, 'Contact card')
})

test('boot applies it to the merged manifest', () => {
	assert.match(read('../../src/main.js'), /applyIntegrationTitles\(mergedManifest\)/)
})
