// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// learniq#945: a learner who retakes a course started it already complete,
// because the lesson player treated any completion of the lesson by this
// learner as "done", whatever enrolment it came from.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	completionBelongsTo,
	currentEnrolment,
} from '../../src/utils/lessonCompletion.js'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')

const old = {
	id: 'enrol-1',
	lifecycle: 'completed',
	'@self': { created: '2026-01-01T00:00:00Z' },
}
const retake = {
	id: 'enrol-2',
	lifecycle: 'active',
	'@self': { created: '2027-01-01T00:00:00Z' },
}

test('the current enrolment is the newest active one, else the newest pending one', () => {
	assert.equal(currentEnrolment([old, retake]).id, 'enrol-2')
	assert.equal(currentEnrolment([old, { id: 'p', lifecycle: 'pending' }]).id, 'p')
	assert.equal(currentEnrolment([old]), null)
})

test('a completion of an earlier enrolment does not count for the retake', () => {
	assert.equal(
		completionBelongsTo(
			{ enrolmentId: 'enrol-1', completedAt: '2026-02-01T00:00:00Z' },
			retake,
		),
		false,
	)
	assert.equal(
		completionBelongsTo(
			{ enrolmentId: 'enrol-2', completedAt: '2027-02-01T00:00:00Z' },
			retake,
		),
		true,
	)
	assert.equal(
		completionBelongsTo({ completedAt: '2026-02-01T00:00:00Z' }, retake),
		false,
		'untied and before the retake started',
	)
	assert.equal(
		completionBelongsTo({ completedAt: '2027-02-01T00:00:00Z' }, retake),
		true,
		'untied and after the retake started',
	)
})

test('the lesson player checks completion against the current enrolment and sends it on a manual completion', () => {
	const player = readFileSync(resolve(root, 'src/views/LessonPlayer.vue'), 'utf8')
	assert.match(player, /completionBelongsTo\(/)
	assert.match(player, /enrolmentId: this\.currentEnrolmentId/)
})
