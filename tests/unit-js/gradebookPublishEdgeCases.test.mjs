// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The gradebook publish helpers given no entries, no outcomes and outcomes
// without an entry or reason.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	ALL_COMPONENTS,
	distribution,
	publishable,
	publishReport,
	scopeEntries,
} from '../../src/utils/gradebookPublish.js'

test('no entries are in scope and none are publishable', () => {
	assert.deepEqual(scopeEntries(null, ALL_COMPONENTS), [])
	assert.deepEqual(publishable(undefined), [])
})

test('a null entry is in scope for all components but not for one', () => {
	assert.deepEqual(scopeEntries([null], ALL_COMPONENTS), [null])
	assert.deepEqual(scopeEntries([null], 'c1'), [])
})

test('no entries give an empty distribution', () => {
	assert.deepEqual(distribution(null), {
		count: 0,
		average: null,
		lowest: null,
		highest: null,
		passing: null,
		bands: [],
	})
})

test('no outcomes report nothing published and nothing refused', () => {
	assert.deepEqual(publishReport(null), { published: 0, refused: [] })
})

test('a refusal without an entry or reason is reported with empty strings', () => {
	assert.deepEqual(publishReport([{ ok: false }, { ok: true, entry: { learnerId: 'a' } }, { ok: false, entry: { learnerId: 'b' }, reason: 'locked' }]), {
		published: 1,
		refused: [
			{ learnerId: '', reason: '' },
			{ learnerId: 'b', reason: 'locked' },
		],
	})
})
