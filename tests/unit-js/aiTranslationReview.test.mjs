// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// ai-translated-catalogue-review: the filter and paging behind the
// AI-translated strings section of the learniq admin settings, and the section
// being mounted there at all (a section nobody mounts reviews nothing).
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	filterReviewItems,
	PAGE_SIZE,
	pageOf,
	reviewText,
} from '../../src/utils/aiTranslationReview.js'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')

const ITEMS = [
	{ key: 'Publish marks', source: 'Publish marks', value: 'Cijfers publiceren' },
	{
		key: 'Everyone has handed in.',
		source: 'Everyone has handed in.',
		value: 'Iedereen heeft ingeleverd.',
	},
	{
		key: '_%n mark published._::_%n marks published._',
		source: '_%n mark published._::_%n marks published._',
		value: ['%n cijfer gepubliceerd.', '%n cijfers gepubliceerd.'],
	},
]

test('a plural value reads as its forms joined, a missing value as empty', () => {
	assert.equal(
		reviewText(ITEMS[2].value),
		'%n cijfer gepubliceerd. / %n cijfers gepubliceerd.',
	)
	assert.equal(reviewText(null), '')
	assert.equal(reviewText(undefined), '')
	assert.equal(reviewText('Cijfers publiceren'), 'Cijfers publiceren')
})

test('the filter matches the English source and the Dutch value, case-insensitively', () => {
	assert.deepEqual(
		filterReviewItems(ITEMS, 'CIJFER').map((i) => i.key),
		['Publish marks', '_%n mark published._::_%n marks published._'],
	)
	assert.deepEqual(
		filterReviewItems(ITEMS, 'handed').map((i) => i.key),
		['Everyone has handed in.'],
	)
	assert.equal(
		filterReviewItems(ITEMS, '   '),
		ITEMS,
		'an empty filter keeps the list itself',
	)
	assert.deepEqual(filterReviewItems(ITEMS, 'nothing like this'), [])
})

test('pages hold fifty rows and never run past the end', () => {
	const many = Array.from({ length: 120 }, (_, i) => ({ key: `k${i}` }))
	assert.equal(PAGE_SIZE, 50)
	assert.equal(pageOf(many, 0).length, 50)
	assert.equal(pageOf(many, 2).length, 20)
	assert.equal(pageOf(many, 2)[0].key, 'k100')
	assert.deepEqual(pageOf(many, 3), [])
	assert.equal(pageOf(many, -1)[0].key, 'k0')
})

test('the section is mounted in the admin settings and calls the admin endpoints', () => {
	const adminRoot = readFileSync(
		resolve(root, 'src/views/settings/AdminRoot.vue'),
		'utf8',
	)
	assert.match(adminRoot, /<AiTranslationReviewSection \/>/)
	const section = readFileSync(
		resolve(root, 'src/views/settings/AiTranslationReviewSection.vue'),
		'utf8',
	)
	assert.match(section, /\/apps\/learniq\/api\/l10n\/ai-translated'/)
	assert.match(section, /\/apps\/learniq\/api\/l10n\/ai-translated\/reviewed'/)
	const routes = readFileSync(resolve(root, 'appinfo/routes.php'), 'utf8')
	assert.match(
		routes,
		/'aiTranslationReview#index',\s+'url' => '\/api\/l10n\/ai-translated',\s+'verb' => 'GET'/,
	)
	assert.match(
		routes,
		/'aiTranslationReview#reviewed', 'url' => '\/api\/l10n\/ai-translated\/reviewed', 'verb' => 'POST'/,
	)
})
