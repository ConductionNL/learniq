// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The teacher's conference and school calendar screens read words, not codes
// (lq-polish, 2026-10-03). The lists showed every schema property: tenant
// ids, uuids, the Nextcloud user ids of teacher and pupil, an ISO timestamp
// and a round status as stored ("booking-closed"). A slot page opened on
// "Acknowledged at", "Booked at" and "Conference Round ID". Each screen now
// names the columns and fields a teacher reads, every status has a label,
// and every label the screens show has a Dutch entry.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
// @spec openspec/changes/conference-screens-read-words/specs/parent-conferences/spec.md

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const readJson = (rel) =>
	JSON.parse(readFileSync(new URL(rel, import.meta.url), 'utf8'))
const REGISTER = readJson('../../lib/Settings/learniq_register.json')
const NL = readJson('../../l10n/nl.json').translations
const PAGES = [
	...readJson('../../src/manifest.d/guardian-meetings.json').pages,
	...readJson('../../src/manifest.d/school-calendar.json').pages,
]
const SCHEMAS = Object.fromEntries(
	Object.values(REGISTER.components.schemas).map((s) => [s.slug, s]),
)
const page = (id) => PAGES.find((p) => p.id === id)
const widget = (pageId, widgetId) =>
	page(pageId).config.widgets.find((w) => w.id === widgetId)

/**
 * Whether a property holds a code a teacher cannot read: the tenant, a uuid
 * reference, or a Nextcloud user id.
 *
 * @param {object} schema The schema.
 * @param {string} key The property.
 * @return {boolean}
 */
function isCode(schema, key) {
	const property = schema.properties[key] || {}
	return (
		key === 'tenant_id'
		|| property.format === 'uuid'
		|| /(Id|Ids|Ref|Refs)$/.test(key)
	)
}

for (const [pageId, schema] of [
	['ConferenceRounds', 'conference-round'],
	['ConferenceSlots', 'conference-slot'],
	['SchoolEvents', 'school-event'],
]) {
	test(`${pageId} names its columns and shows no codes`, () => {
		const columns = page(pageId).config.columns
		assert.ok(
			Array.isArray(columns) && columns.length > 0,
			`${pageId} declares no columns`,
		)
		for (const column of columns) {
			assert.ok(
				column.key in SCHEMAS[schema].properties,
				`${pageId}.${column.key} is not on ${schema}`,
			)
			assert.equal(
				isCode(SCHEMAS[schema], column.key),
				false,
				`${pageId} shows the code ${column.key}`,
			)
			assert.ok(
				NL[column.label],
				`${pageId} column "${column.label}" has no Dutch entry`,
			)
		}
	})
}

test('the school calendar reads its dates as dates', () => {
	const columns = Object.fromEntries(
		page('SchoolEvents').config.columns.map((c) => [c.key, c]),
	)
	assert.equal(columns.startsAt.formatter, 'date')
	assert.equal(columns.endsAt.formatter, 'date')
})

test('a slot page leads with what a teacher reads and leaves the codes out', () => {
	const include = widget('ConferenceSlotDetail', 'slot-data').content.include
	assert.deepEqual(include.slice(0, 3), ['slotLabel', 'teacherName', 'lifecycle'])
	const overrides = widget('ConferenceSlotDetail', 'slot-data').content.overrides
	assert.deepEqual(
		include.map((key) => overrides[key].order),
		include.map((_, i) => i),
		'the fields render in the order listed',
	)
	for (const key of [
		'tenant_id',
		'signupId',
		'eligibleLearnerRefs',
		'teacherId',
		'guardianRef',
	]) {
		assert.equal(include.includes(key), false, `the slot page shows ${key}`)
	}
	for (const key of include) {
		const title = SCHEMAS['conference-slot'].properties[key].title
		assert.ok(NL[title], `slot field title "${title}" has no Dutch entry`)
		assert.equal(
			/ ID$|Ref$| Time$/.test(title),
			false,
			`slot field title "${title}" reads like a code`,
		)
	}
})

test('a round page lists its slots by time and teacher name', () => {
	const keys = widget('ConferenceRoundDetail', 'round-slots').content.columns.map(
		(c) => c.key,
	)
	assert.equal(keys.includes('teacherId'), false)
	assert.ok(keys.includes('teacherName') && keys.includes('slotLabel'))
})

test('every status a conference screen shows has a label in Dutch', () => {
	for (const schema of [
		'conference-round',
		'conference-slot',
		'teacher-availability',
	]) {
		const lifecycle = SCHEMAS[schema].properties.lifecycle
		const labels = lifecycle['x-enum-labels'] || {}
		assert.deepEqual(
			Object.keys(labels),
			lifecycle.enum,
			`${schema}.lifecycle labels`,
		)
		for (const label of Object.values(labels)) {
			assert.ok(NL[label], `${schema} status "${label}" has no Dutch entry`)
		}
	}
})

test('the schemas a Related panel names read as words, in Dutch too', () => {
	for (const schema of [
		'learner-profile',
		'conference-round',
		'conference-slot',
		'conference-signup',
		'conference-report',
	]) {
		const title = SCHEMAS[schema].title
		assert.equal(/[a-z][A-Z]/.test(title), false, `${schema} title "${title}"`)
		assert.ok(NL[title], `${schema} title "${title}" has no Dutch entry`)
	}
})
