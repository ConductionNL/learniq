// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// permission-slips: the teacher's outcome of a permission request. Pins the
// yes / no / not answered split, the latest answer winning, answers outside
// the request ignored, and silence counting as refusal by default.
//
// @spec openspec/changes/permission-slips/specs/portal-contribution/spec.md#requirement-a-teacher-sees-who-has-not-answered

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	latestAnswers,
	permissionOutcome,
} from '../../src/utils/permissionOutcome.js'

/**
 * @param {number} n How many children.
 * @return {string[]} child-1 .. child-n
 */
function children(n) {
	return Array.from({ length: n }, (_, i) => `child-${i + 1}`)
}

/**
 * @param {string} learnerRef The child.
 * @param {string} answer yes or no.
 * @param {string} answeredAt When.
 * @param {string} permissionRequestId The request.
 * @return {object} A PermissionResponse.
 */
function response(learnerRef, answer, answeredAt = '2026-10-10T10:00:00Z', permissionRequestId = 'req-1') {
	return { permissionRequestId, learnerRef, answer, answeredAt }
}

test('the evening before the trip: 18 of 28 answered, the 10 who did not are named', () => {
	const asked = children(28)
	const request = { id: 'req-1', learnerRefs: asked }
	const responses = [
		...asked.slice(0, 15).map((ref) => response(ref, 'yes')),
		...asked.slice(15, 18).map((ref) => response(ref, 'no')),
	]

	const outcome = permissionOutcome(request, responses)

	assert.deepEqual(outcome.yes, asked.slice(0, 15))
	assert.deepEqual(outcome.no, asked.slice(15, 18))
	assert.deepEqual(outcome.notAnswered, asked.slice(18))
	assert.equal(outcome.asked, 28)
	assert.equal(outcome.answered, 18)
})

test('nobody answered: every child reads as not answered and counts as refused', () => {
	const outcome = permissionOutcome({ id: 'req-1', learnerRefs: children(3) }, [])

	assert.deepEqual(outcome.notAnswered, children(3))
	assert.equal(outcome.silenceRefuses, true)
	assert.equal(outcome.refused, 3)
	assert.equal(outcome.permitted, 0)
})

test('a request that says silence is not a refusal counts the unanswered as permitted', () => {
	const request = { id: 'req-1', learnerRefs: children(3), treatSilenceAsRefusal: false }
	const outcome = permissionOutcome(request, [response('child-1', 'no')])

	assert.equal(outcome.silenceRefuses, false)
	assert.deepEqual(outcome.notAnswered, ['child-2', 'child-3'])
	assert.equal(outcome.refused, 1)
	assert.equal(outcome.permitted, 2)
})

test('the latest answer for a child counts, whatever order the rows come in', () => {
	const request = { id: 'req-1', learnerRefs: ['child-1'] }
	const later = response('child-1', 'no', '2026-10-12T08:00:00Z')
	const earlier = response('child-1', 'yes', '2026-10-11T08:00:00Z')

	assert.deepEqual(permissionOutcome(request, [later, earlier]).no, ['child-1'])
	assert.deepEqual(permissionOutcome(request, [earlier, later]).no, ['child-1'])
	assert.equal(latestAnswers(request, [earlier, later]).get('child-1'), later)
})

test('answers for another request, for a child not asked, or without yes or no are ignored', () => {
	const request = { id: 'req-1', learnerRefs: ['child-1', 'child-2'] }
	const outcome = permissionOutcome(request, [
		response('child-1', 'yes', undefined, 'req-2'),
		response('child-9', 'yes'),
		response('child-2', 'maybe'),
		null,
	])

	assert.deepEqual(outcome.yes, [])
	assert.deepEqual(outcome.notAnswered, ['child-1', 'child-2'])
})

test('duplicate and blank learner refs on the request are asked once', () => {
	const request = { id: 'req-1', learnerRefs: ['child-1', '', 'child-1', 7, 'child-2'] }
	const outcome = permissionOutcome(request, [response('child-1', 'yes')])

	assert.equal(outcome.asked, 2)
	assert.deepEqual(outcome.yes, ['child-1'])
	assert.deepEqual(outcome.notAnswered, ['child-2'])
})

test('a request with no learners asks nobody', () => {
	const outcome = permissionOutcome({ id: 'req-1' }, [response('child-1', 'yes')])

	assert.equal(outcome.asked, 0)
	assert.deepEqual([outcome.yes, outcome.no, outcome.notAnswered], [[], [], []])
})
