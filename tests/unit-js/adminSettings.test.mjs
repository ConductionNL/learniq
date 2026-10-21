// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The admin page is declared in src/adminSettings.js: tabs in the order of
// board LqInstellingen, and per tab the library sections that draw it. These
// cases hold what the hand-written sections did before, and that every word
// an admin reads has a Dutch translation.
// Run via `node --test tests/unit-js/` (package.json's `test:js-unit`).

import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { test } from 'node:test'
import { ADMIN_SECTIONS, ADMIN_TABS, allSections, sectionAddress } from '../../src/adminSettings.js'

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8')
const dutch = JSON.parse(read('l10n/nl.json')).translations
const routes = read('appinfo/routes.php')

/**
 * The section with this id.
 *
 * @param {string} id The section id.
 * @return {object}
 */
function section(id) {
	const found = allSections().find((candidate) => candidate.id === id)
	assert.ok(found, `no section ${id}`)
	return found
}

/**
 * The field with this key in a fields section.
 *
 * @param {string} id The section id.
 * @param {string} key The field key.
 * @return {object}
 */
function field(id, key) {
	const found = section(id).props.fields.find((candidate) => candidate.key === key)
	assert.ok(found, `no field ${key} in ${id}`)
	return found
}

/**
 * Every string an admin reads on the page.
 *
 * @return {Array<string>}
 */
function visibleStrings() {
	const strings = ADMIN_TABS.map((tab) => tab.label)
	for (const entry of allSections()) {
		const props = entry.props || {}
		strings.push(entry.name, entry.description, props.name, props.description, props.savedMessage, props.removeSetQuestion)
		for (const item of props.fields || []) {
			strings.push(item.label, item.hint, item.placeholder, item.text, item.confirm, item.confirmLabel, item.successText, item.clearLabel, item.keyLabel, item.valueLabel, item.addLabel)
			strings.push(...Object.values(item.textMap || {}))
			for (const option of item.options || []) {
				strings.push(option.label, option.description)
			}
		}
		const content = entry.content || {}
		strings.push(content.emptyText, content.headerLink?.label)
		strings.push(...Object.values(content.pill?.labels || {}))
		for (const labels of Object.values(content.valueLabels || {})) {
			strings.push(...Object.values(labels))
		}
	}
	// A bare token or an address example is not prose.
	return strings.filter((text) => typeof text === 'string' && text !== '' && !/^\{[^}]+\}$/.test(text) && !/^https?:\/\//.test(text))
}

test('the tabs follow board LqInstellingen and every section has a unique id', () => {
	assert.deepEqual(ADMIN_TABS.map((tab) => tab.id), ['menu', 'example-data', 'data-exchange', 'timetable-exchange', 'lti', 'store', 'app', 'ai-translations', 'action-rights'])
	for (const tab of ADMIN_TABS) {
		assert.ok((ADMIN_SECTIONS[tab.id] || []).length > 0, `tab ${tab.id} is empty`)
	}
	const ids = allSections().map((entry) => entry.id)
	assert.equal(new Set(ids).size, ids.length)
	assert.equal(sectionAddress('section-lti'), '/settings/admin/learniq?tab=lti#section-lti')
	assert.equal(sectionAddress('section-nowhere'), '')
})

test('the signing key section rotates through the real endpoint, asks first and shows the new fingerprint', () => {
	assert.equal(section('section-credential-signing').props.url, '/apps/learniq/api/settings/signing-key')
	const rotate = field('section-credential-signing', 'rotate')
	assert.equal(rotate.url, '/apps/learniq/api/settings/signing-key/rotate')
	assert.notEqual(rotate.url, '/apps/learniq/api/settings/load')
	assert.deepEqual(rotate.params, { confirm: 'true' })
	assert.ok(rotate.confirm)
	assert.equal(rotate.reload, true)
	assert.match(routes, /'keyAdmin#rotateSigningKey', 'url' => '\/api\/settings\/signing-key\/rotate', 'verb' => 'POST'/)
	assert.match(routes, /'keyAdmin#signingKeyStatus', 'url' => '\/api\/settings\/signing-key',\s+'verb' => 'GET'/)
})

