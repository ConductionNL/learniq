// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The two compliance dashboard widgets, through each component's own
// methods with a stubbed axios: coverage per rule (filtered by department,
// sorted by percentage either way) and compliance per department (named by
// the last part of its path).
//
// @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const http = { calls: [], answers: [] }

/**
 * Record a call and answer with the next queued answer (an Error is thrown).
 *
 * @param {Array} args The axios arguments.
 * @return {Promise<object>} The answer.
 */
async function answer(args) {
	http.calls.push(args[0])
	const next = http.answers.shift() ?? { data: {} }
	if (next instanceof Error) throw next
	return next
}

const stubs = {
	'@nextcloud/axios': { default: { get: (...args) => answer(args) } },
	'@nextcloud/router': { generateUrl: (path) => `/nc${path}` },
}
const coverage = await loadSfc('src/views/widgets/RegulationCoverageWidget.vue', stubs)
const departments = await loadSfc('src/views/widgets/DepartmentComplianceWidget.vue', stubs)

/**
 * A fresh widget with these answers queued.
 *
 * @param {object} component The component options.
 * @param {Array} answers The queued answers.
 * @return {object} The component instance.
 */
function widget(component, answers = []) {
	http.calls = []
	http.answers = answers
	return instanceOf(component)
}

test('coverage per rule loads the rules and the departments to filter by', async () => {
	const vm = widget(coverage, [
		{ data: { regulations: [{ slug: 'a', coveragePercent: 80 }, { slug: 'b', coveragePercent: 20 }, { slug: 'c', coveragePercent: null }] } },
		{ data: { departments: [{ department: 'school/havo' }, { department: '' }, {}] } },
	])
	coverage.mounted.call(vm)
	await new Promise((resolve) => setImmediate(resolve))
	assert.deepEqual(http.calls, [
		'/nc/apps/learniq/api/compliance/coverage-by-regulation',
		'/nc/apps/learniq/api/compliance/departments',
	])
	assert.deepEqual(vm.departments, ['school/havo'])
	assert.equal(vm.loading, false)
	assert.deepEqual(vm.sortedRows.map((r) => r.slug), ['b', 'a', 'c'])
	vm.toggleSort()
	assert.equal(vm.direction, 'desc')
	assert.deepEqual(vm.sortedRows.map((r) => r.slug), ['a', 'b', 'c'])
	vm.toggleSort()
	assert.equal(vm.direction, 'asc')
})

test('coverage per rule asks for the chosen department only', async () => {
	const vm = widget(coverage, [{ data: { regulations: [] } }])
	vm.department = 'school/havo & vwo'
	await vm.load()
	assert.deepEqual(http.calls, ['/nc/apps/learniq/api/compliance/coverage-by-regulation?department=school%2Fhavo%20%26%20vwo'])
})

test('coverage per rule reads a malformed answer as no rules, a failure says so', async () => {
	const vm = widget(coverage, [{ data: { regulations: 'none' } }])
	await vm.load()
	assert.deepEqual(vm.rows, [])
	assert.equal(vm.error, '')

	vm.rows = [{ slug: 'old' }]
	http.answers = [new Error('500')]
	await vm.load()
	assert.deepEqual(vm.rows, [])
	assert.equal(vm.error, 'Coverage per rule could not be loaded.')
	assert.equal(vm.loading, false)
})

test('coverage per rule offers no departments when they cannot load', async () => {
	const vm = widget(coverage, [new Error('500')])
	vm.departments = ['old']
	await vm.loadDepartments()
	assert.deepEqual(vm.departments, [])
})

test('a coverage colour reads as its word, an unknown one as itself', () => {
	const vm = widget(coverage)
	assert.equal(vm.ragLabel('red'), 'Red')
	assert.equal(vm.ragLabel('amber'), 'Amber')
	assert.equal(vm.ragLabel('green'), 'Green')
	assert.equal(vm.ragLabel('purple'), 'purple')
})

test('compliance per department loads the departments', async () => {
	const rows = [{ department: 'school/havo', coveragePercent: 75, covered: 3, obligations: 4 }]
	const vm = widget(departments, [{ data: { departments: rows } }])
	departments.mounted.call(vm)
	await new Promise((resolve) => setImmediate(resolve))
	assert.deepEqual(http.calls, ['/nc/apps/learniq/api/compliance/departments'])
	assert.deepEqual(vm.rows, rows)
	assert.equal(vm.loading, false)
})

test('compliance per department reads a malformed answer as none, a failure says so', async () => {
	const vm = widget(departments, [{ data: null }])
	await vm.load()
	assert.deepEqual(vm.rows, [])

	http.answers = [new Error('500')]
	await vm.load()
	assert.equal(vm.error, 'Compliance per department could not be loaded.')
	assert.equal(vm.loading, false)
})

test('a department is named by the last part of its path', () => {
	const vm = widget(departments)
	assert.equal(vm.departmentLabel({ department: 'school/havo/bovenbouw' }), 'bovenbouw')
	assert.equal(vm.departmentLabel({ department: 'school' }), 'school')
	assert.equal(vm.departmentLabel({ department: '' }), 'No department')
	assert.equal(vm.departmentLabel({}), 'No department')
})

test('a department\'s coverage reads as percent of its obligations', () => {
	const vm = widget(departments)
	assert.equal(vm.coverageLabel({ coveragePercent: 75, covered: 3, obligations: 4 }), '75% (3 of 4)')
	assert.equal(vm.coverageLabel({ coveragePercent: 0, covered: 0, obligations: 2 }), '0% (0 of 2)')
	assert.equal(vm.coverageLabel({ coveragePercent: null }), 'No obligations')
	assert.equal(vm.coverageLabel({}), 'No obligations')
})
