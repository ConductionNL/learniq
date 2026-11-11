// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// TeacherAvailability.blocks and the half hours they mark
// (src/dialogs/dialogBodies.js), the edges the board test leaves open:
// unsorted and duplicate half hours, dates out of order, a block that cannot
// be read, and a block spanning midnight. Pinned before the two functions were
// split into named steps.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { availabilityBlocks, pickedFromBlocks } from '../../src/dialogs/dialogBodies.js'

/**
 * @param {string} local A local moment.
 * @return {string} The same text, so the blocks read without a time zone.
 */
function same(local) {
	return local
}

test('dates come out in order and each date merges its own neighbours', () => {
	assert.deepEqual(availabilityBlocks({ '2026-11-12': ['20:00', '19:30'], '2026-11-10': ['19:00'] }, same), [
		{ startsAt: '2026-11-10T19:00:00', endsAt: '2026-11-10T19:30:00' },
		{ startsAt: '2026-11-12T19:30:00', endsAt: '2026-11-12T20:30:00' },
	])
	assert.deepEqual(availabilityBlocks({ '2026-11-10': [] }, same), [])
	assert.deepEqual(availabilityBlocks({}, same), [])
})

test('a repeated half hour opens a new block', () => {
	assert.deepEqual(availabilityBlocks({ '2026-11-10': ['19:00', '19:00'] }, same), [
		{ startsAt: '2026-11-10T19:00:00', endsAt: '2026-11-10T19:30:00' },
		{ startsAt: '2026-11-10T19:00:00', endsAt: '2026-11-10T19:30:00' },
	])
})

test('a block that cannot be read marks nothing; a block can span midnight', () => {
	assert.deepEqual(pickedFromBlocks([{ startsAt: 'soon', endsAt: '2026-11-10T20:00:00' }]), {})
	assert.deepEqual(pickedFromBlocks([{ startsAt: '2026-11-10T19:00:00', endsAt: 'later' }]), {})
	assert.deepEqual(pickedFromBlocks([{ startsAt: '2026-11-10T23:30:00', endsAt: '2026-11-11T00:30:00' }]), {
		'2026-11-10': ['23:30'],
		'2026-11-11': ['00:00'],
	})
	assert.deepEqual(pickedFromBlocks([
		{ startsAt: '2026-11-10T20:00:00', endsAt: '2026-11-10T20:30:00' },
		{ startsAt: '2026-11-10T19:00:00', endsAt: '2026-11-10T19:30:00' },
	]), { '2026-11-10': ['20:00', '19:00'] })
	assert.deepEqual(pickedFromBlocks({ length: 1 }), {})
})
