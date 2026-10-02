// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// teacher-dashboard-own-groups: the teacher dashboard of a group teacher
// lists only the cohorts they teach and what hangs off them. Found on a clean
// primary-school install: po-leerkracht-09 (Groep 7) saw every group, course
// and session in the school. Run via `npm run test:js-unit`.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import {
	appendFilter,
	filterMatchesNothing,
	isScopedTeacher,
	teacherScope,
	teacherTileSource,
	teacherWidgetFilters,
} from '../../src/utils/teacherScope.js'

const COHORTS = [
	{
		id: 'groep-7',
		teacherIds: ['juf-7'],
		courseId: 'course-po',
		programmeId: 'prog-po',
	},
	{
		id: 'plusklas',
		teacherIds: ['juf-7', 'meester-8'],
		courseId: null,
		programmeId: null,
	},
	// A widened server answer: a cohort that does not list the teacher.
	{
		id: 'groep-8',
		teacherIds: ['meester-8'],
		courseId: 'course-other',
		programmeId: 'prog-other',
	},
]

const PROGRAMMES = [
	{ id: 'prog-po', courseIds: ['course-rekenen', 'course-taal', 'course-po'] },
	{ id: 'prog-other', courseIds: ['course-other-2'] },
]

test('only a group teacher is scoped; coordinators, directors, team leads and admins keep the school', () => {
	assert.equal(isScopedTeacher('instructor'), true)
	for (const role of [
		'coordinator',
		'administration-manager',
		'team-lead',
		'admin',
		'hr',
		'learner',
		'',
	]) {
		assert.equal(isScopedTeacher(role), false, role)
	}
})

test('the scope is the cohorts the teacher teaches and the courses they run', () => {
	const scope = teacherScope({
		userId: 'juf-7',
		cohorts: COHORTS,
		programmes: PROGRAMMES,
	})

	assert.deepEqual(scope.cohortIds, ['groep-7', 'plusklas'])
	assert.deepEqual(scope.programmeIds, ['prog-po'])
	assert.deepEqual([...scope.courseIds].sort(), [
		'course-po',
		'course-rekenen',
		'course-taal',
	])
})

test('a cohort the server returned without the teacher never widens the scope', () => {
	const scope = teacherScope({
		userId: 'juf-7',
		cohorts: COHORTS,
		programmes: PROGRAMMES,
	})

	assert.ok(!scope.cohortIds.includes('groep-8'))
	assert.ok(!scope.courseIds.includes('course-other'))
	assert.ok(!scope.courseIds.includes('course-other-2'))
})

test('each widget gets the filter of its schema', () => {
	const filters = teacherWidgetFilters('juf-7', {
		cohortIds: ['groep-7'],
		courseIds: ['course-po'],
	})

	assert.deepEqual(filters.cohorts, { teacherIds: 'juf-7' })
	assert.deepEqual(filters.sessions, { cohortId: ['groep-7'] })
	assert.deepEqual(filters.assignments, { cohortId: ['groep-7'] })
	assert.deepEqual(filters.courses, { _ids: ['course-po'] })
})

test('school-wide roles get no filter', () => {
	assert.deepEqual(teacherWidgetFilters('ib-1', null), {
		cohorts: {},
		courses: {},
		sessions: {},
		assignments: {},
	})
})

test('a teacher without a group gets lists that match nothing, not the whole school', () => {
	const filters = teacherWidgetFilters(
		'juf-new',
		teacherScope({ userId: 'juf-new', cohorts: [] }),
	)

	assert.equal(filterMatchesNothing(filters.sessions), true)
	assert.equal(filterMatchesNothing(filters.assignments), true)
	assert.equal(filterMatchesNothing(filters.courses), true)
	assert.equal(
		filterMatchesNothing(filters.cohorts),
		false,
		'the cohort list still asks, filtered on the teacher',
	)
	assert.equal(filterMatchesNothing({}), false)
	assert.equal(filterMatchesNothing({ lifecycle: 'active' }), false)
})

test('a list filter is sent the way OpenRegister reads an IN filter', () => {
	const params = appendFilter(new URLSearchParams({ _limit: '6' }), {
		cohortId: ['a', 'b'],
		teacherIds: 'juf-7',
	})

	assert.equal(
		params.toString(),
		'_limit=6&cohortId%5B%5D=a&cohortId%5B%5D=b&teacherIds=juf-7',
	)
})

