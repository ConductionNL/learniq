// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The staff lists that still read "po-leerling-147" after
// lists-read-pupil-names (ui-open, 2026-10-05): the index pages that declared
// no columns. An index page without columns shows every property of its
// schema, the pupil's user id among them, and the earlier guard only read
// declared columns, so it could not see these. Attendance flags, exam
// accommodations and BSA flags were the three reported; 38 more had the same
// shape.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
// @spec openspec/changes/remaining-lists-read-pupil-names/specs/school-structure/spec.md

import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { test } from 'node:test'

function readJson(rel) {
	return JSON.parse(readFileSync(new URL(rel, import.meta.url), 'utf8'))
}
const REGISTER = readJson('../../lib/Settings/learniq_register.json')
const NL = readJson('../../l10n/nl.json').translations
const SCHEMAS = {}
for (const [key, s] of Object.entries(REGISTER.components.schemas)) {
	SCHEMAS[key] = s
	SCHEMAS[s.slug] = s
	SCHEMAS[s.title] = s
}
const MANIFEST_DIR = new URL('../../src/manifest.d/', import.meta.url)
const INDEX_PAGES = readdirSync(MANIFEST_DIR)
	.filter((f) => f.endsWith('.json'))
	.flatMap((f) => JSON.parse(readFileSync(new URL(f, MANIFEST_DIR), 'utf8')).pages || [])
	.filter((page) => page.type === 'index' && SCHEMAS[page.config?.schema])

const keyOf = (c) => (typeof c === 'string' ? c : c.key)
const page = (id) => INDEX_PAGES.find((p) => p.id === id)

/** The fields that hold a pupil's Nextcloud user id, one or a list. */
const USER_ID_KEYS = ['learnerId', 'learnerIds', 'affectedLearnerIds', 'checkedLearnerId']
/** The fields that repeat the pupil next to a learner profile uuid. */
const ACCOUNT_KEYS = ['learnerUserId', 'accusedLearnerUserId']

/**
 * The pupil fields of a schema a list would show as a code.
 *
 * `regulation-exemption.learnerId` is left out: the register calls it "the
 * learner profile of the person" without a format, and no row exists to say
 * what it holds.
 *
 * @param {object} schema The schema.
 * @return {{userIds: string[], profileRefs: string[], accounts: string[]}}
 */
function pupilFields(schema) {
	const props = schema.properties || {}
	const visible = (key) => props[key] && props[key].visible !== false
	const isProfile = (key) => props[key].$ref === 'LearnerProfile'
	if (schema.slug === 'regulation-exemption') {
		return { userIds: [], profileRefs: [], accounts: [] }
	}
	return {
		userIds: USER_ID_KEYS.filter((key) => visible(key) && !isProfile(key)),
		profileRefs: ['learnerId', 'accusedLearnerId'].filter((key) => visible(key) && isProfile(key)),
		accounts: ACCOUNT_KEYS.filter(visible),
	}
}

test('an index page on a schema with a pupil user id declares its columns', () => {
	const undeclared = INDEX_PAGES
		.filter((p) => {
			const fields = pupilFields(SCHEMAS[p.config.schema])
			return fields.userIds.length + fields.accounts.length > 0
		})
		.filter((p) => !Array.isArray(p.config.columns) || p.config.columns.length === 0)
		.map((p) => p.id)
	assert.deepEqual(undeclared, [], 'without columns the list shows every property, the user id too')
})

test('no index page shows a pupil as a user id', () => {
	const bare = []
	for (const p of INDEX_PAGES) {
		const fields = pupilFields(SCHEMAS[p.config.schema])
		for (const c of p.config.columns || []) {
			const key = keyOf(c)
			if (fields.userIds.includes(key) && (typeof c === 'string' || c.widget !== 'learnerName')) {
				bare.push(`${p.id}:${key}`)
			}
			if (fields.accounts.includes(key)) {
				bare.push(`${p.id}:${key}`)
			}
			const resolves = typeof c !== 'string' && c.widget === 'fkResolve'
				&& c.widgetProps && c.widgetProps.schema === 'learner-profile'
			if (fields.profileRefs.includes(key) && !resolves) {
				bare.push(`${p.id}:${key}`)
			}
		}
	}
	assert.deepEqual(bare, [])
})

test('the three reported lists lead with the pupil and read as a teacher reads them', () => {
	const expected = {
		AttendanceFlags: ['learnerId', 'cohortId', 'flagKind', 'metricValue', 'windowStart', 'windowEnd', 'reportDeadlineAt', 'reportOverdue', 'lifecycle'],
		ExamAccommodations: ['learnerId', 'accommodationKind', 'value', 'assessmentId', 'lifecycle'],
		BsaProgressFlags: ['learnerId', 'programmeId', 'academicYear', 'ectsEarned', 'ectsRequiredAtCheck', 'flaggedAt', 'lifecycle'],
	}
	for (const [id, keys] of Object.entries(expected)) {
		const columns = page(id).config.columns
		assert.deepEqual(columns.map(keyOf), keys, id)
		assert.deepEqual(columns[0], { key: 'learnerId', label: 'Learner', widget: 'learnerName' }, id)
		const props = SCHEMAS[page(id).config.schema].properties
		for (const c of columns) {
			if (props[keyOf(c)].format === 'uuid') {
				assert.equal(c.widget, 'fkResolve', `${id}: ${keyOf(c)} reads by name`)
			}
		}
	}
})

test('every declared column is a property the list may show', () => {
	for (const p of INDEX_PAGES) {
		const props = SCHEMAS[p.config.schema].properties
		const keys = (p.config.columns || []).map(keyOf)
		assert.equal(new Set(keys).size, keys.length, `${p.id} names a column twice`)
		for (const c of p.config.columns || []) {
			// A column may also name row metadata or carry its own data
			// (aggregate, formatter); only a plain key must be a property.
			if (typeof c === 'string') {
				assert.ok(props[c], `${p.id}: ${c} is not on ${p.config.schema}`)
				assert.notEqual(props[c].visible, false, `${p.id}: ${c} is hidden`)
				assert.notEqual(c, 'tenant_id', `${p.id} shows the tenant id`)
			}
		}
	}
})

test('a list that only gained the pupil name kept its other columns', () => {
	// The 38 pages beyond the three reported ones show what they showed,
	// less the tenant id and the fields that repeat the pupil.
	const repeats = ['tenant_id', 'learnerRef', 'learnerRefs', ...ACCOUNT_KEYS]
	for (const id of ['DossierNotes', 'SupportRequests', 'Submissions', 'Sessions', 'Credentials', 'FraudCases']) {
		const schema = SCHEMAS[page(id).config.schema]
		const shown = Object.entries(schema.properties)
			.filter(([key, prop]) => prop.visible !== false && prop.type !== 'object' && !repeats.includes(key))
			.map(([key]) => key)
			.sort()
		assert.deepEqual(page(id).config.columns.map(keyOf).sort(), shown, id)
	}
})

test('every heading these lists add has a Dutch entry', () => {
	const missing = new Set()
	for (const p of INDEX_PAGES) {
		for (const c of p.config.columns || []) {
			if (typeof c !== 'string' && ['learnerName', 'fkResolve'].includes(c.widget) && c.label && !NL[c.label]) {
				missing.add(`${p.id}: ${c.label}`)
			}
		}
	}
	assert.deepEqual([...missing], [])
})
