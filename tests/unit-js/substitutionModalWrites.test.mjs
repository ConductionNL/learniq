// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The substitution dialog's writes, pinned through the component's own
// computed and methods: when it may submit, the PATCH a single change sends,
// and the batch request for several weeks.
//
// @spec openspec/specs/timetabling/spec.md#scenario-a-cohort-teacher-cancels-a-session-with-a-reason

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { loadSfcScript } from './sfcScript.mjs'

globalThis.t = (app, text) => text

const http = { calls: [], answers: [] }
const stubs = {
	'@nextcloud/axios': {
		default: {
			patch: (...args) => http.reply('patch', args),
			post: (...args) => http.reply('post', args),
			get: (...args) => http.reply('get', args),
		},
	},
	'@nextcloud/router': { generateUrl: (path, params) => path.replace('{id}', params?.id ?? '{id}') },
	'@nextcloud/vue': {},
}
http.reply = async (method, args) => {
	http.calls.push([method, ...args])
	const next = http.answers.shift()
	if (next instanceof Error) {
		throw next
	}
	return next ?? { data: {} }
}
const modal = await loadSfcScript('src/dialogs/SubstitutionModal.vue', stubs)

const FAILED = 'Could not save this change. Please check the reason and try again.'

/**
 * A fresh dialog `this` with recorded emits.
 *
 * @param {object} state Data to override.
 * @return {object}
 */
function dialog(state = {}) {
	http.calls = []
	http.answers = []
	const self = {
		...modal.data(),
		session: { id: 's1', lifecycle: 'scheduled', title: 'Wiskunde' },
		emitted: [],
		$emit(name) {
			self.emitted.push(name)
		},
		...state,
	}
	for (const [name, method] of Object.entries(modal.methods)) {
		self[name] = typeof method === 'function' ? method.bind(self) : method
	}
	Object.defineProperty(self, 'canSubmit', { get: () => modal.computed.canSubmit.call(self) })
	return self
}

/**
 * Run with console.error recorded instead of printed.
 *
 * @param {Function} run The callback.
 * @return {Promise<Array<Array>>} The logged arguments.
 */
async function capturingErrors(run) {
	const original = console.error
	const logged = []
	console.error = (...args) => logged.push(args)
	try {
		await run()
	} finally {
		console.error = original
	}
	return logged
}

test('a change needs a reason kind, the mode field and, for more weeks, a ticked lesson', () => {
	assert.equal(dialog().canSubmit, true)
	assert.equal(dialog({ changeReasonKind: '' }).canSubmit, false)
	assert.equal(dialog({ mode: 'substitute', substituteTeacherId: '  ' }).canSubmit, false)
	assert.equal(dialog({ mode: 'substitute', substituteTeacherId: null }).canSubmit, false)
	assert.equal(dialog({ mode: 'substitute', substituteTeacherId: 'jan' }).canSubmit, true)
	assert.equal(dialog({ mode: 'room', roomId: null }).canSubmit, false)
	assert.equal(dialog({ mode: 'room', roomId: 'r1' }).canSubmit, true)
	assert.equal(dialog({ mode: 'cancel', roomId: null, substituteTeacherId: '' }).canSubmit, true)
	assert.equal(dialog({ moreWeeks: true, selectedIds: [] }).canSubmit, false)
	assert.equal(dialog({ moreWeeks: true, selectedIds: ['s1'] }).canSubmit, true)
})

test('nothing is sent while the form is incomplete', async () => {
	const vm = dialog({ changeReasonKind: '' })
	await vm.submit()
	assert.deepEqual(http.calls, [])
	assert.equal(vm.saving, false)
})

test('a cancel, substitute or room change patches the session and closes', async () => {
	const cases = [
		[{ mode: 'cancel', changeReason: '' }, { changeReasonKind: 'teacher-absence', changeReason: null, lifecycle: 'cancelled' }],
		[
			{ mode: 'substitute', substituteTeacherId: ' jan ', changeReason: 'Ziek' },
			{ changeReasonKind: 'teacher-absence', changeReason: 'Ziek', lifecycle: 'scheduled', substituteTeacherId: 'jan' },
		],
		[{ mode: 'room', roomId: 'r2', changeReasonKind: 'room-unavailable' }, { changeReasonKind: 'room-unavailable', changeReason: null, roomId: 'r2' }],
	]
	for (const [state, body] of cases) {
		const vm = dialog({ ...state, error: 'old' })
		await vm.submit()
		assert.deepEqual(http.calls, [['patch', '/apps/openregister/api/objects/learniq/session/s1', body]])
		assert.deepEqual(Object.keys(http.calls[0][2]), Object.keys(body))
		assert.deepEqual(vm.emitted, ['changed', 'close'])
		assert.equal(vm.error, '')
		assert.equal(vm.saving, false)
	}
})

test('a refused change is logged and shown, and the dialog stays open', async () => {
	const vm = dialog()
	http.answers.push(new Error('409'))
	const logged = await capturingErrors(() => vm.submit())
	assert.equal(logged[0][0], '[SubstitutionModal] submit failed')
	assert.equal(vm.error, FAILED)
	assert.deepEqual(vm.emitted, [])
	assert.equal(vm.saving, false)
})

test('more weeks posts one batch with the mode fields and shows each outcome', async () => {
	const cases = [
		[{ mode: 'cancel' }, { kind: 'cancel', substituteTeacherId: null, roomId: null }],
		[{ mode: 'substitute', substituteTeacherId: ' eva ' }, { kind: 'substitute', substituteTeacherId: 'eva', roomId: null }],
		[{ mode: 'room', roomId: 'r3' }, { kind: 'room', substituteTeacherId: null, roomId: 'r3' }],
	]
	for (const [state, fields] of cases) {
		const vm = dialog({ ...state, moreWeeks: true, selectedIds: ['s1', 's2'], changeReason: 'Studiedag' })
		http.answers.push({ data: { results: [{ sessionId: 's1', ok: true }] } })
		await vm.submit()
		assert.deepEqual(http.calls, [
			[
				'post',
				'/apps/learniq/api/session-change-batches',
				{
					kind: fields.kind,
					sessionIds: ['s1', 's2'],
					changeReasonKind: 'teacher-absence',
					changeReason: 'Studiedag',
					substituteTeacherId: fields.substituteTeacherId,
					roomId: fields.roomId,
				},
			],
		])
		assert.deepEqual(Object.keys(http.calls[0][2]), ['kind', 'sessionIds', 'changeReasonKind', 'changeReason', 'substituteTeacherId', 'roomId'])
		assert.deepEqual(vm.results, [{ sessionId: 's1', ok: true }])
		assert.deepEqual(vm.emitted, ['changed'])
		assert.equal(vm.saving, false)
	}
})

test('a batch without results shows none, a refused batch shows the server error or ours', async () => {
	const empty = dialog({ moreWeeks: true, selectedIds: ['s1'] })
	http.answers.push({ data: {} })
	await empty.submit()
	assert.deepEqual(empty.results, [])

	const refused = dialog({ moreWeeks: true, selectedIds: ['s1'] })
	http.answers.push(Object.assign(new Error('422'), { response: { data: { error: 'Lesson s1 is in the past.' } } }))
	await refused.submit()
	assert.equal(refused.error, 'Lesson s1 is in the past.')
	assert.equal(refused.results, null)
	assert.deepEqual(refused.emitted, [])

	const broken = dialog({ moreWeeks: true, selectedIds: ['s1'] })
	http.answers.push(new Error('500'))
	await broken.submit()
	assert.equal(broken.error, FAILED)
	assert.equal(broken.saving, false)
})
