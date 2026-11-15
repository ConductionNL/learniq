// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The list views that read Open Register collections on load: the admissions
// review board, the cohort trend heat map, the course quality report and the
// study-progress risk dashboard. Pinned through each component's own methods
// with a recording fetch.

import assert from 'node:assert/strict'
import { afterEach, beforeEach, mock, test } from 'node:test'
import { loadSfcScript } from './sfcScript.mjs'

const OCS = { headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' } }
const API = '/index.php/apps/openregister/api/objects/learniq'

const stubs = {
	'@conduction/nextcloud-vue': {},
	'@nextcloud/l10n': { getCanonicalLocale: () => 'nl-NL' },
	'@nextcloud/router': { generateUrl: (path) => `/index.php${path}` },
	'@nextcloud/vue': {},
}

const admissions = await loadSfcScript('src/views/AdmissionsReviewBoard.vue', stubs)
const heatmap = await loadSfcScript('src/views/GroupTrendHeatmap.vue', stubs)
const quality = await loadSfcScript('src/views/CourseQualityReport.vue', stubs)
const bsa = await loadSfcScript('src/views/BsaRiskDashboard.vue', stubs)

const calls = []
let answers = {}
let errors = []

/**
 * A fetch response.
 *
 * @param {number} status the HTTP status
 * @param {*} body what json() resolves to
 * @return {object}
 */
function response(status, body = {}) {
	return { ok: status >= 200 && status < 300, status, json: async () => body }
}

/**
 * A stand-in `this` carrying every method of a component.
 *
 * @param {object} component the component options
 * @param {object} state the data fields the test sets
 * @return {object}
 */
function view(component, state = {}) {
	const ctx = { t: (app, text) => text, ...state }
	for (const [name, fn] of Object.entries(component.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	return ctx
}

beforeEach(() => {
	calls.length = 0
	answers = {}
	errors = []
	globalThis.fetch = async (url, init) => {
		calls.push([url, init])
		const answer = answers[url]
		if (answer instanceof Error) {
			throw answer
		}
		return answer ?? response(200, [])
	}
	mock.method(console, 'error', (...args) => errors.push(args))
})

afterEach(() => {
	mock.restoreAll()
})

test('the review board reads applications and rounds, from any list shape', async () => {
	answers[`${API}/admission?_limit=200`] = response(200, { results: [{ id: 'a1' }] })
	answers[`${API}/admissions-round?_limit=200`] = response(200, { objects: [{ id: 'r1' }] })
	const ctx = view(admissions, { error: 'old' })
	const loading = ctx.loadData()
	assert.equal(ctx.loading, true)
	assert.equal(ctx.error, null)
	await loading
	assert.deepEqual(calls, [[`${API}/admission?_limit=200`, OCS], [`${API}/admissions-round?_limit=200`, OCS]])
	assert.deepEqual(ctx.applications, [{ id: 'a1' }])
	assert.deepEqual(ctx.rounds, [{ id: 'r1' }])
	assert.equal(ctx.loading, false)
	assert.equal(ctx.error, null)

	answers[`${API}/admission?_limit=200`] = response(200, [{ id: 'a2' }])
	answers[`${API}/admissions-round?_limit=200`] = response(200, [])
	await ctx.loadData()
	assert.deepEqual(ctx.applications, [{ id: 'a2' }])
	assert.deepEqual(ctx.rounds, [])
})

test('the review board reports the first failed read', async () => {
	for (const [failing, message] of [['admission', 'Application fetch failed: 500'], ['admissions-round', 'AdmissionsRound fetch failed: 500']]) {
		errors = []
		answers = { [`${API}/${failing}?_limit=200`]: response(500) }
		const ctx = view(admissions, { applications: ['kept'], rounds: ['kept'] })
		await ctx.loadData()
		assert.equal(ctx.error, 'Failed to load pending applications. Please try again.')
		assert.deepEqual(ctx.applications, ['kept'])
		assert.deepEqual(ctx.rounds, ['kept'])
		assert.equal(ctx.loading, false)
		assert.equal(errors[0][0], '[AdmissionsReviewBoard] loadData error')
		assert.equal(errors[0][1].message, message)
	}
	answers = { [`${API}/admission?_limit=200`]: response(500), [`${API}/admissions-round?_limit=200`]: response(503) }
	errors = []
	await view(admissions).loadData()
	assert.equal(errors[0][1].message, 'Application fetch failed: 500')
})

test('the heat map keeps only published grade entries with a cohort', async () => {
	const entries = `${API}/grade-entry?_limit=500&lifecycle=published`
	answers[`${API}/Cohort?_limit=200`] = response(200, { results: [{ id: 'c1' }] })
	answers[entries] = response(200, { results: [
		{ id: 'e1', lifecycle: 'published', cohortId: 'c1' },
		{ id: 'e2', lifecycle: 'draft', cohortId: 'c1' },
		{ id: 'e3', lifecycle: 'published', cohortId: '' },
	] })
	const ctx = view(heatmap)
	await ctx.loadData()
	assert.deepEqual(calls.map(([url, init]) => [url, init]), [[`${API}/Cohort?_limit=200`, OCS], [entries, OCS]])
	assert.deepEqual(ctx.cohorts, [{ id: 'c1' }])
	assert.deepEqual(ctx.gradeEntries.map((e) => e.id), ['e1'])
	assert.equal(ctx.error, null)
	assert.equal(ctx.loading, false)

	for (const [url, message] of [[`${API}/Cohort?_limit=200`, 'Cohort fetch failed: 404'], [entries, 'GradeEntry fetch failed: 404']]) {
		errors = []
		answers = { [url]: response(404) }
		const failed = view(heatmap)
		await failed.loadData()
		assert.equal(failed.error, 'Failed to load grade trend data. Please try again.')
		assert.equal(errors[0][0], '[GroupTrendHeatmap] loadData error')
		assert.equal(errors[0][1].message, message)
		assert.equal(failed.loading, false)
	}
})

test('the quality report reads courses and campaigns; a failed read leaves an empty object', async () => {
	const courses = `${API}/Course?_limit=500`
	const campaigns = `${API}/evaluation-campaign?_limit=200`
	answers[courses] = response(200, { results: [{ id: 'k1' }] })
	answers[campaigns] = response(500)
	const ctx = view(quality)
	const loading = ctx.loadCourses()
	assert.equal(ctx.loadingCourses, true)
	await loading
	assert.deepEqual(calls, [[courses, OCS], [campaigns, OCS]])
	assert.deepEqual(ctx.courses, [{ id: 'k1' }])
	assert.deepEqual(ctx.campaigns, {})
	assert.equal(ctx.loadingCourses, false)

	answers[courses] = response(403)
	answers[campaigns] = response(200, { objects: [{ id: 'm1' }] })
	await ctx.loadCourses()
	assert.deepEqual(ctx.courses, {})
	assert.deepEqual(ctx.campaigns, [{ id: 'm1' }])
	assert.equal(errors.length, 0)

	answers[courses] = new Error('offline')
	const failed = view(quality, { courses: ['kept'] })
	await failed.loadCourses()
	assert.deepEqual(failed.courses, ['kept'])
	assert.equal(failed.loadingCourses, false)
	assert.equal(errors[0][0], '[CourseQualityReport] loadCourses error')
	assert.equal(errors[0][1].message, 'offline')
})

test('the quality report reads the selected course\'s scores and responses', async () => {
	const none = view(quality, { selectedCourseId: '', scores: ['old'], responses: ['old'] })
	await none.loadReport()
	assert.deepEqual([none.scores, none.responses, calls.length, none.loadingReport], [[], [], 0, undefined])

	const scores = `${API}/course-quality-score?courseId=a%20b%2Fc&_limit=100`
	const responses = `${API}/course-evaluation-response?courseId=a%20b%2Fc&_limit=200`
	answers[scores] = response(200, { results: [{ id: 's1' }] })
	answers[responses] = response(200, { objects: [{ id: 'q1' }] })
	const ctx = view(quality, { selectedCourseId: 'a b/c' })
	const loading = ctx.loadReport()
	assert.equal(ctx.loadingReport, true)
	await loading
	assert.deepEqual(calls, [[scores, OCS], [responses, OCS]])
	assert.deepEqual(ctx.scores, [{ id: 's1' }])
	assert.deepEqual(ctx.responses, [{ id: 'q1' }])
	assert.equal(ctx.loadingReport, false)

	answers[scores] = response(500)
	answers[responses] = response(500)
	await ctx.loadReport()
	assert.deepEqual([ctx.scores, ctx.responses], [{}, {}])

	answers[responses] = new Error('offline')
	const failed = view(quality, { selectedCourseId: 'a b/c', scores: ['kept'] })
	await failed.loadReport()
	assert.deepEqual(failed.scores, ['kept'])
	assert.equal(failed.loadingReport, false)
	assert.equal(errors[0][0], '[CourseQualityReport] loadReport error')
})

test('the risk dashboard reads the progress flags and reports a failed read', async () => {
	const flags = `${API}/bsa-progress-flag?_limit=100`
	answers[flags] = response(200, { objects: [{ id: 'f1' }] })
	const ctx = view(bsa, { error: 'old' })
	const loading = ctx.loadFlags()
	assert.equal(ctx.loading, true)
	assert.equal(ctx.error, null)
	await loading
	assert.deepEqual(calls, [[flags, OCS]])
	assert.deepEqual(ctx.flags, [{ id: 'f1' }])
	assert.equal(ctx.loading, false)

	answers[flags] = response(502)
	const failed = view(bsa, { flags: ['kept'] })
	await failed.loadFlags()
	assert.deepEqual(failed.flags, ['kept'])
	assert.equal(failed.error, 'Failed to load at-risk learners. Please try again.')
	assert.equal(errors[0][0], '[BsaRiskDashboard] loadFlags error')
	assert.equal(errors[0][1].message, 'BsaProgressFlag fetch failed: 502')
	assert.equal(failed.loading, false)
})
