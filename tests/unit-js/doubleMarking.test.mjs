// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Double marking mode switch and final grade prefill
// (assignments-double-marking), run by `npm run test:js-unit`.

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { markingMode, prefillFinalGrade } from '../../src/utils/doubleMarking.js'

const two = { markersPerSubmission: 2 }
const allocated = { markerIds: ['j.devries', 'a.bakker'] }

test('one marker per submission keeps the single marking flow', () => {
	assert.equal(markingMode({ markersPerSubmission: 1 }, allocated, null), 'single')
	assert.equal(markingMode({}, allocated, null), 'single')
	assert.equal(markingMode(two, { markerIds: [] }, null), 'single')
})

test('a marker with a draft mark scores into their own mark', () => {
	assert.equal(markingMode(two, allocated, { complete: false, ownMark: { lifecycle: 'draft' } }), 'marker')
})

test('a marker who handed in waits until every mark is in', () => {
	assert.equal(markingMode(two, allocated, { complete: false, ownMark: { lifecycle: 'submitted' } }), 'waiting')
})

test('every mark in opens the final grade', () => {
	assert.equal(markingMode(two, allocated, { complete: true, ownMark: null }), 'final')
})

test('a refused marks read means the user is not allocated', () => {
	assert.equal(markingMode(two, allocated, null), 'not-allocated')
})

test('the average rule proposes the average, highest the highest, manual nothing', () => {
	const summary = { average: 7.15, highest: 7.5 }
	assert.equal(prefillFinalGrade('average', summary), 7.15)
	assert.equal(prefillFinalGrade('highest', summary), 7.5)
	assert.equal(prefillFinalGrade('manual', summary), null)
	assert.equal(prefillFinalGrade('average', null), null)
})
