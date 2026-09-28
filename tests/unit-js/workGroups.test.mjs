// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Group hand-in learners from the work group set
// (enrolment-self-join-work-group), run by `npm run test:js-unit`.

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { handInLearners } from '../../src/utils/workGroups.js'

const sets = [
	{
		cohortId: 'coh-1',
		setName: 'Campagne',
		groups: [
			{ mine: false, memberIds: [] },
			{ mine: true, memberIds: ['a', 'b', 'me', 'c'] },
		],
	},
]

test('a group hand-in names the whole work group, the caller first', () => {
	const assignment = { groupSubmission: true, workGroupSetName: 'Campagne', cohortId: 'coh-1' }
	assert.deepEqual(handInLearners(assignment, sets, 'me'), ['me', 'a', 'b', 'c'])
})

test('without a set, another cohort or no own group only the caller hands in', () => {
	assert.deepEqual(handInLearners({ groupSubmission: true }, sets, 'me'), ['me'])
	assert.deepEqual(handInLearners({ groupSubmission: false, workGroupSetName: 'Campagne' }, sets, 'me'), ['me'])
	assert.deepEqual(handInLearners({ groupSubmission: true, workGroupSetName: 'Campagne', cohortId: 'other' }, sets, 'me'), ['me'])
	assert.deepEqual(handInLearners({ groupSubmission: true, workGroupSetName: 'Other' }, sets, 'me'), ['me'])
})
