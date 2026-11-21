// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The certificate reissue screen's load and last-run line, pinned through the
// component's own method and computed with a stubbed axios.
//
// @spec openspec/specs/certification/spec.md#requirement-staff-reissue-every-certificate-of-a-course-in-one-action

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const http = { calls: [], answer: null }
const component = await loadSfc('src/views/ReissueCertificatesView.vue', {
	'@nextcloud/axios': {
		default: {
			get: async (url) => {
				http.calls.push(url)
				if (http.answer instanceof Error) throw http.answer
				return http.answer
			},
		},
	},
	'@nextcloud/router': { generateUrl: (path) => `/nc${path}` },
})

/**
 * A reissue screen for course k1.
 *
 * @param {object|Error} answer The axios answer.
 * @return {object}
 */
function screen(answer) {
	http.calls = []
	http.answer = answer
	return instanceOf(component, { courseId: 'k1' })
}

test('the load shows the counts and the last run', async () => {
	const vm = screen({ data: { issued: 12, revoked: 1, expired: 2, lastRun: { status: 'done', processed: 10, skipped: 2, failed: 0 } } })
	await vm.load()
	assert.deepEqual(http.calls, ['/nc/apps/learniq/api/courses/k1/credentials/reissue'])
	assert.deepEqual(vm.counts, { issued: 12, revoked: 1, expired: 2 })
	assert.deepEqual(Object.keys(vm.counts), ['issued', 'revoked', 'expired'])
	assert.equal(vm.lastRunText, 'Last reissue: 10 reissued, 2 skipped, 0 failed.')
	assert.equal(vm.loading, false)
	assert.equal(vm.error, '')
})

test('missing counts read as zero, a missing or queued run as waiting', async () => {
	const vm = screen({ data: { issued: 3 } })
	await vm.load()
	assert.deepEqual(vm.counts, { issued: 3, revoked: 0, expired: 0 })
	assert.equal(vm.lastRun, null)
	assert.equal(vm.lastRunText, 'A reissue is waiting to run.')
	vm.lastRun = { status: 'queued', processed: 5 }
	assert.equal(vm.lastRunText, 'A reissue is waiting to run.')
	vm.lastRun = { status: 'done' }
	assert.equal(vm.lastRunText, 'Last reissue: 0 reissued, 0 skipped, 0 failed.')
})

test('a refused load says who may reissue and keeps the zero counts', async () => {
	const vm = screen(new Error('403'))
	await vm.load()
	assert.equal(vm.error, 'Only HR and compliance officers can reissue certificates.')
	assert.deepEqual(vm.counts, { issued: 0, revoked: 0, expired: 0 })
	assert.equal(vm.loading, false)
})
