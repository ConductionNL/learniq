// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// `appinfo/attention.json`: what this app tells LaunchPad needs attention.
//
// LaunchPad's "First today" widget reads this file from every app a user has,
// counts each item in OpenRegister as that user and shows one ranked list with
// a link into the app. The contract is LaunchPad's
// (`openspec/specs/attention-feed/spec.md`, REQ-ATT-001 and REQ-ATT-003 in
// ConductionNL/launchpad); the rules below are a copy of its
// `AttentionDeclarationValidator`, because that class is not available here.
//
// The file fails quietly on LaunchPad's side: an invalid file is left out of
// the list, a filter that differs from the Today dashboard's card shows a
// different number than the app does, and a string without a translation is
// shown in English. So every one of those is held here.
//
// The card is BUILT, not read: the simple profile is built for a teacher with
// the library's own buildManifest(), as src/main.js does.
//
// Run by `npm run check:attention`, which `check:specs` (a CI frontend check)
// calls.
//
// @spec openspec/changes/simple-today-dashboard/specs/dashboard/spec.md

import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { test } from 'node:test'
import { buildManifest } from '../../node_modules/@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { passesContextPredicates } from '../../node_modules/@conduction/nextcloud-vue/src/utils/visibleIfContext.js'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const root = new URL('../../', import.meta.url)
const readJson = (path) => JSON.parse(readFileSync(new URL(path, root), 'utf8'))

const declaration = readJson('appinfo/attention.json')
const item = declaration.items.find((entry) => entry.id === 'attendance-flags-open')

const base = readJson('src/manifest.json')
// The Today dashboard is for the roles that teach or run teaching.
base.runtime = { user: { primaryRole: 'instructor' } }
const built = buildProfiledManifest(
	buildManifest,
	base,
	readdirSync(new URL('src/manifest.d/', root))
		.filter((name) => name.endsWith('.json'))
		.sort()
		.map((name) => readJson('src/manifest.d/' + name)),
	readJson('src/menu-layout.simple.json'),
	passesContextPredicates,
)
// The app's own "First today" card, as the Today dashboard ships it.
const card = built.pages
	.find((page) => page.id === 'Dashboard')
	.config.widgets.find((widget) => widget.id === 'today-first').content

const SEVERITIES = ['error', 'warning', 'info']
const OPERATORS = ['gt', 'gte', 'lt', 'lte', 'eq', 'neq']
// The tokens LaunchPad resolves before it counts. Any other value that starts
// with `@` fails the count (REQ-ATT-003).
const TOKEN = /^@(me|now|today([+-]\d+d)?|monthStart|quarterStart|yearStart)$/

function isScalar(value) {
	return ['string', 'number', 'boolean'].includes(typeof value)
}
function isFlat(value) {
	return (
		isScalar(value)
		|| (Array.isArray(value) && value.length > 0 && value.every(isScalar))
	)
}

test('attention.json is version 1 with a list of at most ten items, each id once', () => {
	assert.equal(declaration.version, 1)
	assert.ok(Array.isArray(declaration.items))
	assert.ok(declaration.items.length > 0)
	assert.ok(declaration.items.length <= 10)
	const ids = declaration.items.map((entry) => entry.id)
	assert.equal(new Set(ids).size, ids.length)
})

test('every item has an id, three texts, a known severity, operator and a numeric value', () => {
	for (const entry of declaration.items) {
		assert.match(entry.id, /^[a-z0-9][a-z0-9-]{0,63}$/)
		for (const text of [entry.title, entry.reason, entry.action?.label]) {
			assert.equal(typeof text, 'string', entry.id)
			assert.notEqual(text.trim(), '', entry.id)
		}
		assert.ok(SEVERITIES.includes(entry.severity ?? 'info'), entry.id)
		assert.ok(OPERATORS.includes(entry.op ?? 'gt'), entry.id)
		assert.equal(typeof (entry.value ?? 0), 'number', entry.id)
	}
})

