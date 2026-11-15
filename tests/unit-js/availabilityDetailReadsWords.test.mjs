// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The teacher availability page reads its times as words, like the list
// (lq-names-2, 2026-10-05). The list reads "do 22 okt, 18:00–20:00" through
// the timeBlocks formatter; the detail page's data widget showed the same
// blocks as raw timestamps. The data widget's field override names the same
// formatter (nextcloud-vue: CnObjectDataWidget honours overrides[key].formatter).
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
// @spec openspec/changes/availability-detail-reads-words/specs/parent-conferences/spec.md

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { createFormatters } from '../../src/utils/timeBlocks.js'

const PAGES = JSON.parse(
	readFileSync(new URL('../../src/manifest.d/guardian-meetings.json', import.meta.url), 'utf8'),
).pages

/**
 * The teacher availability page's data widget content.
 *
 * @return {object}
 */
function dataWidget() {
	const page = PAGES.find((p) => p.id === 'TeacherAvailabilityDetail')
	return page.config.widgets.find((w) => w.type === 'data').content
}

test('the availability page shows its times through the list\'s formatter', () => {
	const content = dataWidget()
	assert.ok(content.include.includes('blocks'))
	assert.equal(content.overrides.blocks.formatter, 'timeBlocks')
})

test('the formatter the page names is the one learniq registers', () => {
	const formatters = createFormatters(() => 'nl-NL')
	const id = dataWidget().overrides.blocks.formatter
	assert.equal(typeof formatters[id], 'function')
	const text = formatters[id]([
		{ startsAt: '2026-10-22T16:00:00+00:00', endsAt: '2026-10-22T18:00:00+00:00' },
	])
	assert.match(text, /22 okt/)
	assert.doesNotMatch(text, /startsAt|\{|T16:00/)
})
