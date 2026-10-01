// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// governance-wcag-evidence-report: the conformance dialog's fail-needs-a-limitation
// prompt, which limitations it offers, the summary counts and the saved payload.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	criterionNumber,
	limitationsFor,
	needsLimitation,
	recordFor,
	resultPayload,
	summarise,
} from '../../src/utils/conformance.js'

test('a failure without a limitation prompts, anything else does not', () => {
	assert.equal(needsLimitation('fail', null), true)
	assert.equal(needsLimitation('fail', ''), true)
	assert.equal(needsLimitation('fail', 'limitation-1'), false)
	assert.equal(needsLimitation('pass', null), false)
	assert.equal(needsLimitation('not-tested', undefined), false)
})

test('the dialog offers the statement limitations for the same criterion that are not fixed', () => {
	const limitations = [
		{ id: 'a', wcagCriterion: '1.4.3 Contrast (Minimum)', lifecycle: 'open' },
		{ id: 'b', wcagCriterion: '1.4.3', lifecycle: 'fixed' },
		{ id: 'c', wcagCriterion: '1.4.3', lifecycle: 'mitigated' },
		{ id: 'd', wcagCriterion: '1.4.30', lifecycle: 'open' },
		{ id: 'e', wcagCriterion: '2.1.1 Keyboard', lifecycle: 'open' },
		null,
	]
	assert.deepEqual(
		limitationsFor('1.4.3', limitations).map((l) => l.id),
		['a', 'c'],
	)
	assert.deepEqual(limitationsFor('1.4.3', undefined), [])
})

test('criterion numbers are read from the start of a reference', () => {
	assert.equal(criterionNumber('2.1.1 Keyboard'), '2.1.1')
	assert.equal(criterionNumber(' 1.4.10'), '1.4.10')
	assert.equal(criterionNumber('Keyboard'), null)
	assert.equal(criterionNumber(undefined), null)
})

test('the latest record of a criterion wins', () => {
	const records = [
		{ id: 'old', wcagCriterion: '1.4.3', testedOn: '2026-01-01' },
		{ id: 'new', wcagCriterion: '1.4.3', testedOn: '2026-09-01' },
		{ id: 'other', wcagCriterion: '2.1.1', testedOn: '2026-10-01' },
	]
	assert.equal(recordFor('1.4.3', records).id, 'new')
	assert.equal(recordFor('4.1.3', records), null)
	assert.equal(recordFor('1.4.3', null), null)
})

test('untested criteria are counted, and an unknown result counts as not tested', () => {
	const rows = [
		{ result: 'pass' },
		{ result: 'fail' },
		{ result: 'not-applicable' },
		{ result: 'not-tested' },
		{ result: 'maybe' },
	]
	assert.deepEqual(summarise(rows), {
		pass: 1,
		fail: 1,
		'not-applicable': 1,
		'not-tested': 2,
		total: 5,
	})
	assert.equal(summarise(undefined).total, 0)
})

test('the saved payload holds the schema fields only, and a limitation only for a failure', () => {
	const context = {
		statementId: 'statement-1',
		tenantId: 'tenant-a',
		criterion: '1.4.3',
		level: 'AA',
	}
	assert.deepEqual(
		resultPayload(
			{
				result: 'pass',
				method: ' axe and manual check ',
				evidenceReference: 'https://example.org/report',
				testedOn: '2026-09-01',
				limitationId: 'limitation-1',
			},
			context,
		),
		{
			accessibilityStatementId: 'statement-1',
			wcagCriterion: '1.4.3',
			level: 'AA',
			result: 'pass',
			tenant_id: 'tenant-a',
			method: 'axe and manual check',
			evidenceReference: 'https://example.org/report',
			testedOn: '2026-09-01',
		},
	)
	assert.equal(
		resultPayload({ result: 'fail', limitationId: 'limitation-1' }, context)
			.limitationId,
		'limitation-1',
	)
	assert.equal(resultPayload({ result: 'bogus' }, context).result, 'not-tested')
	assert.equal('method' in resultPayload({ result: 'pass', method: '  ' }, context), false)
})
