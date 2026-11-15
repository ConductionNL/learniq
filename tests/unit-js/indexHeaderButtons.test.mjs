// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The learniq list boards draw Downloaden and the page's primary button
// beside the title (LqCijfers "Cijfer invoeren", LqGroepen "Nieuwe groep"),
// three of them also an Acties menu (LqAanwezigheidLijst, LqGroepen,
// LqLeerlingen). Without `headerButtons` nextcloud-vue keeps those in the
// toolbar under the title, so the header showed one button and the toolbar
// six. Each list declares its header buttons as its board draws them, and
// every label is a catalogue key with a Dutch translation.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')

/** Page id -> [manifest.d fragment, header button actions in order, primary label]. */
const lists = {
	AttendanceRecords: ['people.json', ['export', 'actions-menu', 'add'], 'Add registration'],
	BpvPlacements: ['work-placement.json', ['export', 'add'], 'Propose placement'],
	AccessibilityLimitations: ['dashboard.json', ['export', 'add'], 'New limitation'],
	PartnerApprovalJobs: ['compliance.json', ['export', 'add'], 'New connection'],
	DataSubjectRequests: ['compliance.json', ['export', 'add'], 'New request'],
	Regulations: ['dashboard.json', ['export', 'add'], 'Add regulation'],
	GradeEntries: ['learning.json', ['export', 'add'], 'Enter grade'],
	Applications: ['admissions.json', ['export', 'add'], 'New application'],
	DossierNotes: ['pupil-record.json', ['export', 'add'], 'New note'],
	Cohorts: ['learning.json', ['export', 'actions-menu', 'add'], 'New group'],
	LearnerProfiles: ['people.json', ['export', 'actions-menu', 'add'], 'New learner'],
	ConfidentialNotes: ['confidential-counsel.json', ['export', 'add'], 'New note'],
	ConcernReports: ['confidential-counsel.json', ['export'], null],
}

const nl = JSON.parse(readFileSync(resolve(root, 'l10n/nl.json'), 'utf8')).translations

for (const [id, [fragment, actions, primary]] of Object.entries(lists)) {
	test(`${id} draws its board's header buttons`, () => {
		const pages = JSON.parse(readFileSync(resolve(root, 'src/manifest.d', fragment), 'utf8')).pages
		const page = pages.find((p) => p.id === id)
		assert.ok(page, `${id} exists in ${fragment}`)
		assert.equal(page.config.showTitle, true, 'header buttons show only with the title')
		const buttons = page.config.headerButtons
		assert.deepEqual(buttons.map((b) => b.action), actions)
		const add = buttons.find((b) => b.action === 'add')
		if (primary) {
			assert.equal(add.label, primary)
			assert.equal(add.variant, 'primary')
		} else {
			assert.equal(add, undefined)
		}
		for (const button of buttons) {
			assert.ok(nl[button.label], `"${button.label}" has a Dutch translation`)
		}
	})
}
