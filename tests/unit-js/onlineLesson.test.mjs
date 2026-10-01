// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// timetabling-online-lesson-link: a lesson's Join action opens only an https
// meeting link. Run via `npm run test:js-unit`.

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { joinUrl } from '../../src/utils/onlineLesson.js'

test('an https link is the join link', () => {
	assert.equal(
		joinUrl({ onlineMeetingUrl: 'https://meet.example.org/les-3b' }),
		'https://meet.example.org/les-3b',
	)
})

test('no link, or an unsafe one, gives no Join action', () => {
	for (const link of [
		undefined,
		null,
		'',
		'javascript:alert(1)',
		'http://meet.example.org',
		' https://x',
		'https://',
	]) {
		assert.equal(joinUrl({ onlineMeetingUrl: link }), '', String(link))
	}
	assert.equal(joinUrl(null), '')
})
