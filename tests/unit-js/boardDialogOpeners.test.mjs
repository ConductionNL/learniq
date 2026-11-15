// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The board dialogs open from the list and detail pages that draw them: an
// index create opens its dialog through `config.createModal`, a row or header
// action through `type: "open-modal"`. A target that names no registry modal
// opens nothing and logs nothing, so every opener is checked against
// src/registry.js, and every status-gated opener against the schema's states.
//
// @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component

import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { test } from 'node:test'

function readJson(rel) {
	return JSON.parse(readFileSync(new URL(rel, import.meta.url), 'utf8'))
}

const registry = readFileSync(new URL('../../src/registry.js', import.meta.url), 'utf8')
const REGISTER = readJson('../../lib/Settings/learniq_register.json')
const SCHEMAS = {}
for (const [key, s] of Object.entries(REGISTER.components.schemas)) {
	SCHEMAS[key] = s
	SCHEMAS[s.slug] = s
}
const EN = readJson('../../l10n/en.json').translations
const NL = readJson('../../l10n/nl.json').translations
const MANIFEST_DIR = new URL('../../src/manifest.d/', import.meta.url)
const PAGES = readdirSync(MANIFEST_DIR)
	.filter((f) => f.endsWith('.json'))
	.flatMap((f) => readJson(`../../src/manifest.d/${f}`).pages || [])
const page = (id) => PAGES.find((p) => p.id === id)
const isModal = (name) => new RegExp(`\\t${name}: \\{\\n\\t\\tkind: 'modal',`).test(registry)

const CREATES = {
	ReportConcern: 'ConcernReportDialog',
	ConfidentialNotes: 'ConfidentialNoteCreateDialog',
	AccessibilityFeedbacks: 'AccessibilityBarrierDialog',
	Cohorts: 'CohortCreateDialog',
	GradeEntries: 'GradeEntryCreateDialog',
	DossierNotes: 'DossierNoteCreateDialog',
	ConferenceReports: 'ConferenceReportDialog',
}

const ROW_ACTIONS = {
	Credentials: ['CredentialRevokeDialog', ['issued']],
	ExemptionCases: ['ExemptionDecisionDialog', ['submitted', 'in-assessment']],
	RegulationExemptions: ['RegulationExemptionDecisionDialog', ['requested']],
	Regulations: ['GroupEnrolDialog', null],
}

const HEADER_ACTIONS = {
	CredentialDetail: [['CredentialRevokeDialog', { objectId: '@objectId' }, ['issued']]],
	EnrolmentDetail: [['EnrolmentWithdrawDialog', { objectId: '@objectId' }, ['pending', 'active']]],
	RegulationExemptionDetail: [['RegulationExemptionDecisionDialog', { objectId: '@objectId' }, ['requested']]],
	ConferenceRoundDetail: [['TeacherAvailabilityDialog', { conferenceRoundId: '@objectId' }, null]],
	LearnerProfileDetail: [
		['DossierNoteCreateDialog', { learnerId: '@object.ncUserId' }, null],
		['ConferenceReportDialog', { learnerId: '@object.ncUserId' }, null],
	],
}

const WIDGET_ROW_ACTIONS = [
	['LearnerProfileDetail', 'lprof-enrol', 'EnrolmentWithdrawDialog', ['pending', 'active']],
	['AssignmentDetail', 'asn-subs', 'SubmissionReopenDialog', ['returned']],
]

/**
 * The states a status-gated action shows in.
 *
 * @param {object} when The action's visibleWhen.
 * @return {Array<string>} The lifecycle values.
 */
function statesOf(when) {
	assert.equal(when.field, 'lifecycle')
	return when.op === 'in' ? when.value : [when.value]
}

/**
 * Assert an opener's label reads in Dutch and its states exist on the schema.
 *
 * @param {object} action The manifest action.
 * @param {string} schema The schema the page reads.
 * @param {Array<string>|null} states The lifecycle values it shows in.
 */
function checkOpener(action, schema, states) {
	assert.ok(EN[action.label], `en has "${action.label}"`)
	assert.ok(NL[action.label] && NL[action.label] !== action.label, `nl translates "${action.label}"`)
	if (states === null) {
		assert.equal(action.visibleWhen, undefined)
		return
	}
	assert.deepEqual(statesOf(action.visibleWhen), states)
	const known = SCHEMAS[schema].properties.lifecycle.enum
	for (const state of states) {
		assert.ok(known.includes(state), `${schema} has state ${state}`)
	}
}

for (const [pageId, dialog] of Object.entries(CREATES)) {
	test(`${pageId} creates through ${dialog}`, () => {
		const p = page(pageId)
		assert.equal(p.type, 'index')
		assert.equal(p.config.createModal, dialog)
		assert.notEqual(p.config.showAdd, false)
		assert.ok(isModal(dialog))
	})
}

for (const [pageId, [dialog, states]] of Object.entries(ROW_ACTIONS)) {
	test(`${pageId} rows open ${dialog}`, () => {
		const p = page(pageId)
		const action = p.config.actions.find((a) => a.target === dialog)
		assert.equal(action.type, 'open-modal')
		assert.ok(isModal(dialog))
		checkOpener(action, p.config.schema, states)
	})
}

for (const [pageId, openers] of Object.entries(HEADER_ACTIONS)) {
	test(`${pageId} header opens ${openers.map(([d]) => d).join(', ')}`, () => {
		const p = page(pageId)
		for (const [dialog, props, states] of openers) {
			const action = p.config.headerActions.find((a) => a.target === dialog)
			assert.equal(action.type, 'open-modal')
			assert.deepEqual(action.props, props)
			assert.ok(isModal(dialog))
			checkOpener(action, p.config.schema, states)
		}
	})
}

for (const [pageId, widgetId, dialog, states] of WIDGET_ROW_ACTIONS) {
	test(`${pageId} ${widgetId} rows open ${dialog} with the row id`, () => {
		const widget = page(pageId).config.widgets.find((w) => w.id === widgetId)
		const action = widget.content.rowActions.find((a) => a.target === dialog)
		assert.equal(action.type, 'open-modal')
		assert.deepEqual(action.props, { objectId: '@row.id' })
		assert.ok(isModal(dialog))
		checkOpener(action, widget.content.schema, states)
	})
}

test('Zorg melden on the pupil page opens the concern dialog with the pupil', () => {
	const layout = readJson('../../src/menu-layout.simple.json')
	const overlay = layout.pages.find((p) => p.id === 'LearnerProfileDetail')
	const action = overlay.configAppend.headerActions.find((a) => a.id === 'report-a-concern')
	assert.equal(action.type, 'open-modal')
	assert.equal(action.target, 'ConcernReportDialog')
	assert.deepEqual(action.props, { learnerId: '@objectId' })
	assert.equal(NL[action.label], 'Zorg melden')
	assert.ok(isModal('ConcernReportDialog'))
})
