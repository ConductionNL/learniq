// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// A programme card reflects the sign-up the way a course card does.
// Run via `node --test tests/unit-js/` (package.json's `test:js-unit`).
//
// Reported live: after signing up for a PROGRAMME the card said "Done." and
// kept offering "Sign up". The catalogue returned no `enrolment` for a
// programme card, so the card could not know. CatalogueReader now summarises
// the per-course enrolments a programme sign-up creates; these tests pin what
// the card does with that summary.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { canWithdraw, isLive, withdrawIds } from '../../src/utils/catalogueCard.js'

const programme = (enrolment) => ({ id: 'p-pm', kind: 'programme', courseIds: ['c-1', 'c-2'], enrolment })
const course = (enrolment) => ({ id: 'c-1', kind: 'course', enrolment })

test('a programme without a sign-up offers Sign up', () => {
	assert.equal(isLive(programme(null)), false)
	assert.equal(canWithdraw(programme(null)), false)
	assert.deepEqual(withdrawIds(programme(null)), [])
})

test('a programme the learner signed up for is live and offers Withdraw for every enrolment it created', () => {
	const card = programme({ id: 'e-1', ids: ['e-1', 'e-2'], lifecycle: 'active', source: 'self', progressPercent: 0 })
	assert.equal(isLive(card), true)
	assert.equal(canWithdraw(card), true)
	assert.deepEqual(withdrawIds(card), ['e-1', 'e-2'])
})

test('a pending programme sign-up is live too', () => {
	assert.equal(isLive(programme({ id: 'e-1', ids: ['e-1'], lifecycle: 'pending', source: 'self', progressPercent: 0 })), true)
})

test('a programme with progress, or placed by someone else, is live but not withdrawable', () => {
	assert.equal(canWithdraw(programme({ id: 'e-1', ids: ['e-1'], lifecycle: 'active', source: 'self', progressPercent: 10 })), false)
	assert.equal(canWithdraw(programme({ id: 'e-1', ids: ['e-1', 'e-2'], lifecycle: 'active', source: 'mixed', progressPercent: 0 })), false)
	assert.equal(isLive(programme({ id: 'e-1', ids: ['e-1'], lifecycle: 'active', source: 'hr', progressPercent: 0 })), true)
})

test('a course card keeps working: its one enrolment is what Withdraw withdraws', () => {
	const card = course({ id: 'e-9', lifecycle: 'active', source: 'self', progressPercent: 0 })
	assert.equal(isLive(card), true)
	assert.equal(canWithdraw(card), true)
	assert.deepEqual(withdrawIds(card), ['e-9'])
	assert.equal(isLive(course({ id: 'e-9', lifecycle: 'withdrawn', source: 'self' })), false)
})

test('the catalogue view uses these rules and withdraws every id', () => {
	const view = readFileSync(new URL('../../src/views/CourseCatalogue.vue', import.meta.url), 'utf8')
	assert.match(view, /from '\.\.\/utils\/catalogueCard\.js'/)
	assert.match(view, /for \(const id of withdrawIds\(entry\)\)/)
})
