// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Static guard: every hand-built OpenRegister object URL in src/ must name a
// schema OpenRegister can actually resolve.
//
// THE BUG THIS EXISTS FOR. PupilDossierTimelineView fetched
// `/api/objects/learniq/BehaviourIncident` — the schema's TITLE. OpenRegister
// resolves the `{schema}` segment by uuid, numeric id, or case-insensitive
// SLUG (SchemaMapper::findInIds) and by nothing else, and that schema's slug is
// `behaviour-incident`. All six of that view's fetches 404'd, so the app's one
// genuine custom page had never displayed a timeline.
//
// WHY IT WENT UNNOTICED FOR SO LONG. `lower(slug)` matching means a
// SINGLE-WORD title accidentally works: `Course` lowercases to `course`, which
// IS the slug. Twelve such references exist in this repo and every one of them
// resolves. Only a multi-word title breaks — `BehaviourIncident` lowercases to
// `behaviourincident`, which matches nothing. The rule therefore looks fine
// almost everywhere it is applied wrongly, which is precisely why it needs a
// check rather than a convention.
//
// WHERE THE CHECK HAD TO LOOK, and why the obvious version was useless. The
// first version of this guard scanned `api/objects/learniq/<literal>` URLs —
// and PASSED on the broken code. Every broken call site goes through a helper
// whose URL is `api/objects/learniq/${schema}`, so the identifier is an
// ARGUMENT, never a literal in the URL. A check aimed at the wrong population
// reports the same thing whether or not the bug is present. So this scans the
// two places the identifier is actually written: arguments to `fetch*(` calls,
// and `schema:` property values.
//
// WHAT THIS DOES NOT COVER, stated plainly: an identifier that reaches a
// helper through a variable is invisible here (`fetchList(kind, …)` is
// correct-by-construction but unprovable statically), and a helper not named
// `fetch*` would be missed.

import assert from 'node:assert/strict'
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const ROOT = fileURLToPath(new URL('../../', import.meta.url))

/** Lines that are documentation, not code — endpoint lists at file tops. */
const DOC_LINE = /^\s*([-*]|\/\/|\/\*|<!--)/

/** A literal schema segment written straight into a URL. */
const OBJECT_URL = /api\/objects\/learniq\/([A-Za-z0-9_-]+)/g

/**
 * The identifier as an ARGUMENT — `fetchObject('X', …)`, `fetchList('X', …)`,
 * `fetchSchema('X', …)`. Matches across a line break, because the repo's
 * formatter puts the argument on its own line whenever the call wraps, which
 * is most of them.
 */
const FETCH_ARG = /\bfetch[A-Za-z]*\(\s*['"]([A-Za-z0-9_-]+)['"]/g

/** The identifier as a config value — `schema: 'X'`. */
const SCHEMA_KEY = /\bschema:\s*['"]([A-Za-z0-9_-]+)['"]/g

function walk(dir, out = []) {
	for (const entry of readdirSync(dir)) {
		const full = join(dir, entry)
		if (statSync(full).isDirectory()) {
			if (entry !== 'node_modules') walk(full, out)
		} else if (/\.(vue|js)$/.test(entry)) {
			out.push(full)
		}
	}
	return out
}

function registerSlugs() {
	const register = JSON.parse(
		readFileSync(join(ROOT, 'lib/Settings/learniq_register.json'), 'utf8'),
	)
	const slugs = new Set()
	for (const schema of Object.values(register.components.schemas)) {
		if (schema && typeof schema.slug === 'string')
			slugs.add(schema.slug.toLowerCase())
	}
	return slugs
}

function literalSchemaRefs() {
	const refs = []
	for (const file of walk(join(ROOT, 'src'))) {
		const text = readFileSync(file, 'utf8')
		const lines = text.split('\n')
		// Offset → line number, so a pattern that spans a wrapped call can
		// still report where it is and still be filtered by DOC_LINE.
		const lineAt = (offset) => text.slice(0, offset).split('\n').length
		for (const pattern of [OBJECT_URL, SCHEMA_KEY, FETCH_ARG]) {
			for (const match of text.matchAll(pattern)) {
				const line = lineAt(match.index)
				if (DOC_LINE.test(lines[line - 1])) continue
				refs.push({ file: relative(ROOT, file), line, ref: match[1] })
			}
		}
	}
	return refs
}

test('the register defines slugs for every schema, so the guard has something to check', () => {
	const slugs = registerSlugs()
	// Guards the guard: if the register file moved or changed shape, an empty
	// slug set would make every assertion below pass vacuously.
	assert.ok(slugs.size > 100, `expected >100 schema slugs, got ${slugs.size}`)
})

test('src/ contains hand-built object URLs, so this file is testing something', () => {
	// Same reason: a regex that stops matching would turn this suite green
	// while checking nothing at all.
	assert.ok(literalSchemaRefs().length > 20)
})

test('every literal schema segment resolves as a case-insensitive slug', () => {
	const slugs = registerSlugs()
	const broken = literalSchemaRefs().filter((r) => !slugs.has(r.ref.toLowerCase()))

	assert.deepEqual(
		broken.map((r) => `${r.file}:${r.line} → ${r.ref}`),
		[],
		'These reference a schema OpenRegister cannot resolve — it matches uuid, '
			+ 'numeric id, or lower(slug) only, so a multi-word TITLE such as '
			+ '"BehaviourIncident" 404s where "behaviour-incident" succeeds.',
	)
})

test('a multi-word title is rejected by the same rule the guard applies', () => {
	// Pins the asymmetry the bug depended on, so a future reader does not
	// "simplify" the check into a case-insensitive title comparison.
	const slugs = registerSlugs()
	assert.ok(slugs.has('behaviour-incident'), 'slug should be present')
	assert.ok(
		!slugs.has('behaviourincident'),
		'the lowercased TITLE must not resolve',
	)
	assert.ok(
		slugs.has('course'),
		'a single-word title lowercases onto its own slug',
	)
})
