import { expect, test } from './fixtures.ts'
import { createObject } from './or-api.ts'

/**
 * timetabling-standby-slots: a coordinator puts a teacher on standby, the
 * teacher on standby is listed first for a lesson at that time, and the
 * admin's own standby shows in "My timetable".
 *
 * @spec openspec/specs/timetabling/spec.md#scenario-a-coordinator-puts-a-teacher-on-standby
 * @spec openspec/specs/timetabling/spec.md#scenario-a-coordinator-covers-a-sick-teacher-s-lesson
 */
test.describe('Standby hours', () => {
	test('standby is planned, listed first, and shown in the timetable', async ({
		loggedInPage: page,
	}) => {
		const suffix = Date.now().toString(36)
		const monday = new Date()
		monday.setHours(0, 0, 0, 0)
		monday.setDate(monday.getDate() - ((monday.getDay() + 6) % 7))
		const tuesday = new Date(monday)
		tuesday.setDate(monday.getDate() + 1)
		const day = tuesday.toISOString().slice(0, 10)
		const year = {
			validFrom: `${tuesday.getFullYear() - 1}-08-01`,
			validUntil: `${tuesday.getFullYear() + 1}-07-31`,
		}

		const slot = await createObject(page, 'standby-slot', {
			teacherId: 'admin',
			weekday: 'tuesday',
			startsAt: '10:15',
			endsAt: '11:05',
			...year,
		})
		test.skip(
			!slot,
			'The standby-slot schema is not importable on this instance.',
		)
		const cohortId = await createObject(page, 'cohort', {
			name: `SB ${suffix}`,
			teacherIds: [`sick-${suffix}`],
			learnerIds: [],
		})
		const sessionId = await createObject(page, 'session', {
			cohortId,
			title: `Wiskunde B ${suffix}`,
			startsAt: new Date(`${day}T10:15:00`).toISOString(),
			endsAt: new Date(`${day}T11:05:00`).toISOString(),
			lifecycle: 'scheduled',
		})

		const res = await page.request.get(
			`/index.php/apps/learniq/api/substitution/candidates?sessionId=${sessionId}`,
		)
		expect(res.ok()).toBeTruthy()
		const body = await res.json()
		expect(body.candidates[0]).toMatchObject({
			userId: 'admin',
			group: 'standby',
		})

		await page.goto('/index.php/apps/learniq/standby', {
			waitUntil: 'domcontentloaded',
		})
		await expect(
			page.getByRole('heading', { name: 'Standby hours' }),
		).toBeVisible({ timeout: 30_000 })
		await page.goto('/index.php/apps/learniq/my-timetable', {
			waitUntil: 'domcontentloaded',
		})
		await expect(page.locator('.my-timetable__standby').first()).toBeVisible({
			timeout: 30_000,
		})
	})
})
