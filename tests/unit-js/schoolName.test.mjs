// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// LqHrVandaag's subtitle ends with the school's name, read from the School row.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { roleLine, soleSchoolName } from '../../src/utils/schoolName.js'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const read = (path) => readFileSync(resolve(root, path), 'utf8')

test('one school gives its name', () => {
	assert.equal(soleSchoolName([{ name: ' Zuiddrecht College ' }]), 'Zuiddrecht College')
})

test('no school, several schools or no name give no name', () => {
	assert.equal(soleSchoolName([]), '')
	assert.equal(soleSchoolName(undefined), '')
	assert.equal(soleSchoolName([{ name: 'A' }, { name: 'B' }]), '')
	assert.equal(soleSchoolName([{}]), '')
})

test('a role without a text line has no line', () => {
	assert.equal(roleLine({ hr: 'A' }, 'hr'), 'A')
	assert.equal(roleLine({ hr: 'A' }, 'teacher'), '')
	assert.equal(roleLine(null, 'hr'), '')
	assert.equal(roleLine({ hr: 1 }, 'hr'), '')
})

test('the HR line names the school on every dashboard page, in Dutch', () => {
	const nl = JSON.parse(read('l10n/nl.json')).translations
	const pages = JSON.parse(read('src/manifest.d/dashboard.json')).pages.filter(
		(page) => ['Dashboard', 'DashboardAdmin', 'DashboardTeacher', 'DashboardStudent'].includes(page.id),
	)
	assert.equal(pages.length, 4)
	for (const page of pages) {
		const line = page.config.subtitleOfSchoolByRole.hr
		assert.equal(nl[line].replace('{school}', 'Zuiddrecht College'), 'hr-medewerker · schoolbreed, alle locaties van Zuiddrecht College', page.id)
		assert.ok(page.config.subtitleByRole.hr, 'the line without the name stays as the fallback')
	}
})

test('the dashboard only reads the school for a role whose line names it', () => {
	const source = read('src/views/LearniqDashboards.vue')
	assert.match(source, /if \(roleLine\(this\.subtitleOfSchoolByRole, role\) === ''\) \{\s+return/)
	assert.match(source, /soleSchoolName\(await this\.listObjects\('School', \{\}\)\)/)
})
