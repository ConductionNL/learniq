// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The enrolment forecast page (EnrolmentForecastView), through the
// component's own methods with a stubbed axios: the scenarios and programme
// names it loads, that picking one edits a copy, what saving, computing and
// copying a scenario send, the CSV a result exports, and how a failure reads.
//
// @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const http = { calls: [], answers: [] }

/**
 * Record a call and answer with the next queued answer (an Error is thrown).
 *
 * @param {string} method The HTTP verb.
 * @param {Array} args The axios arguments.
 * @return {Promise<object>} The answer.
 */
async function answer(method, args) {
	http.calls.push([method, ...args])
	const next = http.answers.shift() ?? { data: {} }
	if (next instanceof Error) throw next
	return next
}

const fill = (text, vars = {}) => text.replace(/\{(\w+)\}/g, (whole, key) => (key in vars ? String(vars[key]) : whole))

const component = await loadSfc('src/views/EnrolmentForecastView.vue', {
	'@nextcloud/axios': {
		default: {
			get: (...args) => answer('get', args),
			post: (...args) => answer('post', args),
			patch: (...args) => answer('patch', args),
		},
	},
	'@nextcloud/l10n': { translate: (app, text, vars) => fill(text, vars) },
	'@nextcloud/router': { generateUrl: (path, params) => `/nc${fill(path, params)}` },
})

const OBJECTS = '/nc/apps/openregister/api/objects/learniq/'

const SCENARIOS = [
	{
		id: 'f1',
		name: 'Basis',
		targetYear: 2027,
		targetGroupSize: 28,
		rates: [{ programme: 'p1', programmeYear: 1, upRate: 0.9, repeatRate: 0.05, leaveRate: 0.05 }],
		intake: [{ programme: 'p1', expected: 120 }],
		result: { scenario: 'Basis', targetYear: 2027, programmeYears: [], subjects: [] },
	},
	{ id: 'f2', name: 'Krimp', targetYear: 2028 },
]

/**
 * A fresh page with the scenario and programme answers queued.
 *
 * @return {object} The component instance.
 */
function page() {
	http.calls = []
	http.answers = [
		{ data: { results: structuredClone(SCENARIOS) } },
		{ data: { results: [{ id: 'p1', name: 'Havo' }, { id: 'p2', name: 'Vwo' }] } },
	]
	return instanceOf(component)
}

/**
 * An axios error carrying a response body.
 *
 * @param {object} data The body.
 * @return {Error} The error.
 */
function refused(data) {
	return Object.assign(new Error('Request failed with status code 400'), { response: { status: 400, data } })
}

test('the page loads the scenarios and the programme names, and picks the first', async () => {
	const vm = page()
	await vm.load()
	assert.deepEqual(http.calls, [
		['get', `${OBJECTS}enrolment-forecast`, { params: { _limit: 200 } }],
		['get', `${OBJECTS}programme`, { params: { _limit: 500 } }],
	])
	assert.deepEqual(vm.programmes, { p1: 'Havo', p2: 'Vwo' })
	assert.equal(vm.selectedId, 'f1')
	assert.deepEqual(vm.scenario, SCENARIOS[0])
	assert.deepEqual(vm.result, SCENARIOS[0].result)
	assert.deepEqual(vm.scenarioOptions, [
		{ value: 'f1', label: 'Basis (2027)' },
		{ value: 'f2', label: 'Krimp (2028)' },
	])
	assert.equal(vm.programmeName('p2'), 'Vwo')
	assert.equal(vm.programmeName('p9'), 'p9')
})

test('a reload keeps the scenario already chosen', async () => {
	const vm = page()
	await vm.load()
	vm.pick('f2')
	http.answers = [{ data: { results: structuredClone(SCENARIOS) } }, { data: { results: [] } }]
	await vm.load()
	assert.equal(vm.selectedId, 'f2')
})

test('no scenarios picks nothing, and empty answers read as none', async () => {
	const vm = page()
	http.answers = [{ data: {} }, { data: null }]
	await vm.load()
	assert.deepEqual(vm.scenarios, [])
	assert.deepEqual(vm.programmes, {})
	assert.equal(vm.selectedId, null)
	assert.equal(vm.scenario, null)
})

test('a load failure shows the server\'s error, else a retry sentence', async () => {
	const vm = page()
	http.answers = [refused({ error: 'No access to forecasts.' }), { data: {} }]
	await vm.load()
	assert.equal(vm.error, 'No access to forecasts.')

	http.answers = [new Error('Network Error'), { data: {} }]
	await vm.load()
	assert.equal(vm.error, 'Something went wrong. Try again.')
})

test('picking a scenario edits a copy with empty rates and intake filled in', async () => {
	const vm = page()
	await vm.load()
	vm.scenario.rates[0].upRate = 0.5
	assert.equal(vm.scenarios[0].rates[0].upRate, 0.9)
	vm.pick('f2')
	assert.deepEqual(vm.scenario, { id: 'f2', name: 'Krimp', targetYear: 2028, rates: [], intake: [] })
	assert.equal(vm.result, null)
	vm.pick('gone')
	assert.equal(vm.selectedId, 'gone')
	assert.equal(vm.scenario, null)
	assert.equal(vm.result, null)
})

