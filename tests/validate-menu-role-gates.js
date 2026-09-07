#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// validate-menu-role-gates.js — keeps the navigation's role gates honest.
//
// Two failure modes, both silent without this gate:
//
//   1. A menu node ships with no `visibleIf` at all, so every signed-in user
//      sees it. The manifest was split into src/manifest.d/*.json fragments,
//      and seven fragments arrived carrying zero gates. Nothing noticed,
//      because a missing gate renders MORE nav rather than erroring.
//
//   2. A `visibleIf` names a role literal that
//      DashboardRoleService::resolvePrimaryRole() can never emit. A visibleIf
//      predicate is fail-safe by construction: a mismatch hides the entry
//      instead of erroring, so a typo silently removes a menu item forever.
//      This is the defect the `fix-dead-role-gates` change fixed by hand and
//      promised to gate. The gate never shipped, and the fragment split then
//      reintroduced failure mode 1 unnoticed.
//
// The role vocabulary is PARSED from DashboardRoleService.php rather than
// duplicated here, so the gate cannot drift from the resolver it checks.
//
// Usage:
//   node tests/validate-menu-role-gates.js
//
// Exit codes:
//   0 — every menu node is gated (or intentionally universal) and every role
//       literal is one the resolver can emit
//   1 — at least one violation, listed on stderr

'use strict'

const fs = require('fs')
const path = require('path')

const REPO_ROOT = path.resolve(__dirname, '..')
const MANIFEST = path.join(REPO_ROOT, 'src', 'manifest.json')
const FRAGMENT_DIR = path.join(REPO_ROOT, 'src', 'manifest.d')
const RESOLVER = path.join(REPO_ROOT, 'lib', 'Service', 'DashboardRoleService.php')

// Menu nodes that are deliberately visible to every signed-in user. A learner,
// a guardian and an instructor each have their own dashboard, their own
// timetable and their own learning record. Gating these empties the app for
// everyone who is not staff.
//
// Adding an id here is a deliberate product decision, not a way to silence the
// gate. Anything not listed must carry a visibleIf.
const UNIVERSAL = new Set([
	'Documentation',
	'Dashboard',
	'GroupMyLearning',
	'MyTimetableMenu',
	'MyLearningRecordMenu',
])

/**
 * Read the role literals resolvePrimaryRole() can actually return.
 *
 * Three sources, all in DashboardRoleService.php: the GROUP_BACKED_ROLES map
 * keys, the hardcoded 'admin' short-circuit, and the 'learner' fallback.
 *
 * @return {Set<string>} Every role literal the resolver can emit.
 */
function emittableRoles() {
	const src = fs.readFileSync(RESOLVER, 'utf8')

	const block = src.match(/GROUP_BACKED_ROLES\s*=\s*\[([\s\S]*?)\];/)
	if (block === null) {
		console.error(
			'FAIL: could not find GROUP_BACKED_ROLES in '
				+ path.relative(REPO_ROOT, RESOLVER)
				+ '\n      The resolver changed shape. Update this gate rather than deleting it.',
		)
		process.exit(1)
	}

	const roles = new Set(['admin', 'learner'])
	for (const m of block[1].matchAll(/'([a-z-]+)'\s*=>/g)) {
		roles.add(m[1])
	}
	return roles
}

/**
 * Collect every menu node across the manifest and its fragments.
 *
 * @return {Array<object>} One entry per node: { file, id, visibleIf }.
 */
function collectMenuNodes() {
	const files = [MANIFEST]
	if (fs.existsSync(FRAGMENT_DIR)) {
		for (const name of fs.readdirSync(FRAGMENT_DIR).sort()) {
			if (name.endsWith('.json')) {
				files.push(path.join(FRAGMENT_DIR, name))
			}
		}
	}

	const nodes = []
	for (const file of files) {
		const doc = JSON.parse(fs.readFileSync(file, 'utf8'))
		const menu = doc.menu
		const roots = Array.isArray(menu)
			? menu
			: (menu && (menu.items || menu.main)) || []
		const walk = (items) => {
			for (const item of items || []) {
				nodes.push({
					file: path.relative(REPO_ROOT, file),
					id: item.id,
					visibleIf: item.visibleIf,
				})
				walk(item.children || item.items)
			}
		}
		walk(roots)
	}
	return nodes
}

const roles = emittableRoles()
const nodes = collectMenuNodes()
const ungated = []
const badLiterals = []

for (const node of nodes) {
	if (!node.visibleIf) {
		if (!UNIVERSAL.has(node.id)) {
			ungated.push(node)
		}
		continue
	}

	const predicate = node.visibleIf['user.primaryRole']
	const named = (predicate && predicate.in) || []
	for (const role of named) {
		if (!roles.has(role)) {
			badLiterals.push({ ...node, role })
		}
	}
}

if (ungated.length > 0) {
	console.error(
		`FAIL: ${ungated.length} menu node(s) carry no visibleIf and are not on the UNIVERSAL list.`,
	)
	console.error('      Every signed-in user sees these, whatever their role.\n')
	for (const n of ungated) {
		console.error(`        ${n.file}  ${n.id}`)
	}
	console.error('')
}

if (badLiterals.length > 0) {
	console.error(
		`FAIL: ${badLiterals.length} role literal(s) the resolver can never emit.`,
	)
	console.error(
		`      resolvePrimaryRole() can only return: ${[...roles].sort().join(', ')}`,
	)
	console.error(
		'      A literal outside that set hides the entry forever, silently.\n',
	)
	for (const n of badLiterals) {
		console.error(`        ${n.file}  ${n.id}  ->  "${n.role}"`)
	}
	console.error('')
}

if (ungated.length > 0 || badLiterals.length > 0) {
	process.exit(1)
}

console.log(
	`validate-menu-role-gates: OK — ${nodes.length} menu nodes, `
		+ `${nodes.length - ungated.length - UNIVERSAL.size} gated, `
		+ `${UNIVERSAL.size} intentionally universal, `
		+ `${roles.size} emittable roles.`,
)
