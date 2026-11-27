/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Join an online lesson from My timetable (timetabling-online-lesson-link,
 * task 2.1b): a lesson with a link shows Join and opens the meeting in a new
 * tab, a lesson without a link shows no Join, and a `javascript:` link is
 * refused when the lesson is saved.
 *
 * Written from the change's scenarios; not run in the build lane (needs an
 * instance with learniq's register imported and the frontend built).
 *
 * Covers:
 *   @e2e openspec/changes/timetabling-online-lesson-link/specs/timetable-online-lesson-link/spec.md#scenario-a-learner-joins-from-the-timetable
 *   @e2e openspec/changes/timetabling-online-lesson-link/specs/timetable-online-lesson-link/spec.md#scenario-an-unsafe-link-is-refused
 *   @e2e openspec/changes/timetabling-online-lesson-link/specs/timetable-online-lesson-link/spec.md#scenario-a-lesson-without-a-link-shows-none
 */
import type { TempUser } from './live-fixtures.ts'

import { expect, test } from './fixtures.ts'
import { LiveFixtures, signInAs } from './live-fixtures.ts'

const TIMETABLE = '/index.php/apps/learniq/my-timetable'
const MEETING = 'https://meet.example.org/lq-e2e-online-lesson'

/**
 * A moment on Wednesday of this week.
 *
 * @param hour The hour of the day.
 * @return The ISO timestamp.
 */
function thisWednesday(hour: number): string {
	const day = new Date()
	day.setHours(hour, 0, 0, 0)
	day.setDate(day.getDate() - ((day.getDay() + 6) % 7) + 2)
	return day.toISOString()
}

test.describe('join an online lesson from the timetable', () => {
	const fx = new LiveFixtures()
	let learner: TempUser
	let cohortId = ''
	const online = `Online Engels ${fx.run}`
	const inRoom = `Engels in lokaal ${fx.run}`

	test.beforeAll(async () => {
		learner = await fx.user('learner', ['learners'])
		cohortId = await fx.object('cohort', {
			name: `Online ${fx.run}`,
			period: 'year',
			academicYear: '2026-2027',
			learnerIds: [learner.id],
			teacherIds: [],
		})
		await fx.object('session', {
			cohortId,
			title: online,
			startsAt: thisWednesday(9),
			endsAt: thisWednesday(10),
			lifecycle: 'scheduled',
			onlineMeetingUrl: MEETING,
		})
		await fx.object('session', {
			cohortId,
			title: inRoom,
			startsAt: thisWednesday(11),
			endsAt: thisWednesday(12),
			lifecycle: 'scheduled',
		})
	})

	test.afterAll(async () => {
		await fx.teardown()
	})

	// @e2e openspec/changes/timetabling-online-lesson-link/specs/timetable-online-lesson-link/spec.md#scenario-a-learner-joins-from-the-timetable
	test('Join opens the meeting in a new tab', async ({ browser }) => {
		const page = await signInAs(browser, learner)
		// The meeting host does not exist; answer it here so the new tab lands on its URL
		// instead of a browser error page.
		await page.context().route('https://meet.example.org/**', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '<title>Meeting</title>' }))
		await page.goto(TIMETABLE, { waitUntil: 'domcontentloaded' })
		const lesson = page.locator('.my-timetable__session', { hasText: online })
		await expect(lesson).toHaveCount(1, { timeout: 30_000 })
		const join = lesson.getByTestId('session-join')
		await expect(join).toBeVisible()
		await expect(join).toHaveAttribute('target', '_blank')
		const [tab] = await Promise.all([page.context().waitForEvent('page'), join.click()])
		await tab.waitForLoadState('domcontentloaded').catch(() => undefined)
		expect(tab.url()).toContain('meet.example.org/lq-e2e-online-lesson')
		await page.context().close()
	})

	// @e2e openspec/changes/timetabling-online-lesson-link/specs/timetable-online-lesson-link/spec.md#scenario-a-lesson-without-a-link-shows-none
	test('a lesson without a link shows no Join', async ({ browser }) => {
		const page = await signInAs(browser, learner)
		await page.goto(TIMETABLE, { waitUntil: 'domcontentloaded' })
		const lesson = page.locator('.my-timetable__session', { hasText: inRoom })
		await expect(lesson).toHaveCount(1, { timeout: 30_000 })
		await expect(lesson.getByTestId('session-join')).toHaveCount(0)
		await page.context().close()
	})

	// @e2e openspec/changes/timetabling-online-lesson-link/specs/timetable-online-lesson-link/spec.md#scenario-an-unsafe-link-is-refused
	test('a javascript: link is refused on save', async () => {
		const admin = await fx.api()
		const res = await admin.post('/index.php/apps/openregister/api/objects/learniq/session', {
			data: {
				tenant_id: await fx.tenant(),
				cohortId,
				title: `Unsafe ${fx.run}`,
				startsAt: thisWednesday(13),
				endsAt: thisWednesday(14),
				onlineMeetingUrl: 'javascript:alert(1)',
			},
		})
		const text = await res.text()
		if (res.ok()) {
			// A save that got through must still be removed by the teardown.
			const body = JSON.parse(text)
			fx.adopt('session', String(body.id ?? body['@self']?.id))
		}
		expect(res.status(), text).toBeGreaterThanOrEqual(400)
		expect(text).toContain('onlineMeetingUrl')
	})
})
