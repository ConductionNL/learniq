// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// A teacher reads a pupil by name, not by Nextcloud user id (lq-names,
// 2026-10-04). The learner profile schema had no display name, so a list of
// bookings, a round's slots, the absence reports and a lesson's attendance
// showed "po-leerling-147". The learner profile now names itself from its
// given and family name, and those lists resolve the pupil's profile to that
// name.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
// @spec openspec/changes/pupils-read-by-name/specs/school-structure/spec.md

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

function readJson(rel) {
	return JSON.parse(readFileSync(new URL(rel, import.meta.url), 'utf8'))
}
const REGISTER = readJson('../../lib/Settings/learniq_register.json')
const NL = readJson('../../l10n/nl.json').translations
const PO = readJson('../../lib/Settings/profiles/po.json')
const SCHEMAS = Object.fromEntries(
	Object.values(REGISTER.components.schemas).map((s) => [s.slug, s]),
)
const PAGES = [
	...readJson('../../src/manifest.d/guardian-meetings.json').pages,
	...readJson('../../src/manifest.d/people.json').pages,
	...readJson('../../src/manifest.d/learning.json').pages,
]
const page = (id) => PAGES.find((p) => p.id === id)

/**
 * The columns of one teacher list: an index page, or a list widget on a page.
 *
 * @param {string} pageId The page id.
 * @param {string|null} widgetId The widget id, null for an index page.
 * @return {{schema: string, columns: Array<object|string>}}
 */
function list(pageId, widgetId) {
	const config = page(pageId).config
	if (widgetId === null) {
		return { schema: config.schema, columns: config.columns || [] }
	}
	const content = config.widgets.find((w) => w.id === widgetId).content
	return { schema: content.schema, columns: content.columns || [] }
}

/**
 * The property names one objectNameField template reads.
 *
 * @param {string} template The template.
 * @return {string[]}
 */
function templateFields(template) {
	return [...template.matchAll(/\{\{\s*([^}|\s]+)\s*\}\}/g)].map((m) => m[1])
}

test('a learner profile names itself from the given and family name', () => {
	const profile = SCHEMAS['learner-profile']
	const template = (profile.configuration || {}).objectNameField
	assert.equal(template, '{{ givenName }} {{ familyName }}')
	for (const field of templateFields(template)) {
		assert.ok(
			field in profile.properties,
			`objectNameField reads ${field}, which learner-profile does not declare`,
		)
	}
})

test('every profile in the primary school set carries both names', () => {
	const profiles = PO['x-openregister'].seedData.objects['learner-profile']
	assert.ok(profiles.length > 0)
	for (const profile of profiles) {
		assert.ok(
			typeof profile.givenName === 'string' && profile.givenName !== '',
			`${profile.uuid} has no givenName`,
		)
		assert.ok(
			typeof profile.familyName === 'string' && profile.familyName !== '',
			`${profile.uuid} has no familyName`,
		)
	}
})

const TEACHER_LISTS = [
	['ConferenceRoundDetail', 'round-bookings'],
	['ConferenceRoundDetail', 'round-slots'],
	['ExcuseRequests', null],
	['AttendanceRecords', null],
	['SessionDetail', 'sess-attend'],
]

for (const [pageId, widgetId] of TEACHER_LISTS) {
	const name = widgetId === null ? pageId : `${pageId} ${widgetId}`

	test(`${name} shows the pupil by name, not by user id`, () => {
		const { schema, columns } = list(pageId, widgetId)
		assert.ok(columns.length > 0, `${name} declares no columns`)
		const keys = columns.map((c) => (typeof c === 'string' ? c : c.key))
		assert.equal(keys.includes('learnerId'), false, `${name} shows learnerId`)

		const pupil = columns.find(
			(c) => typeof c === 'object' && c.key === 'learnerRef',
		)
		assert.ok(pupil, `${name} has no pupil column`)
		assert.equal(SCHEMAS[schema].properties.learnerRef.$ref, 'LearnerProfile')
		assert.equal(pupil.widget, 'fkResolve')
		assert.deepEqual(pupil.widgetProps, {
			register: 'learniq',
			schema: 'learner-profile',
		})
	})

	test(`${name} names every column, in Dutch too`, () => {
		const { schema, columns } = list(pageId, widgetId)
		for (const column of columns) {
			assert.equal(typeof column, 'object', `${name} has a bare column`)
			assert.ok(
				column.key in SCHEMAS[schema].properties,
				`${name}.${column.key} is not on ${schema}`,
			)
			assert.ok(NL[column.label], `${name} "${column.label}" has no Dutch entry`)
		}
	})
}

test('every code a teacher list shows reads as a word, in Dutch too', () => {
	for (const [pageId, widgetId] of TEACHER_LISTS) {
		const { schema, columns } = list(pageId, widgetId)
		for (const column of columns) {
			const property = SCHEMAS[schema].properties[column.key]
			if (!Array.isArray(property.enum)) {
				continue
			}
			const labels = property['x-enum-labels'] || {}
			assert.deepEqual(
				Object.keys(labels),
				property.enum,
				`${pageId} ${column.key} has no label for every value`,
			)
			for (const label of Object.values(labels)) {
				assert.ok(NL[label], `${schema}.${column.key} "${label}" has no Dutch entry`)
			}
		}
	}
})