test('saving patches only the rates and the intake', async () => {
	const vm = page()
	await vm.load()
	http.calls = []
	await vm.save()
	assert.deepEqual(http.calls, [[
		'patch',
		`${OBJECTS}enrolment-forecast/f1`,
		{ rates: SCENARIOS[0].rates, intake: SCENARIOS[0].intake },
	]])
	assert.equal(vm.busy, false)
	assert.equal(vm.error, '')
})

test('computing saves first, then shows what the forecast answered', async () => {
	const vm = page()
	await vm.load()
	http.calls = []
	const computed = { scenario: 'Basis', targetYear: 2027, programmeYears: [{ programmeYear: 1, learners: 120 }], subjects: [] }
	http.answers = [{ data: {} }, { data: computed }]
	await vm.compute()
	assert.deepEqual(http.calls.map((c) => [c[0], c[1]]), [
		['patch', `${OBJECTS}enrolment-forecast/f1`],
		['post', '/nc/apps/learniq/api/enrolment-forecasts/f1/compute'],
	])
	assert.deepEqual(vm.result, computed)
})

test('a failed compute shows the reason and keeps the earlier result', async () => {
	const vm = page()
	await vm.load()
	http.answers = [{ data: {} }, refused({ error: 'Rates must add up to 1.' })]
	await vm.compute()
	assert.equal(vm.error, 'Rates must add up to 1.')
	assert.deepEqual(vm.result, SCENARIOS[0].result)
	assert.equal(vm.busy, false)
})

test('copying creates a named copy of the scenario and opens it', async () => {
	const vm = page()
	await vm.load()
	http.calls = []
	const copied = { id: 'f3', name: 'Basis (copy)', targetYear: 2027 }
	http.answers = [
		{ data: copied },
		{ data: { results: [...structuredClone(SCENARIOS), copied] } },
		{ data: { results: [] } },
	]
	await vm.copy()
	assert.deepEqual(http.calls[0], ['post', `${OBJECTS}enrolment-forecast`, {
		name: 'Basis (copy)',
		targetYear: 2027,
		rates: SCENARIOS[0].rates,
		intake: SCENARIOS[0].intake,
		targetGroupSize: 28,
	}])
	assert.equal(http.calls.length, 3)
	assert.equal(vm.selectedId, 'f3')
	assert.equal(vm.scenario.name, 'Basis (copy)')
})

test('the CSV export lists every year and subject, quoted and escaped', async () => {
	const vm = page()
	vm.result = {
		scenario: 'Basis "A"',
		targetYear: 2027,
		programmeYears: [{ programmeName: 'Havo', programmeYear: 1, learners: 120, groupsNeeded: 5 }],
		subjects: [{ programmeName: 'Havo', programmeYear: 1, courseName: 'Wiskunde, B', learners: 60, estimated: true, groupsNeeded: 3 }],
	}
	const links = []
	globalThis.document = { createElement: () => { const link = { click() { links.push(link) } }; return link } }
	const blobs = []
	const create = URL.createObjectURL
	URL.createObjectURL = (blob) => { blobs.push(blob); return 'blob:csv' }
	try {
		vm.exportCsv()
	} finally {
		URL.createObjectURL = create
	}
	assert.equal(links.length, 1)
	assert.equal(links[0].download, 'enrolment-forecast-2027.csv')
	assert.equal(blobs[0].type, 'text/csv')
	assert.equal(await blobs[0].text(), [
		'"scenario","programme","year","subject","learners","estimated","groups"',
		'"Basis ""A""","Havo","1","","120","","5"',
		'"Basis ""A""","Havo","1","Wiskunde, B","60","true","3"',
	].join('\n'))
})

test('a time reads in the local format, an invalid one as nothing', () => {
	const vm = page()
	assert.equal(vm.formatTime('not a date'), '')
	assert.equal(vm.formatTime('2026-11-12T09:00:00Z'), new Date('2026-11-12T09:00:00Z').toLocaleString())
})

test('the rate table edits moves-up, repeats and leaves; the result tables are read-only', () => {
	const vm = page()
	assert.deepEqual(vm.rateColumns, [
		{ key: 'programmeYear', label: 'Year', type: 'string', readOnly: true, aggregate: 'none' },
		{ key: 'upRate', label: 'Moves up', type: 'number', aggregate: 'none' },
		{ key: 'repeatRate', label: 'Repeats', type: 'number', aggregate: 'none' },
		{ key: 'leaveRate', label: 'Leaves', type: 'number', aggregate: 'none' },
	])
	assert.deepEqual(vm.intakeColumns, [{ key: 'expected', label: 'Expected learners', type: 'number', aggregate: 'none' }])
	assert.deepEqual(vm.yearColumns.map((c) => [c.key, c.readOnly, c.aggregate]), [
		['programmeYear', true, 'none'],
		['learners', true, 'sum'],
		['groupsNeeded', true, 'sum'],
	])
	assert.deepEqual(vm.subjectColumns.map((c) => c.key), ['programmeYear', 'courseName', 'learners', 'estimate', 'groupsNeeded'])
})
