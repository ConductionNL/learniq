// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Every staff list reads a pupil by name (lq-names-2, 2026-10-05). After
// pupils-read-by-name, the enrolments, grades, report cards, assessment
// results and signal lists still showed "po-leerling-147". Seven of those
// schemas have no learnerRef, and enrolments made through the form carry
// none, so these lists use learniq's `learnerName` cell: the profile through
// learnerRef when the row has one, by Nextcloud user id otherwise.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
// @spec openspec/changes/lists-read-pupil-names/specs/school-structure/spec.md

import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { test } from 'node:test'
import {
	createLearnerNameResolver,
	learnerLookups,
	profileName,
} from '../../src/utils/learnerName.js'

function readJson(rel) {
	return JSON.parse(readFileSync(new URL(rel, import.meta.url), 'utf8'))
}
const REGISTER = readJson('../../lib/Settings/learniq_register.json')
const NL = readJson('../../l10n/nl.json').translations
const SCHEMAS = Object.fromEntries(
	Object.values(REGISTER.components.schemas).flatMap((s) => [[s.slug, s], [s.title, s]]),
)
for (const [key, s] of Object.entries(REGISTER.components.schemas)) {
	SCHEMAS[key] = s
}
const MANIFEST_DIR = new URL('../../src/manifest.d/', import.meta.url)
const PAGES = readdirSync(MANIFEST_DIR)
	.filter((f) => f.endsWith('.json'))
	.flatMap((f) => JSON.parse(readFileSync(new URL(f, MANIFEST_DIR), 'utf8')).pages || [])

/**
 * Every list in the manifest: index pages and list widgets, with the schema
 * and the declared columns.
 *
 * @return {Array<{where: string, schema: string, columns: Array<object|string>|undefined}>}
 */
function allLists() {
	const out = []
	for (const page of PAGES) {
		const config = page.config || {}
		if (page.type === 'index') {
			out.push({ where: page.id, schema: config.schema, columns: config.columns })
		}
		for (const widget of config.widgets || []) {
			const content = widget.content || {}
			if (widget.type === 'object-list' || Array.isArray(content.columns)) {
				out.push({ where: `${page.id}/${widget.id}`, schema: content.schema, columns: content.columns })
			}
		}
	}
	return out
}

const keyOf = (c) => (typeof c === 'string' ? c : c.key)
const LEARNER_KEYS = ['learnerId', 'learnerIds']

// --- the cell's lookups -------------------------------------------------

test('a row with learnerRef is looked up by its profile', () => {
	assert.deepEqual(
		learnerLookups('po-leerling-147', { learnerId: 'po-leerling-147', learnerRef: 'ee01-415' }, 'learnerRef'),
		[{ ref: 'ee01-415', userId: 'po-leerling-147' }],
	)
})

test('a row without learnerRef is looked up by user id', () => {
	assert.deepEqual(
		learnerLookups('po-leerling-139', { learnerId: 'po-leerling-139' }, 'learnerRef'),
		[{ ref: '', userId: 'po-leerling-139' }],
	)
	assert.deepEqual(
		learnerLookups('po-leerling-139', { learnerId: 'po-leerling-139', learnerRef: null }, 'learnerRef'),
		[{ ref: '', userId: 'po-leerling-139' }],
	)
})

test('a group hand-in is looked up per pupil', () => {
	assert.deepEqual(
		learnerLookups(['u1', 'u2'], { learnerIds: ['u1', 'u2'], learnerRefs: ['r1', 'r2'] }, 'learnerRefs'),
		[{ ref: 'r1', userId: 'u1' }, { ref: 'r2', userId: 'u2' }],
	)
	assert.deepEqual(
		learnerLookups(['u1', 'u2'], { learnerIds: ['u1', 'u2'] }, 'learnerRefs'),
		[{ ref: '', userId: 'u1' }, { ref: '', userId: 'u2' }],
	)
})

test('an empty cell has no lookups', () => {
	assert.deepEqual(learnerLookups('', {}, 'learnerRef'), [])
	assert.deepEqual(learnerLookups(null, null, 'learnerRef'), [])
})

test('a profile reads by its given and family name', () => {
	assert.equal(profileName({ givenName: 'Vera', familyName: 'Hulstkamp' }), 'Vera Hulstkamp')
	assert.equal(profileName({ givenName: '', familyName: '', '@self': { name: 'Vera H.' } }), 'Vera H.')
	assert.equal(profileName(null), '')
})

// --- the resolver -------------------------------------------------------

function fakeFetch(byRef, byUser) {
	const calls = []
	const getJson = async (url) => {
		calls.push(url)
		const ref = url.match(/learner-profile\/([^?]+)$/)
		if (ref) {
			if (!byRef[ref[1]]) {
				throw new Error('404')
			}
			return byRef[ref[1]]
		}
		const user = url.match(/ncUserId=([^&]+)/)
		const hit = byUser[decodeURIComponent(user[1])]
		return { results: hit ? [hit] : [] }
	}
	const urlFor = (path, params = {}) => path.replace(/\{(\w+)\}/g, (_, k) => encodeURIComponent(params[k]))
	return { calls, getJson, urlFor }
}

const VERA = { givenName: 'Vera', familyName: 'Hulstkamp', ncUserId: 'po-leerling-147' }

