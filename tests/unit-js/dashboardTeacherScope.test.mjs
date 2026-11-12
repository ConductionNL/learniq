// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The dashboard's teacher scope, pinned through the component's own method
// with stubbed reads: a group teacher sees only their own cohorts and the
// courses those cohorts run; school-wide roles get no scope; a failed read
// leaves the teacher with empty lists, never the whole school.
//
// @spec openspec/changes/teacher-dashboard-own-groups/specs/dashboard/spec.md#requirement-the-teacher-dashboard-of-a-group-teacher-lists-only-their-own-groups

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

let user = { uid: 'jan' }
let primaryRole = 'instructor'
const http = { calls: [], answers: {} }
const component = await loadSfc('src/views/LearniqDashboards.vue', {
	'@nextcloud/auth': { getCurrentUser: () => user },
	'@nextcloud/initial-state': { loadState: (app, key, fallback) => (key === 'primaryRole' ? primaryRole : fallback) },
	'@nextcloud/axios': {
		default: {
			get: async (url) => {
				http.calls.push(url)
				const answer = http.answers[url.split('?')[0].split('/').pop()]
				if (answer instanceof Error || answer === undefined) throw answer ?? new Error('404')
				return answer
			},
		},
	},
	'@nextcloud/router': { generateUrl: (path) => `/nc${path}` },
})
const NO_ROWS = { cohortIds: [], courseIds: [], programmeIds: [], learnerIds: [] }

/**
 * A dashboard in a role.
 *
 * @param {string} activeRole The dashboard shown.
 * @param {object} answers Schema to axios answer.
 * @return {object}
 */
function dashboard(activeRole, answers = {}) {
	http.calls = []
	http.answers = answers
	return instanceOf(component, { activeRole })
}

test('a group teacher gets the cohorts they teach and the courses those cohorts and programmes run', async () => {
	const vm = dashboard('teacher', {
		Cohort: { data: { results: [
			{ id: 'c1', teacherIds: ['jan'], courseId: 'k1', programmeId: 'p1', learnerIds: ['sem'] },
			{ id: 'c2', teacherIds: ['eva'], courseId: 'k9' },
		] } },
		Programme: { data: [{ id: 'p1', courseIds: ['k2'] }] },
	})
	await vm.loadTeacherScope()
	assert.equal(http.calls.length, 2)
	assert.match(http.calls[0], /\/Cohort\?_limit=\d+&teacherIds=jan$/)
	assert.match(http.calls[1], /\/Programme\?_limit=\d+&_ids%5B%5D=p1$/)
	assert.deepEqual(vm.scope.cohortIds, ['c1'])
	assert.deepEqual([...vm.scope.courseIds].sort(), ['k1', 'k2'])
	assert.equal(vm.scopePending, false)
})

test('a teacher without programmes reads no programme', async () => {
	const vm = dashboard('teacher', { Cohort: { data: [{ id: 'c1', teacherIds: ['jan'] }] } })
	await vm.loadTeacherScope()
	assert.equal(http.calls.length, 1)
	assert.deepEqual(vm.scope.cohortIds, ['c1'])
})

test('school-wide roles and other dashboards get no scope and read nothing', async () => {
	for (const [role, activeRole] of [['admin', 'teacher'], ['manager', 'teacher'], ['instructor', 'admin'], ['instructor', 'student']]) {
		primaryRole = role
		const vm = dashboard(activeRole)
		vm.scope = NO_ROWS
		vm.scopePending = true
		await vm.loadTeacherScope()
		assert.equal(vm.scope, null)
		assert.equal(vm.scopePending, false)
		assert.deepEqual(http.calls, [])
	}
	primaryRole = 'instructor'
})

test('a failed read leaves the teacher with empty lists, never the whole school', async () => {
	for (const answers of [{}, { Cohort: { data: [{ id: 'c1', teacherIds: ['jan'], programmeId: 'p1' }] } }]) {
		const vm = dashboard('teacher', answers)
		await vm.loadTeacherScope()
		assert.deepEqual(vm.scope, NO_ROWS)
		assert.equal(vm.scopePending, false)
	}
	user = null
	const anonymous = dashboard('teacher', { Cohort: { data: [{ id: 'c1', teacherIds: [''] }] } })
	await anonymous.loadTeacherScope()
	assert.match(http.calls[0], /teacherIds=$/)
	user = { uid: 'jan' }
})
