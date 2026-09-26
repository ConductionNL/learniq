// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Unit tests for the cmi5 AU launch-URL builder (lesson-player-runtime,
// finding 5.6). Run via `node --test tests/unit-js/` (package.json's
// `test:js-unit` script), same pattern as tests/unit-js/courseOrder.test.mjs.

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { buildCmi5LaunchUrl } from '../../src/utils/cmi5Launch.js'

const VALID_LAUNCH = {
	endpoint: 'https://lrs.example/xapi/',
	fetchUrl: 'https://learniq.example/apps/learniq/api/lessons/l1/cmi5-fetch/tok123',
	actor: { objectType: 'Agent', account: { name: 'learner-1' } },
	activityId: 'https://learniq.example/activities/lessons/l1',
	registration: '11111111-1111-1111-1111-111111111111',
}

test('buildCmi5LaunchUrl: appends all five cmi5-spec query parameters', () => {
	const url = new URL(buildCmi5LaunchUrl('https://au.example/index.html', VALID_LAUNCH))
	assert.equal(url.searchParams.get('endpoint'), VALID_LAUNCH.endpoint)
	assert.equal(url.searchParams.get('fetch'), VALID_LAUNCH.fetchUrl)
	assert.deepEqual(JSON.parse(url.searchParams.get('actor')), VALID_LAUNCH.actor)
	assert.equal(url.searchParams.get('activityId'), VALID_LAUNCH.activityId)
	assert.equal(url.searchParams.get('registration'), VALID_LAUNCH.registration)
})

test('buildCmi5LaunchUrl: preserves an absolute AU launch URL\'s own origin', () => {
	const result = buildCmi5LaunchUrl('https://au.example/index.html', VALID_LAUNCH)
	assert.ok(result.startsWith('https://au.example/index.html?'))
})

test('buildCmi5LaunchUrl: preserves a relative AU launch URL as relative (no placeholder origin leaks)', () => {
	const result = buildCmi5LaunchUrl('/apps/learniq/au/lesson-l1/index.html', VALID_LAUNCH)
	assert.ok(result.startsWith('/apps/learniq/au/lesson-l1/index.html?'), `unexpected: ${result}`)
	assert.ok(!result.includes('placeholder.invalid'), `leaked placeholder origin: ${result}`)
})

test('buildCmi5LaunchUrl: throws when auLaunchUrl is missing', () => {
	assert.throws(() => buildCmi5LaunchUrl('', VALID_LAUNCH), /auLaunchUrl is required/)
})

test('buildCmi5LaunchUrl: throws when any required launch field is missing', () => {
	for (const field of ['endpoint', 'fetchUrl', 'actor', 'activityId', 'registration']) {
		const broken = { ...VALID_LAUNCH, [field]: undefined }
		assert.throws(
			() => buildCmi5LaunchUrl('https://au.example/index.html', broken),
			new RegExp(`launch\\.${field} is required`),
			`missing ${field} MUST throw`,
		)
	}
})
