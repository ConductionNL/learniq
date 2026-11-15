// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The export page turns a publish outcome and a sharing-gate reason into one
// sentence each. These pin the sentences ExportRequestView's publishText and
// blockerText switches returned before they became lookups in
// src/utils/exportOutcomeTexts.js (untranslated here, so the English source).
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { blockerText, publishText } from '../../src/utils/exportOutcomeTexts.js'

test('publishText has one sentence per outcome and a default', () => {
	const unavailable = 'The course marketplace could not take the course. Try again later.'
	assert.equal(publishText('not_configured'), 'No course marketplace is set up yet. Ask your administrator.')
	assert.equal(publishText('too_large'), 'This course is too large for the marketplace.')
	assert.equal(publishText('store_unreachable'), unavailable)
	assert.equal(publishText('store_rejected'), unavailable)
	assert.equal(publishText('store_invalid_response'), unavailable)
	assert.equal(publishText('rate_limited'), 'The course marketplace is busy. Try again in a few minutes.')
	assert.equal(publishText('forbidden'), 'You may not publish courses to the marketplace. Your administrator decides who may.')
	assert.equal(publishText('publish_not_supported'), 'This server cannot publish to a course marketplace yet. Ask your administrator to update OpenRegister.')
	for (const other of [undefined, null, '', 'ok', 'constructor', 'toString']) {
		assert.equal(publishText(other), 'The course could not be published.')
	}
})

test('blockerText has one sentence per reason, naming the item', () => {
	assert.equal(blockerText({ code: 'licence-missing' }), 'Set a licence on the course first.')
	assert.equal(blockerText({ code: 'licence-not-open' }), 'The course licence does not allow sharing. Choose an open licence.')
	assert.equal(blockerText({ code: 'author-missing' }), 'Name the author on the course, so others can credit them.')
	assert.equal(blockerText({ code: 'lesson-licence-not-open', name: 'Intro' }), 'Lesson Intro has a licence that does not allow sharing.')
	assert.equal(blockerText({ code: 'material-licence-not-open', name: 'Sheet' }), 'Material Sheet has a licence that does not allow sharing.')
	assert.equal(blockerText({ code: 'lesson-licence-not-open' }), 'Lesson  has a licence that does not allow sharing.')
	assert.equal(blockerText({ code: 'pupil-data-not-confirmed' }), 'Confirm that the package holds no pupil data.')
	assert.equal(blockerText({ code: 'rights-not-confirmed' }), 'Confirm that the school may share everything in it.')
})

test('blockerText shows an unknown code, or nothing without one', () => {
	assert.equal(blockerText({ code: 'something-new' }), 'something-new')
	assert.equal(blockerText({ code: 'constructor' }), 'constructor')
	assert.equal(blockerText({}), '')
	assert.equal(blockerText(null), '')
	assert.equal(blockerText(undefined), '')
})
