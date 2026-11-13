// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The admin settings sections without tests of their own, through each
// component's own methods with a stubbed axios and toast: what each loads,
// the exact body each saves (trimmed, token kept unless replaced or
// cleared), and how a failed load or save reads.
//
// @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const http = { calls: [], answers: [] }
const toasts = []

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

const stubs = {
	'@nextcloud/axios': {
		default: {
			get: (...args) => answer('get', args),
			put: (...args) => answer('put', args),
		},
	},
	'@nextcloud/dialogs': {
		showError: (text) => toasts.push(['error', text]),
		showSuccess: (text) => toasts.push(['success', text]),
	},
	'@nextcloud/router': { generateUrl: (path) => `/nc${path}` },
}

const sections = {
	timetable: await loadSfc('src/views/settings/TimetableExchangeSettingsSection.vue', stubs),
	store: await loadSfc('src/views/settings/StoreRegistrySettingsSection.vue', stubs),
	openMaterial: await loadSfc('src/views/settings/OpenMaterialSettingsSection.vue', stubs),
	lti: await loadSfc('src/views/settings/LtiSettingsSection.vue', stubs),
}

/**
 * A fresh section with these answers queued.
 *
 * @param {string} name The section key.
 * @param {Array} answers The queued answers.
 * @return {object} The component instance.
 */
