// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The attendance register page (RollCallView), through the component's own
// methods with a stubbed axios and router: which register it asks for, how
// a mark and the keyboard change a row, that an incomplete register is
// refused before anything is sent, what a save posts, and how a load or
// save failure reads.
//
// @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const http = { calls: [], answer: null }

/**
 * Record a call and answer it, or throw what the test set.
 *
 * @param {string} method The HTTP verb.
 * @param {Array} args The axios arguments.
 * @return {Promise<object>} The answer.
 */
async function answer(method, args) {
	http.calls.push([method, ...args])
	if (http.answer instanceof Error) throw http.answer
	return http.answer
}

const component = await loadSfc('src/views/RollCallView.vue', {
	'@nextcloud/axios': {
		default: {
			get: (...args) => answer('get', args),
			post: (...args) => answer('post', args),
		},
	},
	'@nextcloud/router': { generateUrl: (path) => `/nc${path}` },
})

const API = '/nc/apps/learniq/api/attendance/roll-call'

const REGISTER = {
	cohortId: 'c1',
	cohortName: '3B',
	cohorts: [{ id: 'c1', name: '3B' }, { id: 'c2', name: '4A' }],
	sessionId: 's1',
	sessions: [{ id: 's1', name: 'Wiskunde 09:00' }],
	date: '2026-11-12',
	today: '2026-11-12',
	editable: true,
	pupils: [
		{ learnerId: 'l1', name: 'Sem', status: 'present' },
		{ learnerId: 'l2', name: 'Noor', status: 'present' },
	],
}

/**
 * A fresh page on a route query, with a recording router.
 *
 * @param {object} [query] The route query.
 * @return {object} The component instance.
 */
function page(query = {}) {
	http.calls = []
	http.answer = { data: structuredClone(REGISTER) }
	const replaced = []
	return instanceOf(component, {
		$route: { path: '/attendance/roll-call', query },
		$router: { replace: (to) => { replaced.push(to); return Promise.resolve() } },
		replaced,
	})
}

/**
 * An axios error carrying a response body.
 *
 * @param {object} data The body.
 * @return {Error} The error.
 */
function refused(data) {
	return Object.assign(new Error('Request failed with status code 422'), { response: { status: 422, data } })
}

test('the register is asked for the route\'s cohort, date and lesson', async () => {
	const vm = page({ cohortId: 'c1', date: '2026-11-12', sessionId: '', extra: 'x' })
	await vm.load()
	assert.deepEqual(http.calls, [['get', API, { params: { cohortId: 'c1', date: '2026-11-12', sessionId: undefined } }]])
	assert.equal(vm.loading, false)
	assert.equal(vm.title, 'Attendance register · 3B')
	assert.deepEqual(vm.currentCohort, { id: 'c1', name: '3B' })
	assert.deepEqual(vm.currentSession, { id: 's1', name: 'Wiskunde 09:00' })
})

test('the rows are copies, so marking one leaves the loaded register as it was', async () => {
	const vm = page()
	await vm.load()
	vm.mark(0, 'absent-unexcused')
	assert.equal(vm.register.pupils[0].status, 'present')
	assert.equal(vm.pupils[0].status, 'absent-unexcused')
})

test('an empty answer leaves an empty register without a cohort', async () => {
	const vm = page()
	http.answer = { data: null }
	await vm.load()
	assert.deepEqual(vm.pupils, [])
	assert.equal(vm.title, 'Attendance register')
	assert.equal(vm.currentCohort, null)
	assert.equal(vm.currentSession, null)
})

test('a register that cannot load shows the server\'s error, else a general sentence', async () => {
	const vm = page()
	http.answer = refused({ error: 'You do not teach this group.' })
	await vm.load()
	assert.equal(vm.loadError, 'You do not teach this group.')
	assert.equal(vm.loading, false)

	http.answer = new Error('Network Error')
	await vm.load()
	assert.equal(vm.loadError, 'The register could not be loaded.')
})

test('the counts sentence counts every status', async () => {
	const vm = page()
	vm.pupils = [
		{ status: 'present' },
		{ status: 'late' },
		{ status: 'late' },
		{ status: 'absent-excused' },
		{ status: 'absent-unexcused' },
		{ status: 'left-early' },
	]
	assert.equal(vm.countsText, '2 present, 2 late, 1 absent with permission, 1 absent without permission')
})

test('choosing a cohort or date goes there and drops the chosen lesson', async () => {
	const vm = page()
	await vm.load()
	vm.chooseCohort({ id: 'c2' })
	vm.chooseDate('2026-11-13')
	vm.chooseSession({ id: 's9' })
	vm.chooseCohort(null)
	vm.chooseDate('')
	vm.chooseSession({})
	assert.deepEqual(vm.replaced, [
		{ path: '/attendance/roll-call', query: { cohortId: 'c2', date: '2026-11-12' } },
		{ path: '/attendance/roll-call', query: { cohortId: 'c1', date: '2026-11-13' } },
		{ path: '/attendance/roll-call', query: { cohortId: 'c1', date: '2026-11-12', sessionId: 's9' } },
	])
})

test('a mark on a register that cannot be edited changes nothing', async () => {
	const vm = page()
	http.answer = { data: { ...structuredClone(REGISTER), editable: false } }
	await vm.load()
	vm.saved = true
	vm.mark(0, 'late')
	assert.equal(vm.pupils[0].status, 'present')
	assert.equal(vm.saved, true)
})

