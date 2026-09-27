// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// learniq#952: recording one external training for many learners had no
// screen, so POST /api/external-training/bulk, /{recordId}/credential and
// /coverage had no caller in src/. These tests pin the screen's wiring (a
// registered custom page, reachable from the External training index) and
// the pure payload builder the view posts with.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	buildBulkPayload,
	BULK_URL,
	bulkMissingFields,
	coverageUrl,
	credentialUrl,
} from '../../src/utils/externalTrainingBulk.js'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const read = (p) => readFileSync(resolve(root, p), 'utf8')
const compliance = JSON.parse(read('src/manifest.d/compliance.json'))

test('a custom page renders the bulk screen and its component is registered', () => {
	const pageDef = compliance.pages.find(
		(p) => p.id === 'ExternalTrainingBulkRecord',
	)
	assert.ok(pageDef, 'ExternalTrainingBulkRecord page is missing')
	assert.equal(pageDef.type, 'custom')
	assert.match(
		read('src/registry.js'),
		new RegExp(`\\b${pageDef.component}: page\\(${pageDef.component}\\)`),
	)
})

test('the External training index links to the bulk screen', () => {
	const index = compliance.pages.find((p) => p.id === 'ExternalTrainingRecords')
	const actions = index.config.headerActions || []
	assert.ok(
		actions.some(
			(a) =>
				a.handler === 'navigate' && a.route === 'ExternalTrainingBulkRecord',
		),
		'no header action navigates to ExternalTrainingBulkRecord',
	)
})

test('the view calls all three external-training routes', () => {
	const view = read('src/views/ExternalTrainingBulkRecordView.vue')
	for (const name of ['BULK_URL', 'credentialUrl', 'coverageUrl']) {
		assert.ok(view.includes(name), `view does not use ${name}`)
	}
	assert.equal(BULK_URL, '/apps/learniq/api/external-training/bulk')
	assert.equal(
		credentialUrl('abc'),
		'/apps/learniq/api/external-training/abc/credential',
	)
	assert.equal(
		coverageUrl('l 1', 'vca/basis'),
		'/apps/learniq/api/external-training/coverage?learnerId=l%201&regulationSlug=vca%2Fbasis',
	)
})

test('the payload carries the learner ids once, the shared training and the learners tenant', () => {
	const payload = buildBulkPayload({
		learners: [
			{ id: 'a', tenant_id: 't1' },
			{ id: 'b', tenant_id: 't1' },
			{ id: 'a', tenant_id: 't1' },
		],
		training: {
			title: ' First aid ',
			provider: 'Red Cross',
			kind: 'classroom',
			completedAt: '2026-09-01',
			validUntil: '',
			regulationSlug: 'bhv',
			evidenceNote: '',
		},
	})
	assert.deepEqual(payload, {
		learnerIds: ['a', 'b'],
		training: {
			title: 'First aid',
			provider: 'Red Cross',
			kind: 'classroom',
			completedAt: '2026-09-01T00:00:00.000Z',
			regulationSlug: 'bhv',
			tenant_id: 't1',
		},
	})
})

test('missing fields are named before anything is posted', () => {
	assert.deepEqual(
		bulkMissingFields({ learners: [], training: { title: '', provider: 'x' } }),
		['learners', 'title', 'completedAt'],
	)
	assert.deepEqual(
		bulkMissingFields({
			learners: [{ id: 'a' }],
			training: { title: 't', provider: 'p', completedAt: '2026-01-01' },
		}),
		[],
	)
})
