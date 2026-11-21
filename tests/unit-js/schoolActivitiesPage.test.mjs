// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The staff page Activiteiten (school-activities-staff-side T1, board
// learniq/LqActiviteiten): the activities of the school with their places,
// confirmed sign-ups and waiting list. The page draws the rows of
// GET /api/school-activities (SchoolActivityStaff::board()), so its columns
// are the keys that method answers, its "Open for sign-up" action calls the
// PUT route that opens a draft, its menu gate names the roles the action
// activity.manage is seeded for, and every word it shows has a Dutch entry.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
// @spec openspec/changes/school-activities-staff-side/specs/school-activities/spec.md#requirement-staff-must-set-an-activitys-places-by-capacity-and-supervision

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const read = (rel) => readFileSync(new URL(rel, import.meta.url), 'utf8')
const FRAGMENT = JSON.parse(read('../../src/manifest.d/school-calendar.json'))
const NL = JSON.parse(read('../../l10n/nl.json')).translations
const ROUTES = read('../../appinfo/routes.php')
const STAFF = read('../../lib/Service/SchoolActivity/SchoolActivityStaff.php')
const SEED = JSON.parse(read('../../lib/actions.seed.json'))
const ICONS = read('../../src/icons.js')

const page = FRAGMENT.pages.find((p) => p.id === 'SchoolActivities')
const menu = FRAGMENT.menu.find((m) => m.id === 'SchoolActivitiesMenu')

/**
 * The keys of the array board() builds per activity.
 *
 * @return {string[]}
 */
function boardKeys() {
	const body = STAFF.slice(STAFF.indexOf('public function board()'), STAFF.indexOf('}//end board()'))
	const row = body.slice(body.indexOf('$board[] = ['))
	return [...row.matchAll(/'([a-zA-Z]+)' =>/g)].map((m) => m[1])
}

test('the page lists the activities from the staff endpoint', () => {
	assert.ok(page, 'SchoolActivities page exists')
	assert.equal(page.type, 'index')
	assert.equal(page.config.entitySource, 'endpoint')
	assert.equal(page.config.sourceConfig.url, '/apps/learniq/api/school-activities')
	assert.equal(page.config.sourceConfig.rowsPath, 'results')
	assert.match(ROUTES, /'url' => '\/api\/school-activities', 'verb' => 'GET'/)
})

test('every column is a key the endpoint answers, and places, confirmed and waiting list are shown', () => {
	const keys = boardKeys()
	const columns = page.config.columns.map((c) => c.key)
	for (const key of columns) {
		assert.ok(keys.includes(key), `column ${key} is not answered by SchoolActivityStaff::board()`)
	}
	for (const key of ['title', 'places', 'confirmed', 'waitlist']) {
		assert.ok(columns.includes(key), `the page shows ${key}`)
	}
	assert.ok(!columns.includes('id'), 'the page shows no id')
})

test('a draft activity opens through the PUT route, and only a draft shows the action', () => {
	const action = page.config.actions.find((a) => a.id === 'open-activity')
	assert.equal(action.type, 'api-call')
	assert.equal(action.method, 'PUT')
	assert.equal(action.url, '/apps/learniq/api/school-activities/@objectId/open')
	assert.match(ROUTES, /'url' => '\/api\/school-activities\/\{id\}\/open', 'verb' => 'PUT'/)
	assert.deepEqual(action.visibleWhen, { field: 'status', op: 'eq', value: 'draft' })
})

test('the page adds, imports and deletes nothing: guardians sign up on the portal', () => {
	for (const flag of ['showAddAction', 'showDeleteAction', 'showMassImport', 'showMassCopy', 'showMassDelete']) {
		assert.equal(page.config[flag], false, `${flag} is off`)
	}
})

test('the menu entry is shown to the roles activity.manage is seeded for, and not to a company', () => {
	assert.equal(menu.route, page.id)
	const groupToRole = { admin: 'admin', coordinators: 'coordinator', instructors: 'instructor', 'team-leads': 'team-lead' }
	const seeded = SEED['activity.manage'] ?? SEED.actions?.['activity.manage']
	assert.ok(Array.isArray(seeded), 'activity.manage is seeded')
	const expected = seeded.map((group) => groupToRole[group]).sort()
	assert.deepEqual([...menu.visibleIf['user.primaryRole'].in].sort(), expected)
	assert.deepEqual(menu.visibleIf['workspace.chosenSegment'], { notIn: ['corporate'] })
})

test('the icons are registered and every word has a Dutch entry', () => {
	for (const icon of [menu.icon, ...page.config.actions.map((a) => a.icon)]) {
		assert.match(ICONS, new RegExp(`^\\t${icon},$`, 'm'), `${icon} is registered`)
	}
	const words = [
		menu.label,
		page.title,
		page.config.sourceConfig.refusedText,
		...page.config.columns.map((c) => c.label),
		...page.config.columns.flatMap((c) => Object.values(c.enumLabels ?? {})),
		...page.config.actions.flatMap((a) => [a.label, a.successMessage]),
	]
	for (const word of words) {
		assert.ok(NL[word], `"${word}" has a Dutch entry`)
	}
})