test('the resolver names a pupil through learnerRef', async () => {
	const f = fakeFetch({ 'ee01-415': VERA }, {})
	const r = createLearnerNameResolver(f)
	assert.equal(await r.nameOf({ ref: 'ee01-415', userId: 'po-leerling-147' }), 'Vera Hulstkamp')
	assert.equal(f.calls.length, 1)
	assert.match(f.calls[0], /\/apps\/openregister\/api\/objects\/learniq\/learner-profile\/ee01-415$/)
})

test('the resolver names a pupil by user id when the row has no learnerRef', async () => {
	const f = fakeFetch({}, { 'po-leerling-147': VERA })
	const r = createLearnerNameResolver(f)
	assert.equal(await r.nameOf({ ref: '', userId: 'po-leerling-147' }), 'Vera Hulstkamp')
	assert.match(f.calls[0], /learner-profile\?ncUserId=po-leerling-147&_limit=1$/)
})

test('the resolver falls back to the user id when learnerRef does not resolve', async () => {
	const f = fakeFetch({}, { 'po-leerling-147': VERA })
	const r = createLearnerNameResolver(f)
	assert.equal(await r.nameOf({ ref: 'gone', userId: 'po-leerling-147' }), 'Vera Hulstkamp')
})

test('a pupil without a profile keeps the user id', async () => {
	const f = fakeFetch({}, {})
	const r = createLearnerNameResolver(f)
	assert.equal(await r.nameOf({ ref: '', userId: 'po-leerling-999' }), 'po-leerling-999')
})

test('each profile is fetched once, however many cells ask', async () => {
	const f = fakeFetch({}, { 'po-leerling-147': VERA })
	const r = createLearnerNameResolver(f)
	const names = await Promise.all([1, 2, 3].map(() => r.nameOf({ ref: '', userId: 'po-leerling-147' })))
	assert.deepEqual(names, ['Vera Hulstkamp', 'Vera Hulstkamp', 'Vera Hulstkamp'])
	assert.equal(f.calls.length, 1)
})

// --- the manifest -------------------------------------------------------

test('no staff list shows a bare learner id', () => {
	const bare = []
	for (const list of allLists()) {
		for (const c of list.columns || []) {
			if (!LEARNER_KEYS.includes(keyOf(c))) {
				continue
			}
			const prop = (SCHEMAS[list.schema] || { properties: {} }).properties[keyOf(c)] || {}
			// Outside training stores the profile uuid in learnerId itself.
			const profileRef = prop.$ref === 'LearnerProfile' && typeof c !== 'string'
				&& c.widget === 'fkResolve' && c.widgetProps && c.widgetProps.schema === 'learner-profile'
			if (typeof c === 'string' || (c.widget !== 'learnerName' && !profileRef)) {
				bare.push(`${list.where}:${keyOf(c)}`)
			}
		}
	}
	assert.deepEqual(bare, [])
})

test('every learnerName column reads its schema\'s own learner fields', () => {
	for (const list of allLists()) {
		for (const c of list.columns || []) {
			if (typeof c === 'string' || c.widget !== 'learnerName') {
				continue
			}
			const schema = SCHEMAS[list.schema]
			assert.ok(schema, `${list.where}: schema ${list.schema} not in the register`)
			assert.ok(c.key in schema.properties, `${list.where}: ${c.key} not on ${list.schema}`)
			const refField = c.widgetProps && c.widgetProps.refField
			if (refField) {
				assert.ok(refField in schema.properties, `${list.where}: ${refField} not on ${list.schema}`)
			}
			// One pupil reads "Learner"; a list of them may say what they are
			// to the row (remaining-lists-read-pupil-names).
			const labels = Array.isArray(schema.properties[c.key].items) || schema.properties[c.key].type === 'array'
				? ['Learner', 'Learners', 'Affected learners']
				: ['Learner']
			assert.ok(labels.includes(c.label), `${list.where}: ${c.key} is headed "${c.label}"`)
		}
	}
})

test('the four index pages declare columns without a uuid', () => {
	for (const id of ['Enrolments', 'GradeEntries', 'FinalGrades', 'AssessmentResults']) {
		const page = PAGES.find((p) => p.id === id)
		const columns = page.config.columns
		assert.ok(Array.isArray(columns) && columns.length > 0, `${id} declares columns`)
		const props = SCHEMAS[page.config.schema].properties
		for (const c of columns) {
			const prop = props[keyOf(c)]
			assert.ok(prop, `${id}: ${keyOf(c)} is a property`)
			if (prop.format === 'uuid') {
				assert.equal(c.widget, 'fkResolve', `${id}: uuid column ${keyOf(c)} resolves to a name`)
			}
		}
	}
})

test('no touched list shows a course or group as a uuid', () => {
	for (const list of allLists()) {
		const cols = list.columns || []
		if (!cols.some((c) => typeof c !== 'string' && c.widget === 'learnerName')) {
			continue
		}
		for (const c of cols) {
			if (['courseId', 'cohortId'].includes(keyOf(c))) {
				assert.equal(typeof c === 'string' ? null : c.widget, 'fkResolve', `${list.where}:${keyOf(c)}`)
			}
		}
	}
})

test('every heading has a Dutch entry', () => {
	const missing = new Set()
	for (const list of allLists()) {
		const cols = list.columns || []
		if (!cols.some((c) => typeof c !== 'string' && c.widget === 'learnerName')) {
			continue
		}
		for (const c of cols) {
			if (typeof c !== 'string' && c.label && !NL[c.label]) {
				missing.add(c.label)
			}
		}
	}
	assert.deepEqual([...missing], [])
})
