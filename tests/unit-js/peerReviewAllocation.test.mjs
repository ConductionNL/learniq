// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// peer-review-allocation-trigger: when the peer review section shows, whether
// it offers the allocate button, and how it reads the endpoint's answer.
//
// @spec openspec/changes/peer-review-allocation-trigger/specs/assignments/spec.md#requirement-a-teacher-allocates-peer-reviewers-from-the-assignment-page

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	allocationOutcome,
	peerReviewPanel,
} from '../../src/utils/peerReviewAllocation.js'

const teacher = ['teacher', 'student']
const now = new Date('2026-09-27T12:00:00Z')

test('a teacher sees the button on a round-robin or random assignment', () => {
	for (const strategy of ['round-robin', 'random']) {
		const panel = peerReviewPanel(
			{ peerReviewEnabled: true, peerReviewAllocationStrategy: strategy },
			teacher,
			now,
		)
		assert.equal(panel.visible, true)
		assert.equal(panel.canAllocate, true)
		assert.equal(panel.strategy, strategy)
	}
})

test('manual allocation shows the section without a button', () => {
	const panel = peerReviewPanel(
		{ peerReviewEnabled: true, peerReviewAllocationStrategy: 'manual' },
		teacher,
		now,
	)
	assert.equal(panel.visible, true)
	assert.equal(panel.canAllocate, false)
})

test('pupils and assignments without peer review show nothing', () => {
	assert.equal(
		peerReviewPanel({ peerReviewEnabled: true }, ['student'], now).visible,
		false,
	)
	assert.equal(
		peerReviewPanel({ peerReviewEnabled: false }, teacher, now).visible,
		false,
	)
	assert.equal(peerReviewPanel({}, teacher, now).visible, false)
})

test('defaults: round-robin and two reviewers; the configured count wins', () => {
	const bare = peerReviewPanel({ peerReviewEnabled: true }, teacher, now)
	assert.equal(bare.strategy, 'round-robin')
	assert.equal(bare.reviewersPerSubmission, 2)
	assert.equal(
		peerReviewPanel(
			{ peerReviewEnabled: true, peerReviewersPerSubmission: 3 },
			teacher,
			now,
		).reviewersPerSubmission,
		3,
	)
	assert.equal(
		peerReviewPanel(
			{ peerReviewEnabled: true, peerReviewAllocationStrategy: 'bogus' },
			teacher,
			now,
		).strategy,
		'round-robin',
	)
})

test('before the deadline the section says allocating again adds reviewers', () => {
	assert.equal(
		peerReviewPanel(
			{ peerReviewEnabled: true, dueAt: '2026-10-01T00:00:00Z' },
			teacher,
			now,
		).beforeDeadline,
		true,
	)
	assert.equal(
		peerReviewPanel(
			{ peerReviewEnabled: true, dueAt: '2026-09-01T00:00:00Z' },
			teacher,
			now,
		).beforeDeadline,
		false,
	)
})

test('the endpoint answer reads as created, complete or empty', () => {
	assert.deepEqual(
		allocationOutcome({ result: { createdCount: 10, submissionsProcessed: 5 } }),
		{ kind: 'created', created: 10, processed: 5 },
	)
	assert.equal(
		allocationOutcome({ result: { createdCount: 0, submissionsProcessed: 5 } })
			.kind,
		'complete',
	)
	assert.equal(
		allocationOutcome({ result: { createdCount: 0, submissionsProcessed: 0 } })
			.kind,
		'empty',
	)
	assert.equal(allocationOutcome(undefined).kind, 'empty')
})
