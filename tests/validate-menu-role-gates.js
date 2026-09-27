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
// A third failure mode arrived with segment-menu-gating: a `workspace.segment`
// predicate. Two rules, both checked here:
//   3. Every segment literal is one of LearniqSettings.segment's enum values
//      (parsed from learniq_register.json), for the same reason as role
//      literals: a typo hides the entry for everyone, silently.
//   4. Every segment gate keeps `corporate` visible. `corporate` is the
//      default every existing install has (segment-feature-flags Decision 2),
//      so a gate that hides an entry from `corporate` takes a menu away from
//      customers who never chose a segment.
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
const REGISTER = path.join(REPO_ROOT, 'lib', 'Settings', 'learniq_register.json')
const DEFAULT_SEGMENT = 'corporate'

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
 * Every segment code LearniqSettings accepts, from the schema enum.
 *
 * @return {Set<string>} The segment codes.
 */
function segmentCodes() {
	const register = JSON.parse(fs.readFileSync(REGISTER, 'utf8'))
	const segment = register.components.schemas.LearniqSettings.properties.segment
	return new Set(segment.enum)
}

/**
 * Why a `workspace.segment` predicate breaks rule 3 or 4, or null.
 *
 * @param {object} predicate The predicate expression.
 * @param {Set<string>} codes The known segment codes.
 * @return {string|null} The reason, or null when the predicate is fine.
 */
function segmentProblem(predicate, codes) {
	if (typeof predicate !== 'object' || predicate === null) {
		return codes.has(predicate) && predicate === DEFAULT_SEGMENT
			? null
			: `shorthand "${predicate}" hides the entry from "${DEFAULT_SEGMENT}"; use { in: [...] }`
	}
	const listed = [...(predicate.in || []), ...(predicate.notIn || [])]
	if (predicate.eq !== undefined) listed.push(predicate.eq)
	const unknown = listed.filter((code) => !codes.has(code))
	if (unknown.length > 0) {
		return `unknown segment literal(s): ${unknown.join(', ')}`
	}
	if (Array.isArray(predicate.in) && !predicate.in.includes(DEFAULT_SEGMENT)) {
		return `"in" omits "${DEFAULT_SEGMENT}", the default of every existing install`
	}
	if (
		Array.isArray(predicate.notIn)
		&& predicate.notIn.includes(DEFAULT_SEGMENT)
	) {
		return `"notIn" names "${DEFAULT_SEGMENT}", the default of every existing install`
	}
	if (predicate.eq !== undefined && predicate.eq !== DEFAULT_SEGMENT) {
		return `"eq" hides the entry from "${DEFAULT_SEGMENT}"`
	}
	return null
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
const codes = segmentCodes()
const nodes = collectMenuNodes()
const ungated = []
const badLiterals = []
const badSegmentGates = []
let segmentGated = 0

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

	if (Object.hasOwn(node.visibleIf, 'workspace.segment')) {
		segmentGated++
		const problem = segmentProblem(node.visibleIf['workspace.segment'], codes)
		if (problem !== null) {
			badSegmentGates.push({ ...node, problem })
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

if (badSegmentGates.length > 0) {
	console.error(
		`FAIL: ${badSegmentGates.length} workspace.segment gate(s) break the segment rules.`,
	)
	console.error(
		`      Known segments: ${[...codes].join(', ')}. Every gate must keep "${DEFAULT_SEGMENT}" visible.\n`,
	)
	for (const n of badSegmentGates) {
		console.error(`        ${n.file}  ${n.id}  ->  ${n.problem}`)
	}
	console.error('')
}

if (ungated.length > 0 || badLiterals.length > 0 || badSegmentGates.length > 0) {
	process.exit(1)
}

console.log(
	`validate-menu-role-gates: OK — ${nodes.length} menu nodes, `
		+ `${nodes.length - ungated.length - UNIVERSAL.size} gated, `
		+ `${UNIVERSAL.size} intentionally universal, `
		+ `${roles.size} emittable roles, `
		+ `${segmentGated} segment-gated (each keeps "${DEFAULT_SEGMENT}").`,
)