test('every item names its register and schema by slug and writes the filter flat', () => {
	const slug = /^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/
	for (const entry of declaration.items) {
		assert.match(entry.source.register, slug)
		assert.match(entry.source.schema, slug)
		const filter = entry.source.filter ?? {}
		assert.equal(Array.isArray(filter), false, entry.id)
		for (const [key, value] of Object.entries(filter)) {
			// An operator sits in the key ("deadline[lt]"). A nested object
			// cannot be written into the link.
			assert.ok(isFlat(value), `${entry.id}: ${key} is not flat`)
			for (const single of [value].flat()) {
				if (typeof single === 'string' && single.startsWith('@')) {
					assert.match(single, TOKEN, `${entry.id}: ${key}`)
				}
			}
		}
	}
})

test('every link stays inside the app', () => {
	for (const entry of declaration.items) {
		assert.match(entry.action.path, /^(\/[A-Za-z0-9._~-]+)+\/?$/)
		assert.equal(entry.action.path.includes('..'), false, entry.id)
	}
})

test('the declared item is the item the Today dashboard card shows', () => {
	assert.ok(item)
	assert.equal(card.layout, 'attention')
	assert.equal(item.title, card.title)
	assert.equal(item.reason, card.reason)
	assert.equal(item.op ?? 'gt', card.visibleWhen.op)
	assert.equal(item.value ?? 0, card.visibleWhen.value)
	assert.equal(item.action.label, card.actions[0].label)
})

test('it ranks as a warning in LaunchPad, though the card is drawn in the error colour', () => {
	// The card is alone on a teacher's own dashboard. In LaunchPad the item
	// sits in one list with other apps, and it counts every open flag the
	// user may read, not only their own. So it ranks under another app's
	// "yours, and late". Both values are held, so neither moves unnoticed.
	assert.equal(card.variant, 'error')
	assert.equal(item.severity, 'warning')
})

test('it counts in the same register and schema with the same filter, key by key', () => {
	const own = card.visibleWhen.source
	assert.equal(item.source.register, own.register)
	assert.equal(item.source.schema, own.schema)
	assert.deepEqual(
		Object.keys(item.source.filter).sort(),
		Object.keys(own.filter).sort(),
	)
	for (const [key, value] of Object.entries(own.filter)) {
		assert.strictEqual(item.source.filter[key], value, key)
	}
})

test('it counts in a register and schema the app ships, on fields the schema carries', () => {
	const register = readJson('lib/Settings/learniq_register.json')
	const slugs = Object.values(register.components.registers).map(
		(entry) => entry.slug,
	)
	assert.ok(slugs.includes(item.source.register), item.source.register)
	const schema = Object.values(register.components.schemas).find(
		(entry) => entry.slug === item.source.schema,
	)
	assert.ok(schema, item.source.schema)
	for (const key of Object.keys(item.source.filter)) {
		const field = key.replace(/\[.*$/, '')
		assert.ok(schema.properties[field], field)
	}
})

test('it opens the list the card opens, with the filter the card links with', () => {
	const route = card.actions[0].route
	const target = built.pages.find((page) => page.id === route.name)
	assert.ok(target, route.name)
	assert.equal(item.action.path, target.route)
	// LaunchPad writes the filter into the link as the query. The card's own
	// link must carry the same keys and the same values.
	assert.deepEqual(
		Object.keys(route.query).sort(),
		Object.keys(item.source.filter).sort(),
	)
	for (const [key, value] of Object.entries(item.source.filter)) {
		assert.equal(route.query[key], String(value), key)
	}
})

test('the title, the reason and the action label are in English and in Dutch', () => {
	// LaunchPad translates with THIS app's catalogues, on the server.
	const en = readJson('l10n/en.json').translations
	const nl = readJson('l10n/nl.json').translations
	for (const entry of declaration.items) {
		for (const text of [entry.title, entry.reason, entry.action.label]) {
			// English reads as written, Dutch is a translation.
			assert.equal(en[text], text, `en: ${text}`)
			assert.equal(typeof nl[text], 'string', `nl: ${text}`)
			assert.notEqual(nl[text].trim(), '', `nl: ${text}`)
			assert.notEqual(nl[text], text, `nl: ${text}`)
		}
		// The count survives the translation.
		if (entry.reason.includes('{value}')) {
			assert.ok(nl[entry.reason].includes('{value}'), entry.id)
		}
	}
})
