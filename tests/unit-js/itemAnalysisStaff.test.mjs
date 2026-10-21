// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Item statistics are for staff: the page gates on the app's staff rule (the
// teacher or admin dashboard view), not on Nextcloud admin, so a teacher or
// an exam board member who is not an instance admin sees the figures.
//
// @spec openspec/specs/assessment/spec.md#requirement-item-and-assessment-statistics-are-read-restricted-to-staff-roles

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { canSeeHandInStatus } from '../../src/utils/handInStatus.js'

const source = readFileSync(new URL('../../src/views/ItemAnalysisView.vue', import.meta.url), 'utf8')

test('the item analysis page gates on the staff rule, not on isAdmin', () => {
	assert.doesNotMatch(source, /isAdmin/)
	assert.match(source, /canSeeHandInStatus\(\s*loadState\('learniq', 'dashboardRoles'/)
})

test('the staff rule lets teachers and the exam board in and keeps learners out', () => {
	assert.equal(canSeeHandInStatus(['teacher', 'student']), true)
	assert.equal(canSeeHandInStatus(['admin', 'student']), true)
	assert.equal(canSeeHandInStatus(['student']), false)
})
