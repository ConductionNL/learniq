// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The widget and layout declarations the role dashboards and the people
// dashboard hand to CnDashboardPage, pinned as they are built: every tile,
// its source and its place on the grid. The clock is frozen, so the
// "sessions to mark" filter reads the same day every run.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
//
// @spec openspec/specs/dashboard/spec.md#requirement-per-role-group-gated-dashboard-menu-items

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { mock, test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const golden = JSON.parse(
	readFileSync(new URL('./fixtures/dashboardConfigs.json', import.meta.url), 'utf8'),
)

/**
 * The declarations of both dashboards, built on a fixed day.
 *
 * @return {Promise<object>} The declarations by name.
 */
async function buildAll() {
	mock.timers.enable({ apis: ['Date'], now: new Date('2026-03-04T10:00:00Z') })
	try {
		const roles = await loadSfc('src/views/LearniqDashboards.vue')
		const people = await loadSfc('src/views/PeopleDashboard.vue')
		const schoolWide = instanceOf(roles)
		const scoped = instanceOf(roles, {
			scope: { cohortIds: ['c1'], courseIds: ['k1'], learnerIds: ['p1', 'p2'], programmeIds: [] },
			scopePending: false,
		})
		const pending = instanceOf(roles, { scope: null, scopePending: true })
		const peopleView = instanceOf(people)
		const learningView = instanceOf(await loadSfc('src/views/LearningDashboard.vue'))
		const complianceView = instanceOf(await loadSfc('src/views/LearniqCompliance.vue'))
		return JSON.parse(
			JSON.stringify({
				admin: schoolWide.adminConfig,
				teacher: schoolWide.teacherConfig,
				teacherScoped: scoped.teacherConfig,
				teacherPending: pending.teacherConfig,
				student: schoolWide.studentConfig,
				peopleWidgets: peopleView.widgets,
				peopleLayout: peopleView.layout,
				learningWidgets: learningView.widgets,
				learningLayout: learningView.layout,
				complianceWidgets: complianceView.widgets,
				complianceLayout: complianceView.layout,
			}),
		)
	} finally {
		mock.timers.reset()
	}
}

test('the dashboards declare the same tiles, sources and grid as before', async () => {
	assert.deepEqual(await buildAll(), golden)
})

test('each build hands out fresh layout objects', async () => {
	for (const file of ['src/views/PeopleDashboard.vue', 'src/views/LearningDashboard.vue', 'src/views/LearniqCompliance.vue']) {
		const view = await loadSfc(file)
		const first = instanceOf(view).layout
		first[0].gridX = 99
		assert.equal(instanceOf(view).layout[0].gridX, 0, file)
	}
})
