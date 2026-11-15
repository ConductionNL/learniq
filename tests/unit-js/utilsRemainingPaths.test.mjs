// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The paths of the src/utils helpers that the other suites leave out: the
// SCORM shim's refusals before LMSInitialize, the custom-page URL builders,
// the enrolment ordering by creation stamp, and the guard and fallback
// branches of the board, hour-plan and block helpers.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { expandBoardDetailPages } from '../../src/utils/boardDetail.js'
import {
	coursePackagePublishUrl,
	coursePackageShareUrl,
	coursePackageUrl,
	oneObject,
} from '../../src/utils/customPages.js'
import { nextSchoolYear } from '../../src/utils/hourPlan.js'
import { newBlockId } from '../../src/utils/lessonBlocks.js'
import { currentEnrolment } from '../../src/utils/lessonCompletion.js'
import { createScorm12Api } from '../../src/utils/scorm12Runtime.js'

test('SCORM: LMSFinish and LMSCommit refuse before LMSInitialize and set error 301', () => {
	const fired = []
	const api = createScorm12Api({ onCompletion: (s) => fired.push(s) })

	assert.equal(api.LMSFinish(), 'false')
	assert.equal(api.LMSGetLastError(), '301')
	assert.equal(api.LMSCommit(), 'false')
	assert.equal(api.LMSGetLastError(), '301')
	assert.equal(fired.length, 0)

	assert.equal(api.LMSInitialize(), 'true')
	assert.equal(api.LMSCommit(), 'true')
	assert.equal(api.LMSGetLastError(), '0')
})

test('SCORM: an unknown error code reads as a general error, also through LMSGetDiagnostic', () => {
	const api = createScorm12Api()

	assert.equal(api.LMSGetErrorString('101'), 'General error')
	assert.equal(api.LMSGetErrorString('999'), 'General error')
	assert.equal(api.LMSGetDiagnostic('999'), 'General error')
	assert.equal(api.LMSGetDiagnostic('301'), 'Not initialized')
})

test('SCORM: getCmiValue reads the raw stored value, undefined for an unset element', () => {
	const api = createScorm12Api()
	api.LMSInitialize()
	api.LMSSetValue('cmi.core.score.raw', 72)

	assert.equal(api.getCmiValue('cmi.core.score.raw'), '72')
	assert.equal(api.getCmiValue('cmi.core.lesson_status'), 'not attempted')
	assert.equal(api.getCmiValue('cmi.core.unknown'), undefined)
	assert.equal(api.LMSGetValue('cmi.core.unknown'), '')
})

test('customPages: oneObject unwraps `object`, takes a bare body, and never returns null', () => {
	assert.deepEqual(oneObject({ object: { id: 'a' } }), { id: 'a' })
	assert.deepEqual(oneObject({ id: 'b' }), { id: 'b' })
	assert.deepEqual(oneObject(null), {})
	assert.deepEqual(oneObject(undefined), {})
})

test('customPages: the course-package URLs', () => {
	assert.equal(
		coursePackageUrl('c-1', 'common-cartridge'),
		'/apps/learniq/api/course-management/course-package-export?courseId=c-1&format=common-cartridge',
	)
	assert.equal(
		coursePackageUrl('a b&c', 'scholiq-json'),
		'/apps/learniq/api/course-management/course-package-export?courseId=a+b%26c&format=scholiq-json',
	)
	assert.equal(coursePackageShareUrl(), '/apps/learniq/api/course-management/course-package-share')
	assert.equal(coursePackagePublishUrl(), '/apps/learniq/api/store/publish')
})

test('lessonCompletion: currentEnrolment takes the newest active one, by @self.created, then created', () => {
	const oldest = { id: 'e1', lifecycle: 'active', '@self': { created: '2024-09-01T08:00:00Z' } }
	const newest = { id: 'e2', lifecycle: 'active', created: '2026-09-01T08:00:00Z' }
	const middle = { id: 'e3', lifecycle: 'active', '@self': { created: '2025-09-01T08:00:00Z' }, created: '2027-01-01T00:00:00Z' }
	const undated = { id: 'e4', lifecycle: 'active' }

	assert.equal(currentEnrolment([oldest, undated, newest, middle]).id, 'e2')
	assert.equal(currentEnrolment([undated, oldest]).id, 'e1')
	assert.equal(currentEnrolment([oldest, middle]).id, 'e3')
})

test('lessonCompletion: currentEnrolment ignores ended enrolments and handles no list', () => {
	assert.equal(currentEnrolment([{ id: 'x', lifecycle: 'completed', created: '2026-01-01' }]), null)
	assert.equal(currentEnrolment(null), null)
	assert.equal(currentEnrolment([
		{ id: 'p1', lifecycle: 'pending', created: '2026-01-01T00:00:00Z' },
		{ id: 'p2', lifecycle: 'pending', created: '2026-03-01T00:00:00Z' },
	]).id, 'p2')
})

test('boardDetail: a manifest without a pages array is returned as it is', () => {
	const noPages = { menu: [] }
	const pagesNotArray = { pages: 'x' }

	assert.equal(expandBoardDetailPages(noPages), noPages)
	assert.equal(expandBoardDetailPages(pagesNotArray), pagesNotArray)
	assert.equal(expandBoardDetailPages(null), null)
	assert.equal(expandBoardDetailPages(undefined), undefined)
})

test('hourPlan: nextSchoolYear moves one year on and gives "" for anything unreadable', () => {
	assert.equal(nextSchoolYear('2026-2027'), '2027-2028')
	assert.equal(nextSchoolYear('1999-2000'), '2000-2001')
	assert.equal(nextSchoolYear('2026/2027'), '')
	assert.equal(nextSchoolYear('2026-27'), '')
	assert.equal(nextSchoolYear(''), '')
	assert.equal(nextSchoolYear(undefined), '')
})

test('lessonBlocks: newBlockId falls back to a time-and-random id without crypto.randomUUID', () => {
	const original = Object.getOwnPropertyDescriptor(globalThis, 'crypto')
	Object.defineProperty(globalThis, 'crypto', { value: undefined, configurable: true, writable: true })
	try {
		const first = newBlockId()
		const second = newBlockId()
		assert.match(first, /^\d+-[a-z0-9]{1,8}$/)
		assert.notEqual(first, second)
	} finally {
		Object.defineProperty(globalThis, 'crypto', original)
	}
	assert.match(newBlockId(), /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
})
