// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// A learner hands in a file from her own Nextcloud files: the hand-in dialog's
// "Choose from my files" opens the file picker, reads the picked paths over
// WebDAV in her own session and adds them to the dialog as files, which the
// existing upload attaches to the submission (capability-rows-say-what-they-do 2.5).
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
//
// @spec openspec/changes/capability-rows-say-what-they-do/specs/assignments/spec.md#requirement-a-learner-hands-in-a-file-from-their-own-nextcloud-files

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { davFileUrl, fileNameOf, filesFromMyFiles } from '../../src/utils/myFiles.js'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

/**
 * A transport that answers every WebDAV read with a blob of the path's name.
 *
 * @param {string[]} seen The addresses asked, filled in.
 * @return {object} The transport.
 */
function davTransport(seen) {
	return {
		get: async (url, options) => {
			seen.push([url, options.responseType])
			return { data: new Blob(['bytes of ' + url], { type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' }), headers: {} }
		},
	}
}

test('the WebDAV address encodes the user and every segment, and drops dot segments', () => {
	assert.equal(davFileUrl('/remote.php/dav/', 'sem', '/Documenten/School/verslag 3.docx'), '/remote.php/dav/files/sem/Documenten/School/verslag%203.docx')
	assert.equal(davFileUrl('/remote.php/dav', 'a b', '/../x/./y.pdf'), '/remote.php/dav/files/a%20b/x/y.pdf')
	assert.equal(fileNameOf('/Documenten/verslag.docx'), 'verslag.docx')
	assert.equal(fileNameOf(''), 'file')
})

test('a learner picks a report from her drive: it is read as a file with its name and type', async () => {
	const seen = []
	const files = await filesFromMyFiles('/Documenten/verslag.docx', { http: davTransport(seen), remoteBase: '/remote.php/dav', uid: 'sem' })

	assert.deepEqual(seen, [['/remote.php/dav/files/sem/Documenten/verslag.docx', 'blob']])
	assert.equal(files.length, 1)
	assert.equal(files[0].name, 'verslag.docx')
	assert.match(files[0].type, /wordprocessingml/)
	assert.match(await files[0].text(), /verslag\.docx$/)
})

test('several picked paths become several files; nothing picked reads nothing', async () => {
	const seen = []
	const files = await filesFromMyFiles(['/a.pdf', '', '/b.pdf'], { http: davTransport(seen), remoteBase: '/dav', uid: 'sem' })
	assert.deepEqual(files.map((file) => file.name), ['a.pdf', 'b.pdf'])
	assert.deepEqual(await filesFromMyFiles([], { http: davTransport([]), remoteBase: '/dav', uid: 'sem' }), [])
})

/**
 * The hand-in view with a file picker that answers `picked`.
 *
 * @param {Function} pick What the picker's pick() does.
 * @param {object} http The transport.
 * @return {Promise<object>} The instance.
 */
async function view(pick, http) {
	const builder = { setMultiSelect: () => builder, allowDirectories: () => builder, addButton: () => builder, build: () => ({ pick }) }
	const component = await loadSfc('src/views/SubmitWorkView.vue', {
		'@nextcloud/axios': { default: http },
		'@nextcloud/dialogs': { getFilePickerBuilder: () => builder },
		'@nextcloud/router': { generateUrl: (url) => '/nc' + url, generateRemoteUrl: (service) => '/remote.php/' + service },
		'@nextcloud/auth': { getCurrentUser: () => ({ uid: 'sem' }) },
	})
	return instanceOf(component)
}

test('Choose from my files hands the picked file to the dialog', async () => {
	const seen = []
	const vm = await view(async () => '/Documenten/verslag.docx', davTransport(seen))
	const added = []

	await vm.chooseFromMyFiles((files) => {
		added.push(...files)
		return true
	})

	assert.deepEqual(added.map((file) => file.name), ['verslag.docx'])
	assert.equal(seen[0][0], '/remote.php/dav/files/sem/Documenten/verslag.docx')
	assert.equal(vm.pickError, '')
})

test('closing the picker adds nothing and says nothing; a failed read says so', async () => {
	const closed = await view(async () => { throw new Error('closed') }, davTransport([]))
	let called = false
	await closed.chooseFromMyFiles(() => { called = true })
	assert.equal(called, false)
	assert.equal(closed.pickError, '')

	const broken = await view(async () => '/gone.pdf', { get: async () => { throw new Error('404') } })
	await broken.chooseFromMyFiles(() => { called = true })
	assert.equal(called, false)
	assert.equal(broken.pickError, 'This file could not be read from your files.')
})