test('the course store keeps its token write-only and removable', () => {
	const token = field('section-course-store', 'token')
	assert.equal(token.type, 'secret')
	assert.equal(token.setKey, 'tokenSet')
	assert.equal(token.clearable, true)
	assert.equal(token.clearKey, 'clearToken')
	assert.equal(section('section-course-store').props.saveAll, true, 'the endpoint replaces the whole connection')
})

test('the timetable exchange maps each rostering system to learniq groups', () => {
	const props = section('section-timetable-exchange').props
	assert.equal(props.url, '/apps/learniq/api/admin/timetable-exchange')
	assert.equal(props.saveAll, true, 'the endpoint replaces the receiver and every map')
	const maps = props.fields.filter((item) => item.type === 'pairs')
	assert.deepEqual(maps.map((item) => item.path), ['groupMaps.roster-zermelo', 'groupMaps.roster-untis-oneroster', 'groupMaps.roster-xedule', 'groupMaps.roster-timeedit'])
	for (const map of maps) {
		assert.deepEqual(map.valueSource, { register: 'learniq', schema: 'cohort', labelField: 'name' })
	}
})

test('the configuration that lived in the app is on the admin page (Q7)', () => {
	assert.equal(field('section-segment', 'segment').optionsPath, 'segments')
	assert.equal(section('section-segment').props.saveUrl, '/apps/learniq/api/setup/config')
	for (const item of section('section-timetable-visibility').props.fields) {
		assert.match(item.path, /^policy\./, `${item.key} saves inside the policy`)
	}
	assert.match(routes, /'timetableVisibility#savePolicy', 'url' => '\/api\/timetable\/visibility-policy', 'verb' => 'PUT'/)
	assert.equal(field('section-opening-hours', 'weekdays').type, 'week-hours')
	assert.equal(section('section-opening-hours').props.url, '/apps/learniq/api/reports/room-use/opening-hours')
	const fragments = readdirSync(new URL('../../src/manifest.d/', import.meta.url)).filter((name) => name.endsWith('.json'))
	const routesInApp = [read('src/manifest.json'), ...fragments.map((name) => read(`src/manifest.d/${name}`))]
		.flatMap((text) => JSON.parse(text).pages || [])
		.map((page) => page.route)
	assert.deepEqual(routesInApp.filter((route) => String(route).startsWith('/settings/learniq')), [], 'the in-app settings routes are gone')
})

test('example data lists the loaded sets and removes one through the setup action', () => {
	const props = section('section-example-data').props
	assert.equal(props.setsUrl, '/apps/learniq/api/setup/example-sets')
	assert.equal(props.removeSetUrl, '/apps/learniq/api/setup/action/remove-example-set-{id}')
	assert.match(props.removeSetQuestion, /\{set\}/)
})

test('the privacy agreement is saved through the server, which writes the audit fields', () => {
	const props = section('section-privacy-governance').props
	assert.equal(props.url, '/apps/learniq/api/admin/compliance')
	const keys = props.fields.map((item) => item.key)
	for (const audit of ['privacyconvenantSignedAt', 'lastReviewedAt', 'lastReviewedBy']) {
		assert.ok(!keys.includes(audit), `${audit} is not editable`)
	}
	assert.match(routes, /'complianceSettings#update', 'url' => '\/api\/admin\/compliance', 'verb' => 'PUT'/)
})

test('the action matrix is the library one on learniq\'s endpoint', () => {
	assert.equal(section('section-action-rights').type, 'action-matrix')
	assert.equal(section('section-action-rights').props.endpoint, '/apps/learniq/api/admin/action-matrix')
})

test('the default register is chosen from OpenRegister and saved under the key the settings store', () => {
	const register = field('section-openregister', 'register')
	assert.equal(register.type, 'select')
	assert.equal(register.optionsUrl, '/apps/openregister/api/registers')
	assert.equal(register.optionValue, 'slug')
	assert.equal(section('section-openregister').props.url, '/apps/learniq/api/settings')
})

test('every word an admin reads has a Dutch translation and no em-dash', () => {
	const strings = visibleStrings()
	assert.ok(strings.length > 60)
	const missing = strings.filter((text) => typeof dutch[text] !== 'string' || dutch[text].trim() === '')
	assert.deepEqual([...new Set(missing)], [])
	assert.deepEqual(strings.filter((text) => text.includes('—')), [])
})
