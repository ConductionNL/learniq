// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The learniq lists draw the toolbar their zuiddrecht boards draw: the lens
// pills with their counts, Save view, the Filter button, Dutch column titles
// and the row menu named after the row (LqAanwezigheidLijst, LqBpvPlaatsingen,
// LqCfBeperkingen, LqCfPartners, LqCfPrivacyverzoeken, LqCfVerplichtingen,
// LqCijfers, LqCoAanmeldingen, LqDossier, LqGroepen, LqKoppelingen,
// LqLeerlingen, LqZoMeldingen, LqZoNotities, LqStore), and a learner reads
// their own grades on a page of their own (LqLlCijfers, a lens of the grade entries).
//
// Every lens filters on a field: a misspelled key matches nothing and shows an
// empty list with a zero count, and nothing logs it. So each key is checked
// against the schema the page reads.

import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { describe, test } from 'node:test'

function readJson(rel) {
	return JSON.parse(readFileSync(new URL(rel, import.meta.url), 'utf8'))
}

const REGISTER = readJson('../../lib/Settings/learniq_register.json')
const EN = readJson('../../l10n/en.json').translations
const NL = readJson('../../l10n/nl.json').translations
const SCHEMAS = {}
for (const [key, s] of Object.entries(REGISTER.components.schemas)) {
	SCHEMAS[key] = s
	SCHEMAS[s.slug] = s
}
const MANIFEST_DIR = new URL('../../src/manifest.d/', import.meta.url)
const PAGES = [
	...readdirSync(MANIFEST_DIR)
		.filter((f) => f.endsWith('.json'))
		.flatMap((f) => readJson(`../../src/manifest.d/${f}`).pages || []),
	...readJson('../../src/manifest.json').pages,
]
const page = (id) => PAGES.find((p) => p.id === id)

// Board to page; the pages whose rows live in learniq's own register.
const BOARD_LISTS = {
	LqAanwezigheidLijst: 'AttendanceRecords',
	LqBpvPlaatsingen: 'BpvPlacements',
	LqCfBeperkingen: 'AccessibilityLimitations',
	LqCfPartners: 'PartnerApprovalJobs',
	LqCfVerplichtingen: 'Regulations',
	LqCijfers: 'GradeEntries',
	LqCoAanmeldingen: 'Applications',
	LqDossier: 'DossierNotes',
	LqGroepen: 'Cohorts',
	LqLeerlingen: 'LearnerProfiles',
	LqZoMeldingen: 'ConcernReports',
	LqZoNotities: 'ConfidentialNotes',
}
// The two lists over another app's register: OpenRegister's privacy requests
// and integriq's connection registry.
const FOREIGN_LISTS = {
	LqCfPrivacyverzoeken: 'DataSubjectRequests',
	LqKoppelingen: 'ConnectionRegistry',
}
const ALL_LISTS = { ...BOARD_LISTS, ...FOREIGN_LISTS }

/**
 * The strings a list config declares that a reader sees.
 *
 * @param {object} cfg The page config.
 * @return {string[]} The labels.
 */
function labelsOf(cfg) {
	return [
		cfg.countText,
		cfg.searchPlaceholder,
		cfg.bulkNoun,
		...(cfg.quickFilters || []).map((q) => q.label),
		...(cfg.columns || []).filter((c) => typeof c !== 'string').map((c) => c.label),
		...(cfg.columns || []).flatMap((c) => Object.values((c && c.enumLabels) || {})),
	].filter(Boolean)
}

// A filter key as the API reads it: `markedAt[gte]` filters on markedAt.
const fieldOf = (key) => key.replace(/\[[a-z]+\]$/, '')

