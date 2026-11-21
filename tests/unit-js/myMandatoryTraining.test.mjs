// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// "My mandatory training" lists the signed-in user's own enrolments. Enrolment
// read rights let instructors, team leads, HR, compliance officers and managers
// read other people's enrolments, so the query itself must ask for the user's
// own rows, and each row names its course by the schema's `courseName`.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
// @spec openspec/specs/enrolment/spec.md#scenario-progress-percentage-is-visible-on-the-learners-my-learning-dashboard

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const WIDGET = readFileSync(
	new URL('../../src/views/widgets/MyMandatoryTrainingWidget.vue', import.meta.url),
	'utf8',
)
const ENROLMENT = JSON.parse(
	readFileSync(new URL('../../lib/Settings/learniq_register.json', import.meta.url), 'utf8'),
).components.schemas.Enrolment

test('the enrolment query is scoped to the signed-in learner', () => {
	assert.match(WIDGET, /import \{ getCurrentUser \} from '@nextcloud\/auth'/)
	assert.match(WIDGET, /learnerId: user\.uid,/)
	assert.ok(ENROLMENT.properties.learnerId, 'Enrolment has learnerId')
})

test('without a signed-in user nothing is fetched', () => {
	assert.match(WIDGET, /if \(!user\) \{\s*this\.enrolments = \[\]\s*return\s*\}/)
})

test('a row names its course by courseName', () => {
	assert.match(WIDGET, /enrolment\.courseName\b/)
	assert.doesNotMatch(WIDGET, /courseTitle/)
	assert.ok(ENROLMENT.properties.courseName, 'Enrolment has courseName')
})
