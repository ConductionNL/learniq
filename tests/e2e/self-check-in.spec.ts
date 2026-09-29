/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * attendance-self-check-in, Task 4: the teacher opens a check-in window from
 * the register, a learner of the lesson's group checks in with the code on the
 * board, and the teacher sees the row.
 *
 * Actors: admin as the teacher (the register is staff-only), and a temporary
 * learner in learniq's `learners` group who is in the cohort's `learnerIds`
 * (CheckInService::context() refuses anyone else with not_in_group).
 *
 * The lesson is created running: it started two minutes ago, inside the
 * default five-minute grace, so the record is `present`.
 *
 * @e2e openspec/specs/attendance/spec.md#requirement-a-teacher-opens-a-self-check-in-window-for-a-lesson
 * @e2e openspec/specs/attendance/spec.md#requirement-a-learner-checks-in-with-the-code
 */
import { expect, test } from './fixtures.ts'
import { LiveFixtures, signInAs } from './live-fixtures.ts'

const APP = '/index.php/apps/learniq'

test.describe('self check-in', () => {
	test.describe.configure({ timeout: 240_000 })

	const fx = new LiveFixtures()

	test.afterAll(async () => {
		await fx.teardown()
	})

	test('the teacher opens a window, a learner checks in, the teacher sees the row', async ({
		loggedInPage: teacher,
		browser,
	}) => {
		const learner = await fx.user('learner', ['learners'])
		const cohortId = await fx.object('cohort', {
			name: `r5 check-in ${fx.run}`,
			period: 'Q1',
			academicYear: '2026-2027',
			learnerIds: [learner.id],
			teacherIds: ['admin'],
		})
		const now = Date.now()
		const title = `r5 check-in lesson ${fx.run}`
		const sessionId = await fx.object('session', {
			cohortId,
			title,
			startsAt: new Date(now - 2 * 60_000).toISOString(),
			endsAt: new Date(now + 60 * 60_000).toISOString(),
		})

		// Teacher: open self check-in from the register.
		await teacher.goto(`${APP}/sessions/${sessionId}/attendance`, {
			waitUntil: 'domcontentloaded',
		})
		await teacher
			.getByRole('button', { name: 'Open self check-in', exact: true })
			.click({ timeout: 60_000 })
		const codeCell = teacher.locator('.self-check-in__code')
		await expect(codeCell).toHaveText(/\S+/, { timeout: 30_000 })

		const windows = await fx.find('check-in-window', { sessionId })
		expect(windows, 'the register created no check-in window').toHaveLength(1)
		fx.adopt('check-in-window', String(windows[0].id))

		// Learner: the lesson is listed as open, and the code checks them in.
		const learnerPage = await signInAs(browser, learner)
		try {
			await learnerPage.goto(`${APP}/check-in`, {
				waitUntil: 'domcontentloaded',
			})
			await expect(
				learnerPage.locator('.check-in-page__lessons li', {
					hasText: title,
				}),
			).toBeVisible({ timeout: 60_000 })
			// The code rotates every thirty seconds and the current and previous
			// step are accepted. Signing a fresh account in takes longer than that
			// on a busy instance, so read the board's current code again, the way a
			// learner reads it off the screen at the moment they type.
			const api = await fx.api()
			const board = await api.get(`${APP}/api/check-in/${windows[0].id}/code`)
			expect(
				board.ok(),
				`reading the board code: HTTP ${board.status()}`,
			).toBe(true)
			const current = String((await board.json()).code)
			expect(current).toMatch(/\S+/)
			await learnerPage.locator('#check-in-code').fill(current)
			await learnerPage
				.getByRole('button', { name: 'Check in', exact: true })
				.click()
			await expect(
				learnerPage
					.locator('.notecard--success, [class*="success"]')
					.first(),
			).toBeVisible({ timeout: 30_000 })
		} finally {
			await learnerPage.context().close()
		}

		const records = await fx.find('attendance-record', { sessionId })
		for (const r of records) fx.adopt('attendance-record', String(r.id))
		expect(records).toHaveLength(1)
		expect(records[0].learnerId).toBe(learner.id)
		expect(records[0].markedVia).toBe('self-check-in')
		expect(records[0].status).toBe('present')

		// Teacher: the row now carries the "checked in" badge.
		await teacher.reload({ waitUntil: 'domcontentloaded' })
		await expect(
			teacher
				.locator('.attendance-register__table tr', { hasText: learner.id })
				.locator('.attendance-register__self'),
		).toBeVisible({ timeout: 60_000 })
	})
})
