// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The coordinator's timetable conflict queue (TimetableConflictQueue):
// acknowledging a conflict keeps it in the queue, resolving it takes it out,
// and either one changes only the conflict's lifecycle, never a lesson.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
//
// @spec openspec/changes/capability-rows-say-what-they-do/specs/timetabling/spec.md#requirement-a-coordinator-acknowledges-and-resolves-a-timetable-conflict-by-hand
// @spec openspec/changes/capability-rows-say-what-they-do/specs/timetabling/spec.md#requirement-an-import-started-from-the-timetable-page-reports-its-outcome-and-refreshes-the-conflicts

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { instanceOf, loadSfc, recordingAxios } from './support/loadSfc.mjs'

/**
 * The queue wired to a recording transport, holding one open double booking.
 *
 * @param {Function} [reply] The transport's answer.
 * @return {Promise<{vm: object, http: object}>} The instance and its transport.
 */
async function queue(reply = () => ({ data: {} })) {
	// The view calls Nextcloud's global t() while it loads.
	globalThis.t = (app, text) => text
	const http = recordingAxios(reply)
	const component = await loadSfc('src/views/TimetableConflictQueue.vue', {
		'@nextcloud/axios': { default: http },
		'@nextcloud/l10n': { translate: (app, text) => text, translatePlural: () => '' },
		'@nextcloud/router': {
			generateUrl: (url, vars) => '/nc' + url.replace(/\{(\w+)\}/g, (w, k) => (vars && k in vars ? vars[k] : w)),
		},
	})
	const vm = instanceOf(component)
	vm.conflicts = [{
		id: 'conflict-1',
		kind: 'room-double-booking',
		lifecycle: 'open',
		sessionIds: ['lesson-a', 'lesson-b'],
		detectedAt: '2026-11-16T08:00:00Z',
	}]
	return { vm, http }
}

test('acknowledging a room double booking keeps it in the queue and touches only the conflict', async () => {
	const { vm, http } = await queue()

	await vm.transition(vm.conflicts[0], 'acknowledged')

	assert.deepEqual(http.calls.map((call) => [call.method, call.url, call.body]), [
		['patch', '/nc/apps/openregister/api/objects/learniq/timetable-conflict/conflict-1', { lifecycle: 'acknowledged' }],
	])
	assert.deepEqual(vm.visibleConflicts.map((c) => [c.id, c.lifecycle]), [['conflict-1', 'acknowledged']])
	assert.deepEqual(vm.conflicts[0].sessionIds, ['lesson-a', 'lesson-b'])
})

test('resolving the conflict takes it out of the queue', async () => {
	const { vm, http } = await queue()

	await vm.transition(vm.conflicts[0], 'resolved')

	assert.equal(http.calls.length, 1)
	assert.deepEqual(http.calls[0].body, { lifecycle: 'resolved' })
	assert.ok(http.calls.every((call) => !call.url.includes('/session')), 'no lesson is written')
	assert.deepEqual(vm.visibleConflicts, [])
})

test('without planninq the import is offered but disabled, with the reason', async () => {
	const { vm } = await queue(() => ({ data: { canImport: true, planninq: false } }))

	await vm.loadImportAccess()

	assert.equal(vm.canImport, true)
	assert.equal(vm.planninqReady, false)
	const template = readFileSync(new URL('../../src/views/TimetableConflictQueue.vue', import.meta.url), 'utf8')
	assert.match(template, /:disabled="!planninqReady"/)
	assert.match(template, /v-if="canImport && !planninqReady"/)
	assert.match(template, /Install planninq to import a timetable/)
})

test('a finished import reloads the conflict queue', () => {
	const template = readFileSync(new URL('../../src/views/TimetableConflictQueue.vue', import.meta.url), 'utf8')
	assert.match(template, /<TimetableImportDialog[^>]*@imported="load"/s)
})