test('a late mark starts at the first quick minutes and clears the saved state', async () => {
	const vm = page()
	await vm.load()
	vm.saved = true
	vm.mark(1, 'late')
	assert.equal(vm.pupils[1].status, 'late')
	assert.equal(vm.pupils[1].lateMinutes, 5)
	assert.equal(vm.saved, false)
	vm.setField(1, 'lateMinutes', 15)
	assert.equal(vm.pupils[1].lateMinutes, 15)
})

test('a letter key marks the row, an arrow moves the focus', async () => {
	const vm = page()
	await vm.load()
	const focused = []
	vm.$refs = { rows: [{ focus: () => focused.push(0) }, { focus: () => focused.push(1) }] }
	const row = {}
	const key = (k, extra = {}) => {
		let prevented = false
		vm.onKey({ key: k, target: row, currentTarget: row, preventDefault: () => { prevented = true }, ...extra }, 0)
		return prevented
	}
	assert.equal(key('t'), true)
	assert.equal(vm.pupils[0].status, 'absent-excused')
	assert.equal(vm.pupils[0].absenceReasonKind, 'illness')
	assert.equal(key('ArrowDown'), true)
	assert.deepEqual(focused, [1])
	assert.equal(key('ArrowUp'), true)
	assert.deepEqual(focused, [1])
	assert.equal(key('x'), false)
	assert.equal(key('p', { ctrlKey: true }), false)
	assert.equal(vm.pupils[0].status, 'absent-excused')
})

test('an incomplete register is refused by name and nothing is sent', async () => {
	const vm = page()
	await vm.load()
	vm.pupils = [
		{ learnerId: 'l1', name: 'Sem', status: 'late', lateMinutes: 0 },
		{ learnerId: 'l2', name: 'Noor', status: 'absent-excused', absenceReasonKind: null },
	]
	http.calls = []
	await vm.save()
	assert.deepEqual(http.calls, [])
	assert.equal(
		vm.saveError,
		'Check the marks of: Sem, Noor. A late mark needs its minutes and an absence with permission needs a reason.',
	)
	assert.equal(vm.saved, false)
})

test('a save posts the cohort, date, lesson and the marks, then shows the answer', async () => {
	const vm = page()
	await vm.load()
	vm.pupils = [
		{ learnerId: 'l1', name: 'Sem', status: 'late', lateMinutes: '10' },
		{ learnerId: 'l2', name: 'Noor', status: 'absent-excused', absenceReasonKind: 'appointment', reason: ' tandarts ' },
	]
	http.calls = []
	const after = { ...structuredClone(REGISTER), pupils: [{ learnerId: 'l1', name: 'Sem', status: 'late', lateMinutes: 10 }] }
	http.answer = { data: after }
	await vm.save()
	assert.deepEqual(http.calls, [['post', API, {
		cohortId: 'c1',
		date: '2026-11-12',
		sessionId: 's1',
		marks: [
			{ learnerId: 'l1', status: 'late', lateMinutes: 10 },
			{ learnerId: 'l2', status: 'absent-excused', absenceReasonKind: 'appointment', reason: 'tandarts' },
		],
	}]])
	assert.equal(vm.saved, true)
	assert.equal(vm.saving, false)
	assert.deepEqual(vm.pupils, after.pupils)
})

test('a register without a lesson saves the lesson as null', async () => {
	const vm = page()
	http.answer = { data: { ...structuredClone(REGISTER), sessionId: '' } }
	await vm.load()
	http.calls = []
	await vm.save()
	assert.equal(http.calls[0][2].sessionId, null)
})

test('a refused save shows the server\'s error, else a retry sentence', async () => {
	const vm = page()
	await vm.load()
	http.answer = refused({ error: 'The register is closed.' })
	await vm.save()
	assert.equal(vm.saveError, 'The register is closed.')
	assert.equal(vm.saved, false)
	assert.equal(vm.saving, false)

	http.answer = new Error('Network Error')
	await vm.save()
	assert.equal(vm.saveError, 'The register could not be saved. Try again.')
})

test('a row reads its name, mark and late minutes', () => {
	const vm = page()
	assert.equal(vm.pupilLabel({ name: 'Sem', status: 'late', lateMinutes: 10 }), 'Sem: Late, 10 minutes')
	assert.equal(vm.pupilLabel({ name: 'Sem', status: 'late' }), 'Sem: Late')
	assert.equal(vm.pupilLabel({ name: 'Noor', status: 'absent-excused' }), 'Noor: Absent with permission')
	assert.equal(vm.statusLabel('left-early'), 'Left early')
	assert.equal(vm.statusLabel('unknown'), 'unknown')
	assert.equal(vm.statusLabel('toString'), 'toString')
	assert.equal(vm.reasonLabel('illness'), 'Ill')
	assert.equal(vm.reasonLabel('custom'), 'custom')
})

test('an absence report reads as approved or waiting', () => {
	const vm = page()
	assert.equal(vm.reportText({ lifecycle: 'approved', reason: 'griep' }), 'Absence report approved: griep')
	assert.equal(vm.reportText({ lifecycle: 'pending', reason: 'griep' }), 'Absence report waiting for a decision: griep')
})
