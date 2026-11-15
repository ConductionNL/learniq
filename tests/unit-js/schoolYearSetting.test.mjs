// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The school year start on the admin settings page: an MM-DD day the server
// accepts, 1 August when nothing usable is stored, the school year a date
// falls in, and a save the server did not keep is an error.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
//
// @spec openspec/changes/school-year-setting/specs/school-structure/spec.md#requirement-the-school-year-starts-on-the-day-the-school-sets

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	DEFAULT_SCHOOL_YEAR_START,
	loadSchoolYearStart,
	parseSchoolYearStart,
	saveSchoolYearStart,
	schoolYearOf,
	startInYear,
	startOfDate,
} from '../../src/services/schoolYearSetting.js'

/**
 * A settings endpoint that answers with what it was given.
 *
 * @param {object} stored The settings the server holds.
 * @param {boolean} keeps Whether a write is kept.
 * @return {{http: object, url: string, sent: Array}} The endpoint.
 */
function endpointHolding(stored, keeps = true) {
	const sent = []
	const http = {
		get: async () => ({ data: stored }),
		put: async (url, body) => {
			sent.push(body)
			return { data: { config: keeps ? { ...stored, ...body } : stored } }
		},
	}
	return { http, url: '/apps/learniq/api/settings', sent }
}

/**
 * A date as YYYY-MM-DD in local time.
 *
 * @param {Date} date The date.
 * @return {string} The day.
 */
function day(date) {
	const pad = (n) => String(n).padStart(2, '0')
	return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

test('a start is a real MM-DD day; 29 February is refused like the server does', () => {
	assert.equal(parseSchoolYearStart('09-01'), '09-01')
	assert.equal(parseSchoolYearStart(' 12-31 '), '12-31')
	for (const raw of ['', '9-1', '13-01', '00-10', '02-30', '02-29', '04-31', '2026-09-01', 'september', null, undefined]) {
		assert.equal(parseSchoolYearStart(raw), null, String(raw))
	}
})

test('a picked date stands for its month and day', () => {
	assert.equal(startOfDate(new Date(2031, 8, 1)), '09-01')
	assert.equal(startOfDate(new Date(2028, 1, 29)), null)
	assert.equal(startOfDate(new Date('nonsense')), null)
	assert.equal(day(startInYear('09-01', 2026)), '2026-09-01')
})

test('a school that starts in September: 20 August 2027 falls in 2026-2027', () => {
	const year = schoolYearOf(new Date(2027, 7, 20), '09-01')
	assert.equal(year.label, '2026-2027')
	assert.equal(day(year.from), '2026-09-01')
	assert.equal(day(year.until), '2027-08-31')
})

test('no setting: 21 October 2026 falls in 1 August 2026 to 31 July 2027', async () => {
	const start = await loadSchoolYearStart(endpointHolding({}))
	assert.equal(start, DEFAULT_SCHOOL_YEAR_START)
	const year = schoolYearOf(new Date(2026, 9, 21), start)
	assert.deepEqual([year.label, day(year.from), day(year.until)], ['2026-2027', '2026-08-01', '2027-07-31'])
})

test('the start day itself opens the year, and a January start spans one calendar year', () => {
	assert.equal(schoolYearOf(new Date(2026, 8, 1), '09-01').label, '2026-2027')
	assert.equal(schoolYearOf(new Date(2026, 7, 31), '09-01').label, '2025-2026')
	const january = schoolYearOf(new Date(2026, 5, 15), '01-01')
	assert.deepEqual([january.label, day(january.until)], ['2026-2026', '2026-12-31'])
})

test('a stored start is read, an unusable one reads as 1 August', async () => {
	assert.equal(await loadSchoolYearStart(endpointHolding({ school_year_start: '09-01' })), '09-01')
	assert.equal(await loadSchoolYearStart(endpointHolding({ school_year_start: '02-29' })), '08-01')
})

test('a save sends the start and refuses an answer that did not keep it', async () => {
	const endpoint = endpointHolding({})
	assert.equal(await saveSchoolYearStart(endpoint, '09-01'), '09-01')
	assert.deepEqual(endpoint.sent, [{ school_year_start: '09-01' }])
	await assert.rejects(saveSchoolYearStart(endpointHolding({}, false), '09-01'))
})

/**
 * The section, wired to a settings endpoint and recording its notices.
 *
 * @param {object} stored The settings the server holds.
 * @return {Promise<{vm: object, sent: Array, notices: Array}>} The instance, what it sent and what it showed.
 */
async function sectionOver(stored) {
	const { loadSfc, instanceOf } = await import('./support/loadSfc.mjs')
	const sent = []
	const notices = []
	const http = {
		get: async () => ({ data: stored }),
		put: async (url, body) => {
			sent.push([url, body])
			return { data: { config: { ...stored, ...body } } }
		},
	}
	const component = await loadSfc('src/views/settings/SchoolYearSettingsSection.vue', {
		'@nextcloud/axios': { default: http },
		'@nextcloud/router': { generateUrl: (url) => `/nc${url}` },
		'@nextcloud/l10n': { translate: (app, text, vars) => String(text).replace(/\{(\w+)\}/g, (w, k) => (vars && k in vars ? String(vars[k]) : w)) },
		'@nextcloud/dialogs': { showError: (m) => notices.push(['error', m]), showSuccess: (m) => notices.push(['success', m]) },
	})
	const vm = instanceOf(component)
	await component.created.call(vm)
	return { vm, sent, notices }
}

test('the section shows the stored start and saves the picked day as MM-DD', async () => {
	const { vm, sent, notices } = await sectionOver({ school_year_start: '09-01' })
	assert.equal(vm.loading, false)
	assert.equal(vm.start, '09-01')
	assert.match(vm.helperText, /^School year \d{4}-\d{4} runs from /)

	vm.picked = new Date(2026, 7, 15)
	await vm.save()

	assert.deepEqual(sent, [['/nc/apps/learniq/api/settings', { school_year_start: '08-15' }]])
	assert.deepEqual(notices, [['success', 'School year start saved']])
})

test('the section does not save 29 February and says why', async () => {
	const { vm, sent } = await sectionOver({})
	vm.picked = new Date(2028, 1, 29)
	assert.equal(vm.start, null)
	assert.equal(vm.helperText, 'Choose a day other than 29 February.')
	await vm.save()
	assert.deepEqual(sent, [])
})
