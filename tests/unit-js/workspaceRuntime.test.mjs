// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Unit tests for the workspace runtime helper (segment-runtime-bridge).
// Run via `node --test tests/unit-js/` (package.json's `test:js-unit`).

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import {
	buildWorkspaceRuntime,
	DEFAULT_SEGMENT,
	resolveSegment,
	SEGMENTS,
} from '../../src/utils/workspaceRuntime.js'

const register = JSON.parse(
	readFileSync(
		new URL('../../lib/Settings/learniq_register.json', import.meta.url),
		'utf8',
	),
)
const segmentProperty =
	register.components.schemas.LearniqSettings.properties.segment

test('SEGMENTS matches the LearniqSettings.segment enum in order', () => {
	assert.deepEqual([...SEGMENTS], segmentProperty.enum)
	assert.equal(DEFAULT_SEGMENT, segmentProperty.default)
})

test('a known segment passes through', () => {
	for (const code of SEGMENTS) {
		assert.equal(resolveSegment(code), code)
	}
})

test('a missing or unknown segment becomes the default', () => {
	for (const raw of [
		undefined,
		null,
		'',
		'kindergarten',
		42,
		['po'],
		{ segment: 'po' },
	]) {
		assert.equal(
			resolveSegment(raw),
			DEFAULT_SEGMENT,
			`raw ${JSON.stringify(raw)}`,
		)
	}
})

test('buildWorkspaceRuntime sets segment and keeps other workspace keys', () => {
	assert.deepEqual(buildWorkspaceRuntime(undefined, 'po'), { segment: 'po' })
	assert.deepEqual(
		buildWorkspaceRuntime({ tenant: 'x', segment: 'vo' }, 'training'),
		{
			tenant: 'x',
			segment: 'training',
		},
	)
	assert.deepEqual(buildWorkspaceRuntime(null, undefined), {
		segment: DEFAULT_SEGMENT,
	})
})
