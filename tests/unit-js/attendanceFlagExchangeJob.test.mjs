// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// attendance-flag-report-actions 1.4 (design D4): the attendance flag page
// shows the exchange job its report goes through, read from integriq by the
// flag's dataExchangeJobId, with the job's exchangeStatus. "Mark as reported"
// waits on that status (AttendanceFlagReportGuard), so the reader sees what
// it waits on. A flag without a linked job shows the empty text.
//
// @spec openspec/changes/attendance-flag-report-actions/specs/attendance/spec.md#requirement-marking-a-flag-as-reported-waits-for-the-exchange
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

function readJson (rel) {
	return JSON.parse(readFileSync(new URL(rel, import.meta.url), 'utf8'))
}
const PEOPLE = readJson('../../src/manifest.d/people.json').pages
const DATA_EXCHANGE = readJson('../../src/manifest.d/data-exchange.json').pages
const REGISTER = readJson('../../lib/Settings/learniq_register.json')
const NL = readJson('../../l10n/nl.json').translations
const EN = readJson('../../l10n/en.json').translations

const page = PEOPLE.find((p) => p.id === 'AttendanceFlagDetail')
const widget = page.config.widgets.find((w) => w.id === 'flag-exchange-job')

test('the flag page has a card for the linked exchange job', () => {
	assert.ok(widget, 'flag-exchange-job widget is declared')
	assert.equal(widget.type, 'facts')
})

test('the card reads the integriq job the flag links, by its id', () => {
	const jobs = DATA_EXCHANGE.find((p) => p.id === 'ExchangeJobs').config
	assert.deepEqual(widget.content.source, {
		register: jobs.register,
		schema: jobs.schema,
		id: '@object.dataExchangeJobId',
	})
	const flag = Object.values(REGISTER.components.schemas).find((s) => s.slug === 'attendance-flag')
	assert.ok(flag.properties.dataExchangeJobId, 'attendance-flag carries dataExchangeJobId')
})

test('the card shows the exchange status the report waits on', () => {
	const paths = widget.content.fields.map((f) => f.path)
	assert.equal(paths[0], 'exchangeStatus', 'the status comes first')
	assert.ok(paths.includes('exchangeError'), 'a failed exchange says why')
})

test('a flag without a linked job reads a sentence, not an empty card', () => {
	assert.equal(typeof widget.content.emptyText, 'string')
	assert.notEqual(widget.content.emptyText, '')
})

test('the card sits on the Overview tab', () => {
	const tabs = page.config.widgets.find((w) => w.id === 'board-tabs').content.tabs
	const overview = tabs.find((t) => t.label === 'Overview')
	assert.ok(overview.widgetIds.includes('flag-exchange-job'))
})

test('the card reads in Dutch and English', () => {
	const texts = [widget.title, widget.content.emptyText, ...widget.content.fields.map((f) => f.label)]
	for (const text of texts) {
		assert.ok(NL[text], `"${text}" has no Dutch entry`)
		assert.ok(EN[text], `"${text}" has no English entry`)
	}
})
