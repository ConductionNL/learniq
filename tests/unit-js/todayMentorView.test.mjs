// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// today-first-per-school-role T3: Today's Teacher / Mentor switch. Only a
// teacher who mentors a group (Cohort.mentorId) is offered the Mentor view:
// its visibleWhen asks GET /api/timetable/mentor-classes, the route
// TimetableController::mentorClasses answers. For anyone else the page has
// one view left and nextcloud-vue draws no switch (views[].visibleWhen,
// manifest schema 2.200.0).
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
//
// @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-a-mentor-switches-today-to-her-mentor-class

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const today = JSON.parse(readFileSync(resolve(root, 'src/menu-layout.simple.json'), 'utf8')).pages.find((p) => p.id === 'Dashboard')
const views = today.config.views

test('Today opens the Teacher view for everyone and offers no condition on it', () => {
	assert.equal(today.config.defaultView, 'teacher')
	const teacher = views.find((view) => view.id === 'teacher')
	assert.ok(teacher)
	assert.equal(teacher.visibleWhen, undefined)
})

test('the Mentor view is offered only when the reader mentors a group', () => {
	const mentor = views.find((view) => view.id === 'mentor')
	assert.deepEqual(mentor.visibleWhen, {
		endpoint: '/apps/learniq/api/timetable/mentor-classes',
		field: 'classes',
		op: 'notEmpty',
	})
})

test('the endpoint the Mentor view asks is a route learniq serves', () => {
	const routes = readFileSync(resolve(root, 'appinfo/routes.php'), 'utf8')
	assert.match(routes, /'name' => 'timetable#mentorClasses', 'url' => '\/api\/timetable\/mentor-classes', 'verb' => 'GET'/)
})
