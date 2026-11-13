// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The teacher availability dialog's facts and save, pinned through the
// component's own computed and method with stubbed request helpers.
//
// @spec openspec/specs/parent-conferences/spec.md

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const log = []
let tenant = ''
let failWith = null
const requests = {
	announceSaved: (...args) => log.push(['announce', ...args]),
	callerTenant: () => tenant,
	createObject: async (...args) => {
		log.push(['create', ...args])
		if (failWith) throw failWith
		return { id: 'av-new', ...args[1] }
	},
	currentUserId: () => 'jan',
	listObjects: async () => [],
	readObject: async () => ({}),
	runTransition: async (...args) => {
		log.push(['transition', ...args])
	},
	updateObject: async (...args) => {
		log.push(['update', ...args])
		return { id: args[1], ...args[2] }
	},
}
let user = { displayName: 'Jan de Vries' }
const component = await loadSfc('src/dialogs/TeacherAvailabilityDialog.vue', {
	'@nextcloud/auth': { getCurrentUser: () => user },
	'@nextcloud/l10n': { getLanguage: () => 'en' },
	'./dialogRequests.js': requests,
	'./dialogNotice.js': {
		dialogNoticeProps: () => ({}),
		requestRefusal: (error, title, fallback) => ({ error: error.message, title, fallback }),
	},
})

/**
 * A dialog for round r1.
 *
 * @param {object} state Data to set.
 * @return {object}
 */
function dialog(state = {}) {
	log.length = 0
	const vm = instanceOf(component, { conferenceRoundId: 'r1' })
	vm.$emit = (name) => log.push(['emit', name])
	return Object.assign(vm, state)
}

test('the facts name the round, the conversation length and the booking window', () => {
	const vm = dialog({
		round: {
			name: 'Najaar',
			slotDurationMinutes: 10,
			bufferMinutes: 5,
			bookingOpensAt: '2026-11-02T08:00:00',
			bookingClosesAt: '2026-11-09T20:00:00',
		},
	})
	const facts = vm.facts
	assert.deepEqual(facts.map((f) => f.label), ['Round', 'Conversations', 'Parents book'])
	assert.equal(facts[0].value, 'Najaar')
	assert.equal(facts[1].value, '10 minutes, with 5 minutes between two conversations')
	assert.match(facts[2].value, /^from .+ up to and including .+$/)
})

test('a round without a length, a pause or a booking window shows blanks or no pause', () => {
	assert.deepEqual(dialog({ round: {} }).facts.map((f) => f.value), ['', '', ''])
	const noPause = dialog({ round: { slotDurationMinutes: 15, bookingOpensAt: '2026-11-02T08:00:00' } }).facts
	assert.equal(noPause[1].value, '15 minutes, with 0 minutes between two conversations')
	assert.equal(noPause[2].value, '')
})

test('a first save creates the availability with the tenant, then closes', async () => {
	tenant = 't1'
	const vm = dialog({ picked: { '2026-11-10': ['19:00', '19:30'] }, notice: 'old' })
	await vm.save(false)
	assert.equal(log[0][0], 'create')
	assert.equal(log[0][1], 'teacher-availability')
	assert.deepEqual(Object.keys(log[0][2]), ['conferenceRoundId', 'teacherId', 'teacherName', 'blocks', 'tenant_id'])
	assert.deepEqual({ ...log[0][2], blocks: 'b' }, {
		conferenceRoundId: 'r1',
		teacherId: 'jan',
		teacherName: 'Jan de Vries',
		blocks: 'b',
		tenant_id: 't1',
	})
	assert.deepEqual(log[0][2].blocks, vm.blocks)
	assert.deepEqual(log.slice(1), [
		['announce', 'learniq:teacher-availability:saved', { availabilityId: 'av-new', submitted: false }],
		['emit', 'close'],
	])
	assert.equal(vm.notice, null)
	assert.equal(vm.busy, false)
	tenant = ''
})

test('a later save updates, submits when asked, and leaves the tenant out without one', async () => {
	user = null
	const vm = dialog({ availability: { id: 'av1', lifecycle: 'draft' } })
	await vm.save(true)
	assert.deepEqual(log.map((entry) => entry[0]), ['update', 'transition', 'announce', 'emit'])
	assert.equal(log[0][2], 'av1')
	assert.equal(log[0][3].teacherName, 'jan')
	assert.equal('tenant_id' in log[0][3], false)
	assert.deepEqual(log[1], ['transition', 'av1', 'submit'])
	assert.deepEqual(log[2][2], { availabilityId: 'av1', submitted: true })
	user = { displayName: 'Jan de Vries' }
})

test('a refused save shows the refusal and stays open', async () => {
	failWith = new Error('403')
	const vm = dialog()
	await vm.save(true)
	assert.deepEqual(log.map((entry) => entry[0]), ['create'])
	assert.deepEqual(vm.notice, { error: '403', title: 'Not saved.', fallback: 'Your availability could not be saved.' })
	assert.deepEqual(vm.availability, {})
	assert.equal(vm.busy, false)
	failWith = null
})
