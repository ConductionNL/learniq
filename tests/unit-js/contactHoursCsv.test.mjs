// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The contact hours report's CSV download, pinned through the component's own
// method: the header, a row per course and per learner course, quoting, and
// the file name.
//
// @spec openspec/specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours

import assert from 'node:assert/strict'
import { afterEach, beforeEach, test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const component = await loadSfc('src/views/ContactHoursReport.vue', {
	'@nextcloud/l10n': { translate: (app, text) => text },
})

let downloads
let blobs
let revoked
const realCreate = URL.createObjectURL
const realRevoke = URL.revokeObjectURL

beforeEach(() => {
	downloads = []
	blobs = []
	revoked = []
	globalThis.document = {
		createElement: (tag) => ({
			tag,
			click() {
				downloads.push({ tag: this.tag, href: this.href, download: this.download })
			},
		}),
	}
	URL.createObjectURL = (blob) => {
		blobs.push(blob)
		return `blob:${blobs.length}`
	}
	URL.revokeObjectURL = (href) => revoked.push(href)
})

afterEach(() => {
	delete globalThis.document
	URL.createObjectURL = realCreate
	URL.revokeObjectURL = realRevoke
})

test('the CSV holds a row per course, then a row per learner course, each cell quoted', async () => {
	const vm = Object.assign(instanceOf(component), {
		from: '2026-08-01',
		to: '2026-11-01',
		report: {
			cohorts: [
				{
					cohortName: 'Klas "1A"',
					courses: [
						{ courseId: 'k1', courseName: 'Wiskunde', owed: 40, given: 38.5, difference: -1.5 },
						{ courseId: 'k2', courseName: 'Engels', owed: null, given: 10, difference: null },
					],
					learners: [
						{ learnerId: 'sem', courses: [{ courseId: 'k1', given: 38.5, attended: 30 }, { courseId: 'kx', given: 2, attended: 0 }] },
						{ learnerId: 'eva', courses: [] },
					],
				},
				{ cohortName: '2B', courses: [], learners: [{ learnerId: 'noor', courses: [{ courseId: 'k3', given: 1, attended: 1 }] }] },
			],
		},
	})
	vm.exportCsv()
	assert.equal(blobs.length, 1)
	assert.equal(blobs[0].type, 'text/csv')
	assert.equal(
		await blobs[0].text(),
		[
			'"group","course","owed","given","difference","learner","attended"',
			'"Klas ""1A""","Wiskunde","40","38.5","-1.5","",""',
			'"Klas ""1A""","Engels","","10","","",""',
			'"Klas ""1A""","Wiskunde","","38.5","","sem","30"',
			'"Klas ""1A""","kx","","2","","sem","0"',
			'"2B","k3","","1","","noor","1"',
		].join('\n'),
	)
	assert.deepEqual(downloads, [{ tag: 'a', href: 'blob:1', download: 'contact-hours-2026-08-01-2026-11-01.csv' }])
	assert.deepEqual(revoked, ['blob:1'])
})

test('without a report the CSV holds the header only', async () => {
	const vm = Object.assign(instanceOf(component), { from: 'a', to: 'b', report: null })
	vm.exportCsv()
	assert.equal(await blobs[0].text(), '"group","course","owed","given","difference","learner","attended"')
	assert.equal(downloads[0].download, 'contact-hours-a-b.csv')
})
