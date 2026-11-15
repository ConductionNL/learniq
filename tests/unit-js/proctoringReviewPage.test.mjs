// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// proctoring-flag-review-page: staff reach the proctoring flag review page
// from the menu, and the page decides a flag by sending the decision alone.
// The menu is built with the library's own buildManifest() and the app's
// menu-layout.json, as src/main.js does, and every entry is checked with the
// library's own visibleIf evaluator (the pattern of absenceReportsMenu.test.mjs).
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { test } from 'node:test'
import { buildManifest } from '../../node_modules/@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { passesContextPredicates } from '../../node_modules/@conduction/nextcloud-vue/src/utils/visibleIfContext.js'
import {
	minutesIn,
	nameOrId,
	openFlagCount,
	withDecision,
} from '../../src/utils/proctoringFlags.js'
import { buildWorkspaceRuntime } from '../../src/utils/workspaceRuntime.js'

const dir = new URL('../../src/manifest.d/', import.meta.url)
const readJson = (url) => JSON.parse(readFileSync(url, 'utf8'))
const BASE = readJson(new URL('../../src/manifest.json', import.meta.url))
const LAYOUT = readJson(new URL('../../src/menu-layout.json', import.meta.url))
const REGISTER = readJson(new URL('../../lib/Settings/learniq_register.json', import.meta.url))
const FRAGMENTS = readdirSync(dir)
	.filter((n) => n.endsWith('.json'))
	.sort()
	.map((name) => readJson(new URL(name, dir)))

/**
 * The manifest as one role sees it.
 *
 * @param {string} primaryRole The resolved role.
 * @return {{manifest: object, runtime: object}}
 */
function manifestFor(primaryRole) {
	const base = structuredClone(BASE)
	const runtime = {
		user: { primaryRole },
		workspace: buildWorkspaceRuntime(undefined, 'vo', 'vo'),
	}
	base.runtime = runtime
	return {
		manifest: buildManifest(base, structuredClone(FRAGMENTS), structuredClone(LAYOUT)),
		runtime,
	}
}

/**
 * The visible menu entries that open the review page.
 *
 * @param {string} primaryRole The resolved role.
 * @return {Array<string>} Their ids.
 */
function reviewEntries(primaryRole) {
	const { manifest, runtime } = manifestFor(primaryRole)
	const found = []
	const walk = (nodes, parentVisible) => {
		for (const node of nodes || []) {
			const visible = parentVisible && (!node.visibleIf || passesContextPredicates(node.visibleIf, runtime))
			if (visible && node.route === 'ProctoringReviewQueue') {
				found.push(node.id)
			}
			walk(node.children, visible)
		}
	}
	walk(manifest.menu, true)
	return found
}

test('instructors and admins find the review page under Learning, compliance officers under Compliance', () => {
	assert.deepEqual(reviewEntries('instructor'), ['ProctoringReviewMenu'])
	assert.deepEqual(reviewEntries('admin'), ['ProctoringReviewMenu'])
	assert.deepEqual(reviewEntries('compliance-officer'), ['ProctoringReviewComplianceMenu'])
})

test('roles the register does not let read a proctoring session get no entry', () => {
	for (const role of ['learner', 'guardian', 'coordinator', 'administration-manager', 'hr', 'team-lead']) {
		assert.deepEqual(reviewEntries(role), [], role)
	}
})

test('the menu offers the page only to groups ProctoringSession grants read and update', () => {
	const rules = REGISTER.components.schemas.ProctoringSession.authorization
	const groups = (action) => new Set(rules[action].filter((rule) => typeof rule === 'string'))
	for (const group of ['instructors', 'compliance-officers']) {
		assert.ok(groups('read').has(group), `read: ${group}`)
		assert.ok(groups('update').has(group), `update: ${group}`)
	}
})

test('the page is routable and the sessions list links to it', () => {
	const { manifest } = manifestFor('instructor')
	const page = manifest.pages.find((p) => p.id === 'ProctoringReviewQueue')
	assert.equal(page.route, '/assessments/proctoring/review')
	assert.equal(page.component, 'ProctoringReviewQueue')
	const sessions = manifest.pages.find((p) => p.id === 'ProctoringSessions')
	const link = (sessions.config.headerActions || []).find((a) => a.route === 'ProctoringReviewQueue')
	assert.ok(link, 'ProctoringSessions has no header link to the review page')
	assert.equal(link.handler, 'navigate')
})

test('a decision sends the decision alone; the server stamps who and when', () => {
	const flags = [
		{ flagId: 'f1', kind: 'tab-hidden', reviewDecision: 'pending' },
		{ flagId: 'f2', kind: 'window-blur', reviewDecision: 'allowed', reviewedBy: 'ann', reviewedAt: '2026-10-06T08:00:00Z' },
	]

	const sent = withDecision(flags, 'f1', 'annulled')

	assert.deepEqual(sent[0], { flagId: 'f1', kind: 'tab-hidden', reviewDecision: 'annulled' })
	assert.deepEqual(sent[1], flags[1], 'another flag changed')
	assert.equal(flags[0].reviewDecision, 'pending', 'the stored flags were changed in place')
})

test('open flags are the pending ones, and a flag without a decision is open', () => {
	assert.equal(openFlagCount({ flags: [{ reviewDecision: 'pending' }, {}, { reviewDecision: 'allowed' }] }), 2)
	assert.equal(openFlagCount({}), 0)
	assert.equal(openFlagCount(null), 0)
})

test('minutes into the attempt are rounded down, and absent without a start', () => {
	assert.equal(minutesIn('2026-10-06T10:14:59Z', '2026-10-06T10:02:00Z'), 12)
	assert.equal(minutesIn('2026-10-06T10:02:30Z', '2026-10-06T10:02:00Z'), 0)
	assert.equal(minutesIn('2026-10-06T10:14:00Z', null), null)
	assert.equal(minutesIn('2026-10-06T10:00:00Z', '2026-10-06T10:02:00Z'), null)
	assert.equal(minutesIn('not a date', '2026-10-06T10:02:00Z'), null)
})

test('a name falls back to the user id, never to an empty line', () => {
	assert.equal(nameOrId({ ann: 'Ann de Vries' }, 'ann'), 'Ann de Vries')
	assert.equal(nameOrId({ ann: '  ' }, 'ann'), 'ann')
	assert.equal(nameOrId({}, 'bob'), 'bob')
	assert.equal(nameOrId({}, null), '')
})