function section(name, answers = []) {
	http.calls = []
	http.answers = answers
	toasts.length = 0
	return instanceOf(sections[name])
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

// --- Timetable exchange -------------------------------------------------------

test('timetable exchange loads its sources, group maps and cohorts', async () => {
	const vm = section('timetable', [
		{ data: { sources: ['roster-zermelo', 'roster-other'], groupMaps: { 'roster-zermelo': { '3B': 'c1' } }, swvReceiverId: 'swv-1' } },
		{ data: { results: [{ id: 'c1', name: '3B' }, { uuid: 'c2' }] } },
	])
	await sections.timetable.mounted.call(vm)
	assert.deepEqual(http.calls, [
		['get', '/nc/apps/learniq/api/admin/timetable-exchange'],
		['get', '/nc/apps/openregister/api/objects/learniq/cohort?_limit=500'],
	])
	assert.deepEqual(vm.rows, { 'roster-zermelo': [{ code: '3B', cohortId: 'c1' }], 'roster-other': [] })
	assert.equal(vm.swvReceiverId, 'swv-1')
	assert.equal(vm.loading, false)
	assert.deepEqual(vm.cohortOptions, [{ id: 'c1', label: '3B' }, { id: 'c2', label: 'c2' }])
	assert.equal(vm.label('roster-zermelo'), 'Zermelo')
	assert.equal(vm.label('roster-other'), 'roster-other')
})

test('timetable exchange shows a cohort by name, an unknown one by id, none as null', () => {
	const vm = section('timetable')
	vm.cohorts = [{ id: 'c1', name: '3B' }]
	assert.deepEqual(vm.cohortOption('c1'), { id: 'c1', label: '3B' })
	assert.deepEqual(vm.cohortOption('c9'), { id: 'c9', label: 'c9' })
	assert.equal(vm.cohortOption(''), null)
})

test('timetable exchange without cohorts still loads its settings', async () => {
	const vm = section('timetable', [{ data: {} }, new Error('Network Error')])
	await sections.timetable.mounted.call(vm)
	assert.deepEqual(vm.cohorts, [])
	assert.deepEqual(vm.sources, [])
	assert.deepEqual(vm.rows, {})
	assert.equal(vm.swvReceiverId, '')
	assert.equal(vm.error, '')
})

test('timetable exchange that cannot load says so', async () => {
	const vm = section('timetable', [new Error('500')])
	await vm.load()
	assert.equal(vm.error, 'The exchange settings could not be loaded.')
	assert.equal(vm.loading, false)
})

test('timetable exchange saves complete rows and the trimmed receiver', async () => {
	const vm = section('timetable')
	vm.rows = { 'roster-zermelo': [{ code: ' 3B ', cohortId: 'c1' }, { code: '', cohortId: 'c2' }, { code: '4A', cohortId: null }] }
	vm.swvReceiverId = '  swv-1  '
	http.answers = [{ data: { sources: ['roster-zermelo'], groupMaps: { 'roster-zermelo': { '3B': 'c1' } }, swvReceiverId: 'swv-1' } }]
	await vm.save()
	assert.deepEqual(http.calls, [['put', '/nc/apps/learniq/api/admin/timetable-exchange', {
		groupMaps: { 'roster-zermelo': { '3B': 'c1' } },
		swvReceiverId: 'swv-1',
	}]])
	assert.deepEqual(vm.rows, { 'roster-zermelo': [{ code: '3B', cohortId: 'c1' }] })
	assert.deepEqual(toasts, [['success', 'Exchange settings saved.']])
	assert.equal(vm.saving, false)
})

test('timetable exchange shows why a save was refused, else a general sentence', async () => {
	const vm = section('timetable', [refused({ error: 'Unknown cohort c9.' })])
	await vm.save()
	assert.equal(vm.error, 'Unknown cohort c9.')
	assert.deepEqual(toasts, [['error', 'Unknown cohort c9.']])

	http.answers = [new Error('Network Error')]
	await vm.save()
	assert.equal(vm.error, 'The exchange settings could not be saved.')
	assert.equal(vm.saving, false)
})

// --- Course marketplace connection ---------------------------------------------

test('the marketplace connection loads its url, register and whether a token is set', async () => {
	const vm = section('store', [{ data: { url: 'https://store.example', register: 'courses', tokenSet: true } }])
	await sections.store.mounted.call(vm)
	assert.deepEqual(http.calls, [['get', '/nc/apps/learniq/api/admin/store-registry']])
	assert.equal(vm.url, 'https://store.example')
	assert.equal(vm.register, 'courses')
	assert.equal(vm.tokenSet, true)
	assert.equal(vm.token, '')
	assert.match(vm.tokenHelp, /^A token is set\./)
})

test('the marketplace connection reads anything malformed as empty', async () => {
	const vm = section('store', [{ data: { url: 42, register: null, tokenSet: 'yes' } }])
	await vm.load()
	assert.equal(vm.url, '')
	assert.equal(vm.register, '')
	assert.equal(vm.tokenSet, false)
	assert.equal(vm.tokenHelp, 'The token of an account in the instructors group on the registry.')

	http.answers = [new Error('500')]
	await vm.load()
	assert.equal(vm.error, 'The course marketplace connection could not be loaded.')
})

test('the marketplace connection keeps the token unless a new one is typed', async () => {
	const vm = section('store')
	vm.url = ' https://store.example '
	vm.register = ' courses '
	vm.token = '   '
	http.answers = [{ data: { url: 'https://store.example', register: 'courses', tokenSet: true } }]
	await vm.save()
	assert.deepEqual(http.calls[0], ['put', '/nc/apps/learniq/api/admin/store-registry', {
		url: 'https://store.example',
		register: 'courses',
		clearToken: false,
	}])
	assert.deepEqual(toasts, [['success', 'Course marketplace connection saved.']])
})

test('the marketplace connection sends a new token trimmed, and none when clearing', async () => {
	const vm = section('store')
	vm.token = ' tok '
	await vm.save()
	assert.equal(http.calls[0][2].token, 'tok')
	assert.equal(vm.token, '')

	http.calls = []
	vm.token = 'tok'
	vm.clearToken = true
	await vm.save()
	assert.deepEqual(http.calls[0][2], { url: '', register: '', clearToken: true })
	assert.equal(vm.clearToken, false)
})

test('the marketplace connection shows why a save was refused', async () => {
	const vm = section('store', [refused({ error: 'The registry did not answer.' })])
	await vm.save()
	assert.equal(vm.error, 'The registry did not answer.')
	assert.deepEqual(toasts, [['error', 'The registry did not answer.']])

	http.answers = [new Error('Network Error')]
	await vm.save()
	assert.equal(vm.error, 'The course marketplace connection could not be saved.')
	assert.equal(vm.saving, false)
})

// --- Open learning material ------------------------------------------------------

test('open material loads its switch and collection lists', async () => {
	const vm = section('openMaterial', [{ data: { enabled: false, collectionsOff: ['a', 'b'], linkCollections: ['c'] } }])
	await sections.openMaterial.mounted.call(vm)
	assert.deepEqual(http.calls, [['get', '/nc/apps/learniq/api/open-material/panel']])
	assert.equal(vm.enabled, false)
	assert.equal(vm.collectionsOff, 'a, b')
	assert.equal(vm.linkCollections, 'c')
})

test('open material is on unless the panel says off', async () => {
	const vm = section('openMaterial', [{ data: null }])
	await vm.load()
	assert.equal(vm.enabled, true)
	assert.equal(vm.collectionsOff, '')

	http.answers = [new Error('500')]
	await vm.load()
	assert.equal(vm.error, 'The open learning material settings could not be loaded.')
})

test('open material saves the switch as 1/0 and the trimmed lists', async () => {
	const vm = section('openMaterial')
	vm.enabled = false
	vm.collectionsOff = ' a, b '
	vm.linkCollections = ' c '
	await vm.save()
	assert.deepEqual(http.calls, [['put', '/nc/apps/learniq/api/settings', {
		open_material_enabled: '0',
		open_material_collections_off: 'a, b',
		open_material_link_collections: 'c',
	}]])
	assert.deepEqual(toasts, [['success', 'Open learning material settings saved']])

	http.calls = []
	vm.enabled = true
	http.answers = [new Error('500')]
	await vm.save()
	assert.equal(http.calls[0][2].open_material_enabled, '1')
	assert.deepEqual(toasts.at(-1), ['error', 'The open learning material settings could not be saved.'])
	assert.equal(vm.saving, false)
})

// --- LTI ----------------------------------------------------------------------------

test('LTI loads and saves the trimmed grade-service subscription', async () => {
	const vm = section('lti', [{ data: { lti_ags_subscription_id: 'sub-1' } }])
	await sections.lti.mounted.call(vm)
	assert.equal(vm.subscriptionId, 'sub-1')
	vm.subscriptionId = ' sub-2 '
	await vm.save()
	assert.deepEqual(http.calls, [
		['get', '/nc/apps/learniq/api/settings'],
		['put', '/nc/apps/learniq/api/settings', { lti_ags_subscription_id: 'sub-2' }],
	])
	assert.deepEqual(toasts, [['success', 'LTI settings saved']])
})

test('LTI without a subscription, or failing, says so', async () => {
	const vm = section('lti', [{ data: {} }])
	await vm.load()
	assert.equal(vm.subscriptionId, '')

	http.answers = [new Error('500')]
	await vm.load()
	assert.equal(vm.error, 'The LTI settings could not be loaded.')

	http.answers = [new Error('500')]
	await vm.save()
	assert.deepEqual(toasts, [['error', 'The LTI settings could not be saved.']])
	assert.equal(vm.saving, false)
})