describe('board list toolbars', () => {
	test('every board list offers its lenses, Save view and Filter', () => {
		for (const [board, id] of Object.entries(ALL_LISTS)) {
			const cfg = page(id).config
			assert.equal(cfg.allowSavedViews, true, `${board}: no Save view`)
			assert.ok(Array.isArray(cfg.quickFilters) && cfg.quickFilters.length > 0, `${board}: no lenses`)
			assert.equal(cfg.quickFilters.filter((q) => q.default === true).length, 1, `${board}: one lens opens the list`)
			for (const q of cfg.quickFilters) {
				assert.equal(q.showCount, true, `${board}: lens ${q.label} shows no count`)
			}
		}
	})

	test('every lens filters on a field of the schema the list reads', () => {
		for (const [board, id] of Object.entries(BOARD_LISTS)) {
			const cfg = page(id).config
			const props = SCHEMAS[cfg.schema].properties
			for (const q of cfg.quickFilters) {
				for (const key of Object.keys(q.filter)) {
					assert.ok(props[fieldOf(key)], `${board}: lens ${q.label} filters on ${key}, not on ${cfg.schema}`)
				}
			}
		}
	})

	test('every lens value is a value the field can hold', () => {
		for (const [board, id] of Object.entries(BOARD_LISTS)) {
			const cfg = page(id).config
			const props = SCHEMAS[cfg.schema].properties
			for (const q of cfg.quickFilters) {
				for (const [key, value] of Object.entries(q.filter)) {
					const allowed = props[fieldOf(key)].enum
					if (!allowed) {
						continue
					}
					for (const v of [value].flat()) {
						assert.ok(allowed.includes(v), `${board}: lens ${q.label} asks for ${key} ${v}`)
					}
				}
			}
		}
	})

	test('every plain column is a field of the schema the list reads', () => {
		for (const [board, id] of Object.entries(BOARD_LISTS)) {
			const cfg = page(id).config
			const props = SCHEMAS[cfg.schema].properties
			for (const c of cfg.columns) {
				const key = typeof c === 'string' ? c : c.key
				if (key.startsWith('@self.')) {
					continue
				}
				assert.ok(props[key.split('.')[0]], `${board}: column ${key} is not on ${cfg.schema}`)
				assert.notEqual(key, 'tenant_id', `${board} shows the tenant id`)
			}
		}
	})

	test('every string a board list declares reads in English and Dutch', () => {
		const missing = []
		for (const [board, id] of Object.entries(ALL_LISTS)) {
			for (const label of labelsOf(page(id).config)) {
				if (EN[label] !== label || !NL[label]) {
					missing.push(`${board}: ${label}`)
				}
			}
		}
		assert.deepEqual(missing, [])
	})

	test('a row menu is named after the row, not its uuid', () => {
		// The row name falls back to `@self.name`, which is the uuid on a
		// schema without an objectNameField. These lists name a text field.
		for (const id of ['AccessibilityLimitations', 'Regulations', 'LearnerProfiles', 'ConnectionRegistry']) {
			const cfg = page(id).config
			assert.ok(cfg.massActionNameField, `${id}: no row name field`)
			const props = id === 'ConnectionRegistry' ? null : SCHEMAS[cfg.schema].properties
			if (props) {
				assert.equal(props[cfg.massActionNameField].type, 'string', `${id}: ${cfg.massActionNameField}`)
			}
		}
	})

	test('the integrations list has lenses for the states, not a folder panel', () => {
		const cfg = page('ConnectionRegistry').config
		assert.equal(cfg.folderSidebar, undefined)
		const status = cfg.columns.find((c) => c.key === 'status')
		assert.equal(status.widget, 'badge')
		assert.equal(status.formatter, 'connectionStatus')
		assert.equal(status.widgetProps.colorMap.error, 'error')
		assert.equal(status.widgetProps.colorMap.configured, 'success')
	})

	test('the privacy requests read their kind and state in words', () => {
		const cfg = page('DataSubjectRequests').config
		for (const key of ['type', 'status']) {
			const c = cfg.columns.find((col) => col.key === key)
			assert.deepEqual(Object.keys(c.enumLabels).sort(), [...c.enum].sort(), key)
		}
	})

	test('the marketplace has a lens per kind it offers', () => {
		const store = page('Store')
		assert.deepEqual(store.config.kindLenses, [{ kind: 'course-package', label: 'Course package' }])
		assert.equal(EN['Course package'], 'Course package')
		assert.ok(NL['Course package'])
	})
})

describe('the learner reads their own grades', () => {
	const SIMPLE = readJson('../../src/menu-layout.simple.json')
	const overlay = SIMPLE.pages.find((p) => p.id === 'GradeEntries' && p.when)
	const config = overlay.config

	test('grades have one list: no page of their own for the learner', () => {
		assert.equal(page('MyGrades'), undefined)
		assert.equal(PAGES.filter((p) => p.type === 'index' && p.config?.schema === 'grade-entry').length, 1)
	})

	test('the overlay is for the learner and reads Mijn cijfers, Concept, Alle cijfers', () => {
		assert.deepEqual(overlay.when, { 'user.primaryRole': { in: ['learner'] } })
		assert.deepEqual(config.quickFilters.map((q) => q.label), ['My grades', 'Draft', 'All grades'])
		assert.deepEqual(config.quickFilters[0].filter, { learnerId: '@me', lifecycle: 'published' })
		assert.equal(config.quickFilters.filter((q) => q.default === true).length, 1)
		assert.equal(config.quickFilters[0].default, true)
	})

	test('the list changes nothing and opens what a grade does to the average', () => {
		assert.equal(config.readOnly, true)
		assert.deepEqual(config.headerButtons, [])
		assert.equal(config.rowRoute, 'GradeImpactDetail')
		assert.ok(page('GradeImpactDetail'), 'GradeImpactDetail exists')
	})

	test('its columns are grade fields and read in Dutch', () => {
		const props = SCHEMAS['grade-entry'].properties
		for (const c of config.columns) {
			assert.ok(props[c.key], c.key)
			assert.ok(NL[c.label], c.label)
		}
		for (const q of config.quickFilters) {
			assert.ok(NL[q.label], q.label)
			for (const key of Object.keys(q.filter)) {
				assert.ok(props[key], key)
			}
		}
		assert.ok(NL[config.searchPlaceholder], config.searchPlaceholder)
		assert.ok(!config.columns.some((c) => c.key === 'learnerId'), 'a learner\'s own list repeats no learner')
	})
})
