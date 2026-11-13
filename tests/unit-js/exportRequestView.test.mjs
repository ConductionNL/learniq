// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The export page (ExportRequestView), through the component's own methods
// with a stubbed axios: when each kind's form is ready, which endpoint each
// kind calls with which body, how a download is named, and how the sharing
// gate's reasons, a server error and a publish outcome reach the screen.
//
// @spec openspec/specs/course-management/spec.md#requirement-the-export-page-offers-sharing-with-the-confirmations

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const http = { calls: [], answer: null }
const state = { storeAccess: null }

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

const component = await loadSfc('src/views/ExportRequestView.vue', {
	'@nextcloud/axios': {
		default: {
			get: (...args) => answer('get', args),
			post: (...args) => answer('post', args),
		},
	},
	'@nextcloud/initial-state': { loadState: (app, key, fallback) => (key in state ? state[key] : fallback) },
	'@nextcloud/router': { generateUrl: (path) => `/nc${path}` },
})

const clicked = []
globalThis.document = {
	createElement: (tag) => ({ tag, click() { clicked.push({ href: this.href, download: this.download }) } }),
}

/**
 * A fresh page of one kind.
 *
 * @param {string} kind The page kind.
 * @param {object} [extra] Data to set.
 * @return {object} The component instance.
 */
function page(kind, extra = {}) {
	http.calls = []
	http.answer = { data: {} }
	clicked.length = 0
	return instanceOf(component, { kind, ...extra })
}

/**
 * An axios error carrying a response body.
 *
 * @param {number} status The status.
 * @param {unknown} data The body.
 * @return {Error} The error.
 */
function refused(status, data) {
	return Object.assign(new Error(`Request failed with status code ${status}`), { response: { status, data } })
}

test('each kind has its own heading', () => {
	assert.equal(page('audit-pack').heading, 'Export an audit pack')
	assert.equal(page('course-package').heading, 'Export a course package')
	assert.equal(page('data-exchange').heading, 'Request a data export')
	assert.equal(page('anything').heading, 'Request a data export')
})

test('publishing shows only to someone the store access admits', () => {
	state.storeAccess = { publish: true }
	assert.equal(page('course-package').canPublish, true)
	state.storeAccess = { publish: 'yes' }
	assert.equal(page('course-package').canPublish, false)
	state.storeAccess = null
	assert.equal(page('course-package').canPublish, false)
})

test('an audit pack is ready with a regulation and both dates', () => {
	const vm = page('audit-pack', { regulationSlug: 'wpo', dateFrom: '2026-01-01' })
	assert.equal(vm.ready, false)
	vm.dateTo = '2026-06-30'
	assert.equal(vm.ready, true)
	vm.regulationSlug = null
	assert.equal(vm.ready, false)
})

test('a course package needs a course and a format, a share both confirmations', () => {
	const vm = page('course-package')
	assert.equal(vm.ready, false)
	vm.courseId = 'c1'
	assert.equal(vm.ready, true)
	vm.format = ''
	assert.equal(vm.ready, false)
	vm.share = true
	vm.noPupilData = true
	assert.equal(vm.ready, false)
	vm.rightsCleared = true
	assert.equal(vm.ready, true)
})

test('a data exchange is ready once a connection is chosen', () => {
	const vm = page('data-exchange')
	assert.equal(vm.ready, true)
	vm.target = ''
	assert.equal(vm.ready, false)
	assert.deepEqual(vm.targetOptions.map((o) => o.id), ['bron-rod', 'oso', 'leerplicht', 'swv', 'uwlr', 'edu-v', 'basispoort', 'hr'])
})

test('an audit pack page loads the regulations that have a slug', async () => {
	const vm = page('audit-pack')
	http.answer = { data: { results: [{ slug: 'wpo', name: 'WPO' }, { name: 'No slug' }, { slug: 'wvo' }] } }
	await component.mounted.call(vm)
	assert.deepEqual(vm.regulationOptions, [{ id: 'wpo', label: 'WPO' }, { id: 'wvo', label: 'wvo' }])
	assert.deepEqual(http.calls, [['get', '/nc/apps/openregister/api/objects/learniq/regulation', { params: { _limit: 500 } }]])
})

test('the other kinds load the courses, named by name, code or id', async () => {
	const vm = page('course-package')
	http.answer = { data: [{ id: 'c1', name: 'Wiskunde' }, { id: 'c2', code: 'NE1' }, { uuid: 'c3' }] }
	await component.mounted.call(vm)
	assert.deepEqual(vm.courseOptions, [
		{ id: 'c1', label: 'Wiskunde' },
		{ id: 'c2', label: 'NE1' },
		{ id: 'c3', label: 'c3' },
	])
	assert.equal(http.calls[0][1], '/nc/apps/openregister/api/objects/learniq/course')
})

test('choices that cannot be loaded say so', async () => {
	const vm = page('course-package')
	http.answer = refused(500, {})
	await component.mounted.call(vm)
	assert.equal(vm.error, 'The choices could not be loaded.')
	assert.deepEqual(vm.courseOptions, [])
})

