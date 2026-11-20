// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// training-provider-course-days T4: the course days board. The page is
// declared in JSON and names its move handler by NAME, so this test reads the
// real fragment and checks the board, the calendar and the handler that has to
// answer the drop.
//
// @spec openspec/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself

import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { describe, test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { courseDayStatusPath, createCourseDayHandlers } from '../../src/utils/courseDayBoard.js'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..')
const fragment = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.d/learning.json'), 'utf8'))
const register = JSON.parse(fs.readFileSync(path.join(ROOT, 'lib/Settings/learniq_register.json'), 'utf8'))
const page = fragment.pages.find((p) => p.id === 'CourseDays')
const cohort = register.components.schemas.Cohort.properties

describe('the course days page', () => {
	test('opens on a board of the derived status, with a table and a calendar', () => {
		assert.equal(page.type, 'index')
		assert.equal(page.config.schema, 'Cohort')
		assert.deepEqual(page.config.viewModes, ['board', 'table', 'calendar'])
		assert.equal(page.config.viewMode, 'board')
		assert.equal(page.config.board.statusField, 'dayStatus')
		assert.deepEqual(cohort.dayStatus.enum, ['open', 'goes-ahead', 'full', 'rounding-off', 'done', 'cancelled'])
		assert.equal(page.config.calendar.dateField, 'dayDate')
		assert.equal(cohort.dayDate.format, 'date')
	})

	test('names a move handler the app provides, and a menu entry reaches it', () => {
		const handlers = createCourseDayHandlers({ post: async () => ({ data: {} }), generateUrl: (u) => u })
		assert.equal(typeof handlers[page.config.board.moveHandler], 'function')
		const app = fs.readFileSync(path.join(ROOT, 'src/App.vue'), 'utf8')
		assert.match(app, /createCourseDayHandlers\(/)
		const entries = JSON.stringify(fragment.menu)
		assert.match(entries, /"route":"CourseDays"/)
	})
})

describe('moveCourseDay', () => {
	test('posts the dropped column to the move and answers the stored status', async () => {
		const calls = []
		const { moveCourseDay } = createCourseDayHandlers({
			post: async (url, body) => {
				calls.push([url, body])
				return { data: { dayStatus: 'goes-ahead' } }
			},
			generateUrl: (u) => '/index.php' + u,
		})

		const moved = await moveCourseDay({ card: { id: 'c 22', name: '22 oktober', dayStatus: 'open' }, toKey: 'goes-ahead' })

		assert.deepEqual(calls, [['/index.php/apps/learniq/api/course-days/c%2022/status', { status: 'goes-ahead' }]])
		assert.deepEqual(moved, { id: 'c 22', name: '22 oktober', dayStatus: 'goes-ahead' })
		assert.equal(courseDayStatusPath('x'), '/apps/learniq/api/course-days/x/status')
	})

	test('a refusal is thrown with the server message; a card without id is refused before any request', async () => {
		let posted = 0
		const { moveCourseDay } = createCourseDayHandlers({
			post: async () => {
				posted++
				throw { response: { status: 400, data: { error: 'Unknown status' } } }
			},
			generateUrl: (u) => u,
		})

		await assert.rejects(moveCourseDay({ card: { '@self': { id: 'c-1' } }, toKey: 'maybe' }), /Unknown status/)
		await assert.rejects(moveCourseDay({ card: {}, toKey: 'open' }), /cannot be moved/)
		assert.equal(posted, 1)
	})
})
