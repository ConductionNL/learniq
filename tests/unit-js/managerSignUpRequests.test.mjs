// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// A line manager may approve a report's pending self sign-up (the Enrolment
// update rule matches managerId), but has no staff group, so their primary
// role is `learner` and the role-gated "Sign-up requests" entry never showed.
// Found live on 2026-09-29. PageController now publishes `managesLearners`
// and a second entry gates on it. The menu is built with the library's own
// buildManifest() and the app's menu-layout.json, as src/main.js does, and
// every entry is checked with the library's own visibleIf evaluator.
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
const FRAGMENTS = readdirSync(dir)
	.filter((n) => n.endsWith('.json'))
	.sort()
	.map((name) => readJson(new URL(name, dir)))

/**
 * The visible menu entries that open the pending self sign-ups.
 *
 * @param {object} user The runtime user.
 * @return {Array<string>} Their ids.
 */
function signUpEntries(user) {
	const base = structuredClone(BASE)
	const runtime = {
		user,
		workspace: buildWorkspaceRuntime(undefined, 'corporate', null),
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
			if (
				visible
				&& node.route === 'Enrolments'
				&& node.query?.source === 'self'
				&& node.query?.lifecycle === 'pending'
			) {
				found.push(node.id)
			}
			walk(node.children, visible)
		}
	}
	walk(manifest.menu, true)
	return found
}

test('a line manager without a staff role sees the sign-up requests', () => {
	assert.deepEqual(
		signUpEntries({ primaryRole: 'learner', managesLearners: true }),
		['ManagerSignUpRequestsMenu'],
	)
})

test('a learner who manages nobody does not', () => {
	assert.deepEqual(
		signUpEntries({ primaryRole: 'learner', managesLearners: false }),
		[],
	)
})

test('staff who also manage someone see one entry, not two', () => {
	for (const primaryRole of [
		'instructor',
		'coordinator',
		'hr',
		'administration-manager',
	]) {
		assert.deepEqual(
			signUpEntries({ primaryRole, managesLearners: true }),
			['SignUpRequestsMenu'],
			primaryRole,
		)
	}
})

// The admin sees every surface (segmentMenuGates.test.mjs relies on it), so
// an admin who is also a line manager gets both entries.
test('an admin who manages someone sees both entries', () => {
	assert.deepEqual(
		signUpEntries({ primaryRole: 'admin', managesLearners: true }).sort(),
		['ManagerSignUpRequestsMenu', 'SignUpRequestsMenu'],
	)
})

// GroupPeople's gate leaves team-lead out, so SignUpRequestsMenu never
// reaches a team lead even though it lists the role. A team lead who manages
// someone gets the My learning entry instead.
test('a team lead who manages someone gets the My learning entry', () => {
	assert.deepEqual(
		signUpEntries({ primaryRole: 'team-lead', managesLearners: true }),
		['ManagerSignUpRequestsMenu'],
	)
})

test('main.js publishes the flag PageController provides', () => {
	const main = readFileSync(new URL('../../src/main.js', import.meta.url), 'utf8')
	assert.match(
		main,
		/managesLearners: loadState\('learniq', 'managesLearners', false\) === true/,
	)
	const controller = readFileSync(
		new URL('../../lib/Controller/PageController.php', import.meta.url),
		'utf8',
	)
	assert.match(controller, /provideInitialState\('managesLearners'/)
})
