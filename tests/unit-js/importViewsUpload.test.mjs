// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The two file-import screens, pinned: picking a file, posting it as form
// data with the request token, showing the server's report or its error,
// filtering the report's entries by outcome, and starting over.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
//
// @spec openspec/specs/course-management/spec.md

import assert from 'node:assert/strict'
import { afterEach, beforeEach, mock, test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const STUBS = { '@nextcloud/router': { generateUrl: (url) => `/nc${url}` } }

let calls
let answer
let errors

beforeEach(() => {
	calls = []
	errors = []
	answer = { status: 200, json: { lifecycle: 'succeeded', entries: [] } }
	globalThis.window = { OC: { requestToken: 'rt' } }
	globalThis.fetch = async (url, init) => {
		calls.push({ url, method: init.method, headers: init.headers, fields: [...init.body.entries()].map(([k, v]) => [k, typeof v === 'string' ? v : v.name]) })
		return { ok: answer.status < 300, status: answer.status, json: async () => answer.json }
	}
	mock.method(console, 'error', (...args) => errors.push(args))
})

afterEach(() => {
	mock.restoreAll()
	delete globalThis.fetch
	delete globalThis.window
})

const SCREENS = [
	{
		file: 'src/views/CoursePackageImportView.vue',
		upload: 'uploadPackage',
		props: {},
		url: '/nc/apps/learniq/api/course-management/course-package-import',
		fields: [['file', 'pack.zip']],
		failure: 'Failed to import the package. Please try again.',
		label: '[CoursePackageImportView] uploadPackage error',
	},
	{
		file: 'src/views/LearningRecordImportView.vue',
		upload: 'uploadBundle',
		props: { applicationId: 'app1' },
		data: { sourceFormat: 'edci' },
		url: '/nc/apps/learniq/api/applications/app1/learning-record-imports',
		fields: [['file', 'pack.zip'], ['sourceFormat', 'edci']],
		failure: 'Failed to upload the bundle. Please try again.',
		label: '[LearningRecordImportView] uploadBundle error',
	},
]

for (const screen of SCREENS) {
	test(`${screen.file}: a picked file is posted with the request token and its report shown`, async () => {
		const vm = instanceOf(await loadSfc(screen.file, STUBS), screen.props)
		Object.assign(vm, screen.data ?? {})
		vm.uploadError = 'old'
		vm.onFileSelected({ target: { files: [new File(['x'], 'pack.zip')] } })
		assert.equal(vm.selectedFileName, 'pack.zip')
		assert.equal(vm.uploadError, null)
		await vm[screen.upload]()
		assert.deepEqual(calls, [{ url: screen.url, method: 'POST', headers: { 'OCS-APIREQUEST': 'true', requesttoken: 'rt' }, fields: screen.fields }])
		assert.deepEqual(vm.report, { lifecycle: 'succeeded', entries: [] })
		assert.equal(vm.uploading, false)

		globalThis.window = {}
		calls = []
		await vm[screen.upload]()
		assert.equal(calls[0].headers.requesttoken, '')
	})

	test(`${screen.file}: a refused upload is reported with the server's reason, and nothing is sent without a file`, async () => {
		const vm = instanceOf(await loadSfc(screen.file, STUBS), screen.props)
		Object.assign(vm, screen.data ?? {})
		vm.onFileSelected({ target: { files: [new File(['x'], 'pack.zip')] } })
		for (const [json, message] of [[{ error: 'Too big' }, 'Too big'], [{}, 'Import failed: 413']]) {
			answer = { status: 413, json }
			vm.report = null
			await vm[screen.upload]()
			assert.equal(vm.report, null)
			assert.equal(vm.uploadError, screen.failure)
			assert.equal(errors.at(-1)[0], screen.label)
			assert.equal(errors.at(-1)[1].message, message)
			assert.equal(vm.uploading, false)
		}
		vm.onFileSelected({ target: { files: [] } })
		assert.equal(vm.selectedFile, null)
		assert.equal(vm.selectedFileName, '')
		vm.onFileSelected({ target: {} })
		calls = []
		await vm[screen.upload]()
		assert.deepEqual(calls, [])
	})

	test(`${screen.file}: the report filters by outcome, and starting over clears the screen`, async () => {
		const vm = instanceOf(await loadSfc(screen.file, STUBS), screen.props)
		assert.deepEqual(vm.filteredEntries, [])
		vm.report = { entries: [{ id: 1, outcome: 'imported' }, { id: 2, outcome: 'dropped' }] }
		assert.deepEqual(vm.filteredEntries.map((e) => e.id), [1, 2])
		vm.outcomeFilter = 'dropped'
		assert.deepEqual(vm.filteredEntries.map((e) => e.id), [2])
		const input = { value: 'C:/pack.zip' }
		vm.$refs = { fileInput: input }
		vm.selectedFile = {}
		vm.selectedFileName = 'pack.zip'
		vm.uploadError = 'x'
		vm.reset()
		assert.deepEqual([vm.report, vm.selectedFile, vm.selectedFileName, vm.outcomeFilter, vm.uploadError, input.value], [null, null, '', 'all', null, ''])
		vm.$refs = {}
		vm.reset()
		assert.equal(vm.outcomeFilter, 'all')
	})
}

test('without an application the bundle screen sends nothing', async () => {
	const vm = instanceOf(await loadSfc('src/views/LearningRecordImportView.vue', STUBS), { applicationId: '' })
	vm.onFileSelected({ target: { files: [new File(['x'], 'pack.zip')] } })
	await vm.uploadBundle()
	assert.deepEqual(calls, [])
})