// The teacher widgets showed raw ids and empty cells: the cohort list showed
// programmeId (a uuid) and learnerCount (no such field), the session list
// asked for `name` on a schema that has `title`.
test('every column of the teacher widgets is a field the schema has', () => {
	const register = JSON.parse(
		readFileSync(
			new URL('../../lib/Settings/learniq_register.json', import.meta.url),
			'utf8',
		),
	)
	const vue = readFileSync(
		new URL('../../src/views/LearniqDashboards.vue', import.meta.url),
		'utf8',
	)
	const widgets = [
		...vue.matchAll(/<template #widget-teacher-[a-z]+>([\s\S]*?)<\/template>/g),
	].map((m) => m[1])
	assert.equal(widgets.length, 4)

	for (const widget of widgets) {
		const schemaName = widget.match(/schema="([A-Za-z]+)"/)[1]
		const columns = JSON.parse(
			widget.match(/:columns="(\[[^\]]*\])"/)[1].replace(/'/g, '"'),
		)
		const schema = register.components.schemas[schemaName]
		const fields = [
			...Object.keys(schema.properties),
			...Object.keys(schema['x-openregister-calculations'] || {}),
		]
		for (const column of columns) {
			assert.ok(fields.includes(column), `${schemaName}.${column}`)
		}
		assert.ok(
			!columns.some((column) => column.endsWith('Id')),
			`${schemaName}: no raw id column`,
		)
	}
})

// teacher-dashboard-engagement-tiles: the two engagement tiles at the top of
// the teacher view counted the whole school for a group teacher. Engagement
// rows carry `learnerId` (a Nextcloud user id) and no cohort, so the scope
// is the pupils of the teacher's cohorts (Cohort.learnerIds).
test('the scope holds the pupils of the cohorts the teacher teaches, and no others', () => {
	const scope = teacherScope({
		userId: 'juf-7',
		cohorts: [
			{ id: 'groep-7', teacherIds: ['juf-7'], learnerIds: ['vera', 'tim'] },
			{ id: 'plusklas', teacherIds: ['juf-7'], learnerIds: ['tim', 'noor'] },
			{ id: 'groep-8', teacherIds: ['meester-8'], learnerIds: ['sam'] },
		],
	})

	assert.deepEqual([...scope.learnerIds].sort(), ['noor', 'tim', 'vera'])
})

test('an engagement tile of a group teacher counts only the pupils of their groups', () => {
	const source = {
		register: 'learniq',
		schema: 'engagement-risk-flag',
		metric: 'count',
		filter: { lifecycle: 'open' },
	}
	const scoped = teacherTileSource(source, { learnerIds: ['vera', 'tim'] }, false)

	assert.deepEqual(scoped, {
		register: 'learniq',
		schema: 'engagement-risk-flag',
		metric: 'count',
		filter: { lifecycle: 'open', learnerId: { in: ['vera', 'tim'] } },
	})
	assert.deepEqual(
		source.filter,
		{ lifecycle: 'open' },
		'the declared source is not changed',
	)
})

test('an engagement tile of a school-wide role keeps counting the whole school', () => {
	const source = {
		register: 'learniq',
		schema: 'engagement-score',
		metric: 'avg',
		field: 'score',
	}

	assert.deepEqual(teacherTileSource(source, null, false), source)
})

test('an engagement tile never asks for the whole school while the scope loads or is empty', () => {
	const source = {
		register: 'learniq',
		schema: 'engagement-score',
		metric: 'avg',
		field: 'score',
	}

	assert.equal(teacherTileSource(source, null, true), null, 'pending')
	assert.equal(
		teacherTileSource(source, { learnerIds: [] }, false),
		null,
		'no pupils',
	)
	assert.equal(teacherTileSource(source, {}, false), null, 'no learner list')
})

test('the teacher view feeds both engagement tiles through the teacher scope', () => {
	const vue = readFileSync(
		new URL('../../src/views/LearniqDashboards.vue', import.meta.url),
		'utf8',
	)
	const teacher = vue.slice(
		vue.indexOf('teacherConfig() {'),
		vue.indexOf('studentConfig() {'),
	)
	const scoped = [...teacher.matchAll(/source: this\.teacherTile\(\{/g)]

	assert.equal(scoped.length, 2, 'both engagement tiles go through teacherTile()')
	assert.ok(
		!/source: \{\s*register/.test(teacher),
		'no tile declares an unscoped source',
	)
})
