// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The absence reports page (/attendance/excuses, manifest page
// ExcuseRequests) had no menu entry: a teacher, the intern begeleider and the
// director could only reach it by typing the URL. Found on a clean primary
// school install (2026-10-01). Every role that can read an ExcuseRequest now
// reaches the page from the menu, in a school, and nobody else does. The menu
// is built with the library's own buildManifest() and the app's
// menu-layout.json, as src/main.js does, and every entry is checked with the
// library's own visibleIf evaluator.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { test } from 'node:test'
import { buildManifest } from '../../node_modules/@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { passesContextPredicates } from '../../node_modules/@conduction/nextcloud-vue/src/utils/visibleIfContext.js'
import { buildWorkspaceRuntime } from '../../src/utils/workspaceRuntime.js'

const dir = new URL('../../src/manifest.d/', import.meta.url)
const readJson = (url) => JSON.parse(readFileSync(url, 'utf8'))
const BASE = readJson(new URL('../../src/manifest.json', import.meta.url))
const LAYOUT = readJson(new URL('../../src/menu-layout.json', import.meta.url))
const REGISTER = readJson(
	new URL('../../lib/Settings/learniq_register.json', import.meta.url),
)
const FRAGMENTS = readdirSync(dir)
	.filter((n) => n.endsWith('.json'))
	.sort()
	.map((name) => readJson(new URL(name, dir)))

/**
 * The visible menu entries that open the absence reports list.
 *
 * @param {string} primaryRole The resolved role.
 * @param {string} segment The install's segment.
 * @param {string|null} chosen The segment the school chose, or null.
 * @return {Array<string>} Their ids.
 */
function absenceEntries(primaryRole, segment = 'po', chosen = 'po') {
	const base = structuredClone(BASE)
	const runtime = {
		user: { primaryRole },
		workspace: buildWorkspaceRuntime(undefined, segment, chosen),
	}
	base.runtime = runtime
	const manifest = buildManifest(
		base,
		structuredClone(FRAGMENTS),
		structuredClone(LAYOUT),
	)
	const found = []
	const walk = (nodes, parentVisible) => {
		for (const node of nodes || []) {
			const visible =
				parentVisible
				&& (!node.visibleIf
					|| passesContextPredicates(node.visibleIf, runtime))
			if (visible && node.route === 'ExcuseRequests') {
				found.push(node.id)
			}
			walk(node.children, visible)
		}
	}
	walk(manifest.menu, true)
	return found
}

test('every role that reads absence reports finds them in the menu, once', () => {
	for (const role of [
		'instructor',
		'coordinator',
		'administration-manager',
		'admin',
	]) {
		assert.deepEqual(absenceEntries(role), ['AbsenceReportsMenu'], role)
	}
	assert.deepEqual(absenceEntries('compliance-officer'), [
		'AbsenceReportsComplianceMenu',
	])
})

test('roles the register does not let read a report get no entry', () => {
	for (const role of ['hr', 'team-lead', 'learner', 'guardian']) {
		assert.deepEqual(absenceEntries(role), [], role)
	}
})

test('every school segment shows the entry; an install that chose corporate does not', () => {
	for (const segment of ['po', 'vo', 'mbo', 'he']) {
		assert.deepEqual(
			absenceEntries('instructor', segment, segment),
			['AbsenceReportsMenu'],
			segment,
		)
	}
	assert.deepEqual(absenceEntries('instructor', 'corporate', 'corporate'), [])
	// An install that never chose keeps every entry it had.
	assert.deepEqual(absenceEntries('instructor', 'corporate', null), [
		'AbsenceReportsMenu',
	])
})

test('the menu offers the page only to groups ExcuseRequest grants read', () => {
	const read = REGISTER.components.schemas.ExcuseRequest.authorization.read
	const groups = new Set(
		read.map((rule) => (typeof rule === 'string' ? rule : rule.group)),
	)
	// DashboardRoleService maps each role to the group it is backed by.
	const groupOf = {
		instructor: 'instructors',
		coordinator: 'coordinators',
		'administration-manager': 'administration-managers',
		'compliance-officer': 'compliance-officers',
	}
	for (const [role, group] of Object.entries(groupOf)) {
		assert.ok(groups.has(group), `${role}: ExcuseRequest grants ${group}`)
		assert.equal(absenceEntries(role).length, 1, role)
	}
})