test('an audit pack downloads under the server\'s file name', async () => {
	const vm = page('audit-pack', { regulationSlug: 'wpo', dateFrom: '2026-01-01', dateTo: '2026-06-30' })
	http.answer = { data: new Blob(['zip']), headers: { 'content-disposition': 'attachment; filename="wpo-2026.zip"' } }
	await vm.submit()
	assert.deepEqual(http.calls, [[
		'post',
		'/nc/apps/learniq/api/compliance/audit/export?regulationSlug=wpo&dateFrom=2026-01-01&dateTo=2026-06-30',
		{},
		{ responseType: 'blob' },
	]])
	assert.equal(clicked.length, 1)
	assert.equal(clicked[0].download, 'wpo-2026.zip')
	assert.match(clicked[0].href, /^blob:/)
	assert.equal(vm.done, 'The download has started.')
	assert.equal(vm.busy, false)
})

test('a course package downloads in the chosen format, named by the fallback', async () => {
	const vm = page('course-package', { courseId: 'c1', format: 'scholiq-json' })
	http.answer = { data: new Blob(['{}']), headers: {} }
	await vm.submit()
	assert.deepEqual(http.calls, [[
		'get',
		'/nc/apps/learniq/api/course-management/course-package-export?courseId=c1&format=scholiq-json',
		{ responseType: 'blob' },
	]])
	assert.equal(clicked[0].download, 'course-package.zip')
})

test('a share posts the course with both confirmations', async () => {
	const vm = page('course-package', { courseId: 'c1', share: true, noPupilData: true, rightsCleared: true })
	http.answer = { data: new Blob(['{}']), headers: { 'content-disposition': 'attachment; filename=share.json' } }
	await vm.submit()
	assert.deepEqual(http.calls, [[
		'post',
		'/nc/apps/learniq/api/course-management/course-package-share',
		{ courseId: 'c1', noPupilData: true, rightsCleared: true },
		{ responseType: 'blob' },
	]])
	assert.equal(clicked[0].download, 'share.json')
})

test('a data exchange hands the trimmed learner to the connection and keeps the job', async () => {
	const vm = page('data-exchange', { target: 'oso', learnerId: '  l1  ', jobId: 'old', error: 'old' })
	http.answer = { data: { jobId: 'job-9' } }
	await vm.submit()
	assert.deepEqual(http.calls, [['post', '/nc/apps/learniq/api/exchange/requests', { target: 'oso', learnerId: 'l1' }]])
	assert.equal(vm.jobId, 'job-9')
	assert.equal(vm.error, '')
	assert.equal(vm.done, 'Integriq has the export. It runs once learniq\'s checks allow it.')
})

test('a data exchange without a learner sends only the target', async () => {
	const vm = page('data-exchange', { target: 'hr' })
	http.answer = { data: {} }
	await vm.submit()
	assert.deepEqual(http.calls[0][2], { target: 'hr' })
	assert.equal(vm.jobId, '')
})

test('a refused share shows the sharing gate\'s reasons, read from the blob body', async () => {
	const vm = page('course-package', { courseId: 'c1', share: true, noPupilData: true, rightsCleared: true })
	const blockers = [{ code: 'lesson_not_open', name: 'Les 1' }]
	http.answer = refused(422, new Blob([JSON.stringify({ blockers })]))
	await vm.submit()
	assert.deepEqual(vm.blockers, blockers)
	assert.equal(vm.blockerTexts.length, 1)
	assert.equal(vm.error, '')
	assert.equal(vm.busy, false)
})

test('a refused export shows the server\'s error, else a general sentence', async () => {
	const vm = page('data-exchange')
	http.answer = refused(403, { error: 'Not allowed for this learner.' })
	await vm.submit()
	assert.equal(vm.error, 'Not allowed for this learner.')

	http.answer = refused(500, new Blob(['<html>']))
	await vm.submit()
	assert.equal(vm.error, 'The export could not be made.')

	http.answer = new Error('Network Error')
	await vm.submit()
	assert.equal(vm.error, 'The export could not be made.')
	assert.deepEqual(vm.blockers, [])
})

test('a publish sends the confirmations and reports the outcome', async () => {
	const vm = page('course-package', { courseId: 'c1', noPupilData: true, rightsCleared: true })
	http.answer = { data: { outcome: 'ok' } }
	await vm.publish()
	assert.deepEqual(http.calls, [['post', '/nc/apps/learniq/api/store/publish', { courseId: 'c1', noPupilData: true, rightsCleared: true }]])
	assert.equal(vm.done, 'Published to the course marketplace.')
	assert.equal(vm.error, '')
	assert.equal(vm.busy, false)
})

test('a publish the store declined shows that outcome\'s sentence', async () => {
	const vm = page('course-package', { courseId: 'c1' })
	http.answer = { data: { outcome: 'too_large' } }
	await vm.publish()
	assert.equal(vm.error, 'This course is too large for the marketplace.')
	assert.equal(vm.done, '')
})

test('a refused publish shows the gate\'s reasons, else the outcome', async () => {
	const vm = page('course-package', { courseId: 'c1' })
	http.answer = refused(422, { blockers: [{ code: 'rights_not_cleared' }] })
	await vm.publish()
	assert.deepEqual(vm.blockers, [{ code: 'rights_not_cleared' }])
	assert.equal(vm.error, '')

	http.answer = refused(403, { outcome: 'forbidden', blockers: [] })
	await vm.publish()
	assert.equal(vm.error, 'You may not publish courses to the marketplace. Your administrator decides who may.')
	assert.deepEqual(vm.blockers, [])

	http.answer = new Error('Network Error')
	await vm.publish()
	assert.equal(vm.error, 'The course could not be published.')
})
