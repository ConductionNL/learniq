// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Every role's start page is called Today, as its board draws it (LqDashboard,
// LqTlVandaag, LqHrVandaag, LqZoVandaag, LqLlVandaag, LqOuVandaag): the title
// "Vandaag" with the date under it, and for some roles a line after the date.
// The admin settings and the timetable draw the cards their boards draw
// (LqInstellingen, LqLlRooster).
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')

/**
 * A file of the app, as text.
 *
 * @param {string} path Path from the app root.
 * @return {string} Its contents.
 */
function read(path) {
	return readFileSync(resolve(root, path), 'utf8')
}

const nl = JSON.parse(read('l10n/nl.json')).translations
const dashboards = JSON.parse(read('src/manifest.d/dashboard.json')).pages.filter(
	(page) => ['Dashboard', 'DashboardAdmin', 'DashboardTeacher', 'DashboardStudent'].includes(page.id),
)

/** The line after the date on each board, by primary role. */
const boardLines = {
	hr: 'hr-medewerker · schoolbreed, alle locaties',
	'team-lead': 'teamleider · schoolbreed: een teamleider ziet alle cursussen, opdrachten, lessen en groepen',
	'confidential-counsellor': 'vertrouwenspersoon · uw eigen verplichte trainingen en punten',
}

test('every role dashboard is titled Today, with the date under it', () => {
	assert.equal(dashboards.length, 4)
	for (const page of dashboards) {
		assert.equal(page.config?.title, 'Today', page.id)
		assert.equal(page.config?.greeting, 'date', page.id)
	}
	assert.equal(nl.Today, 'Vandaag')
})

test('the line after the date says what the board says, in Dutch', () => {
	for (const page of dashboards) {
		const lines = page.config.subtitleByRole
		assert.deepEqual(Object.keys(lines).sort(), Object.keys(boardLines).sort(), page.id)
		for (const [role, english] of Object.entries(lines)) {
			assert.equal(nl[english], boardLines[role], `${page.id} ${role}`)
		}
	}
})

test('the dashboard takes its title and subtitle from the page config', () => {
	const source = read('src/views/LearniqDashboards.vue')
	assert.match(source, /:title="pageTitle"/)
	assert.match(source, /:greeting="greeting"/)
	assert.match(source, /:description="pageSubtitle"/)
	assert.match(source, /return this\.t\('learniq', this\.title\)/)
	assert.doesNotMatch(source, /'Dashboard'\)/, 'no "<role> · Dashboard" title')
})

test('the dashboard card titles have their board wording in Dutch', () => {
	assert.equal(nl['My courses'], 'Mijn cursussen')
	assert.equal(nl['Assignments to grade'], 'Opdrachten om na te kijken')
	assert.equal(nl['Sessions to mark'], 'Lessen om te registreren')
	assert.equal(nl['My cohorts'], 'Mijn groepen')
	assert.equal(nl['My mandatory training'], 'Mijn verplichte trainingen')
})

test('the admin settings put each section in a tab card, as LqInstellingen draws', () => {
	const source = read('src/views/settings/AdminRoot.vue')
	assert.match(source, /<CnAdminSettingsShell\b/)
	assert.match(source, /:tabs="tabs"/)
	for (const tab of ['example-data', 'data-exchange', 'timetable-exchange', 'lti', 'store', 'ai-translations']) {
		assert.match(source, new RegExp(`#tab-${tab}>`), tab)
	}
	assert.equal(nl['Learniq administration'], 'Learniq-beheer')
	assert.equal(nl['AI translations'], 'AI-vertalingen')
})

test('the timetable draws its empty week inside the week card, as LqLlRooster draws the grid', () => {
	const source = read('src/views/MyTimetable.vue')
	const card = source.slice(source.indexOf('class="my-timetable__week"'))
	const close = card.indexOf('</CnDetailCard>')
	assert.ok(card.indexOf(":name=\"t('learniq', 'No sessions')\"") < close, 'empty state inside the card')
	assert.ok(card.indexOf(":name=\"t('learniq', 'No sessions')\"") > 0)
})
