// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The conference report dialog's save, pinned through the component's own
// method with stubbed request helpers: create once, record when asked,
// announce and close, or show the refusal.
//
// @spec openspec/specs/parent-conferences/spec.md

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { conferenceReportBody } from '../../src/dialogs/dialogBodies.js'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const log = []
let failWith = null
let created = { id: 'rep-1' }
const component = await loadSfc('src/dialogs/ConferenceReportDialog.vue', {
	'@nextcloud/auth': { getCurrentUser: () => ({ displayName: 'Jan' }) },
	'@nextcloud/l10n': { getLanguage: () => 'en' },
	'./dialogRequests.js': {
		announceSaved: (...args) => log.push(['announce', ...args]),
		callerTenant: () => 't1',
		createObject: async (...args) => {
			log.push(['create', ...args])
			if (failWith) throw failWith
			return created
		},
		currentUserId: () => 'jan',
		listObjects: async () => [],
		readObject: async () => ({}),
		runTransition: async (...args) => {
			log.push(['transition', ...args])
		},
	},
	'./dialogNotice.js': {
		dialogNoticeProps: () => ({}),
		requestRefusal: (error, title, fallback) => ({ error: error.message, title, fallback }),
	},
})

/**
 * A report dialog with a chosen conversation.
 *
 * @param {object} state Data to set.
 * @return {object}
 */
function dialog(state = {}) {
	log.length = 0
	const vm = instanceOf(component, { conferenceSlotId: 's1', learnerId: '' })
	vm.$emit = (name) => log.push(['emit', name])
	return Object.assign(vm, {
		slot: { id: 's1', learnerId: 'sem', teacherId: 'jan' },
		slotId: 's1',
		narrative: 'Goed gesprek.',
		attendees: 'moeder',
		recordedAt: '2026-11-10T19:00',
		...state,
	})
}

test('a first save creates the report from the form, records it when asked, announces and closes', async () => {
	const vm = dialog()
	await vm.save(true)
	assert.deepEqual(log, [
		[
			'create',
			'conference-report',
			conferenceReportBody({
				slot: vm.slot,
				slotId: 's1',
				narrative: 'Goed gesprek.',
				attendees: 'moeder',
				recordedBy: 'jan',
				recordedAt: '2026-11-10T19:00',
				tenant: 't1',
			}),
		],
		['transition', 'rep-1', 'record'],
		['announce', 'learniq:conference-report:saved', { reportId: 'rep-1', recorded: true }],
		['emit', 'close'],
	])
	assert.equal(vm.savedId, 'rep-1')
	assert.equal(vm.notice, null)
	assert.equal(vm.busy, false)
})

test('a saved report is not created again; a draft save does not record', async () => {
	const vm = dialog({ savedId: 'rep-0' })
	await vm.save(false)
	assert.deepEqual(log, [
		['announce', 'learniq:conference-report:saved', { reportId: 'rep-0', recorded: false }],
		['emit', 'close'],
	])
	await vm.save(true)
	assert.deepEqual(log[2], ['transition', 'rep-0', 'record'])
})

test('a create answer without an id records nothing but still announces', async () => {
	created = null
	const vm = dialog()
	await vm.save(true)
	assert.deepEqual(log.map((entry) => entry[0]), ['create', 'announce', 'emit'])
	assert.equal(vm.savedId, '')
	created = { id: 'rep-1' }
})

test('a refused save shows the refusal and stays open', async () => {
	failWith = new Error('403')
	const vm = dialog({ notice: 'old' })
	await vm.save(true)
	assert.deepEqual(log.map((entry) => entry[0]), ['create'])
	assert.deepEqual(vm.notice, { error: '403', title: 'Not saved.', fallback: 'The report could not be saved.' })
	assert.equal(vm.busy, false)
	failWith = null
})
