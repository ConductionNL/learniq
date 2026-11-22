/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Place a student's elective choices in their timetable
 * (timetabling-student-choice-placement, task 2.1b): a learner choosing two
 * electives that meet at the same time is warned in the picker, a chosen
 * elective's lessons show in My timetable although they belong to another
 * group, a withdrawn enrolment takes them away, and a lesson reached both
 * through the group and through an enrolment shows once.
 *
 * Written from the change's scenarios; not run in the build lane (needs an
 * instance with learniq's register imported and the frontend built).
 *
 * Covers:
 *   @e2e openspec/changes/timetabling-student-choice-placement/specs/timetable-student-choice/spec.md#scenario-two-electives-overlap
 *   @e2e openspec/changes/timetabling-student-choice-placement/specs/timetable-student-choice/spec.md#scenario-a-chosen-elective-appears
 *   @e2e openspec/changes/timetabling-student-choice-placement/specs/timetable-student-choice/spec.md#scenario-a-withdrawn-enrolment-disappears
 *   @e2e openspec/changes/timetabling-student-choice-placement/specs/timetable-student-choice/spec.md#scenario-no-duplicates
 */
import type { Page } from '@playwright/test'
import type { TempUser } from './live-fixtures.ts'

import { expect, test } from './fixtures.ts'
import { LiveFixtures, signInAs } from './live-fixtures.ts'

const PICKER = '/index.php/apps/learniq/subject-choice/picker'
const TIMETABLE = '/index.php/apps/learniq/my-timetable'

/**
 * A moment on a weekday of this week.
 *
 * @param weekday 1 is Monday.
 * @param hour The hour.
 * @param minute The minute.
 * @return The ISO timestamp.
 */
function thisWeek(weekday: number, hour: number, minute = 0): string {
	const day = new Date()
	day.setHours(hour, minute, 0, 0)
	day.setDate(day.getDate() - ((day.getDay() + 6) % 7) + weekday - 1)
	return day.toISOString()
}

/**
 * Pick one option of a picker select by typing its label.
 *
 * @param page The page.
 * @param name The select's accessible name.
 * @param option The option's label.
 */
async function pick(page: Page, name: string, option: string): Promise<void> {
	await page.getByRole('combobox', { name }).first().click()
	await page.keyboard.type(option)
	await page.getByRole('option', { name: new RegExp(option) }).first().click()
}

test.describe('elective choices in the picker and the timetable', () => {
	const fx = new LiveFixtures()
	let learner: TempUser
	const name = (subject: string) => `${subject} ${fx.run}`
	const course: Record<string, string> = {}
	let dramaEnrolment = ''

	test.beforeAll(async () => {
		learner = await fx.user('learner', ['learners'])
		await fx.object('learner-profile', { ncUserId: learner.id, givenName: 'Keuze', familyName: fx.run })
		for (const subject of ['Scheikunde', 'Aardrijkskunde', 'Drama', 'Wiskunde']) {
			course[subject] = await fx.object('course', {
				code: `${subject.slice(0, 3).toUpperCase()}-${fx.run}`,
				name: name(subject),
				level: 'vo',
				language: 'nl',
			})
		}
		await fx.object('curriculum-plan', {
			name: `Profiel ${fx.run}`,
			kind: 'pta',
			formula: 'weighted-average',
			requiredCourseIds: [course.Wiskunde],
			electiveCourseIds: [course.Scheikunde, course.Aardrijkskunde, course.Drama],
		})

		// Each elective meets in a group the learner is not in.
		const otherGroup = await fx.object('cohort', { name: `Keuzegroep ${fx.run}`, period: 'year', academicYear: '2026-2027', learnerIds: [], teacherIds: [] })
		// Scheikunde and Aardrijkskunde both on Tuesday in the third hour.
		for (const subject of ['Scheikunde', 'Aardrijkskunde']) {
			await fx.object('session', {
				cohortId: otherGroup,
				courseId: course[subject],
				title: name(subject),
				startsAt: thisWeek(2, 10, 30),
				endsAt: thisWeek(2, 11, 20),
				lifecycle: 'scheduled',
			})
		}
		await fx.object('session', {
			cohortId: otherGroup,
			courseId: course.Drama,
			title: name('Drama'),
			startsAt: thisWeek(4, 13),
			endsAt: thisWeek(4, 14),
			lifecycle: 'scheduled',
		})
		dramaEnrolment = await fx.object('enrolment', {
			learnerId: learner.id,
			courseId: course.Drama,
			source: 'subject-choice',
			lifecycle: 'active',
		})

		// Wiskunde reaches the learner both through her own group and through an enrolment.
		const ownGroup = await fx.object('cohort', { name: `Eigen klas ${fx.run}`, period: 'year', academicYear: '2026-2027', learnerIds: [learner.id], teacherIds: [] })
		await fx.object('session', {
			cohortId: ownGroup,
			courseId: course.Wiskunde,
			title: name('Wiskunde'),
			startsAt: thisWeek(1, 9),
			endsAt: thisWeek(1, 10),
			lifecycle: 'scheduled',
		})
		await fx.object('enrolment', {
			learnerId: learner.id,
			courseId: course.Wiskunde,
			source: 'subject-choice',
			lifecycle: 'active',
		})
	})

	test.afterAll(async () => {
		await fx.teardown()
	})

	// @e2e openspec/changes/timetabling-student-choice-placement/specs/timetable-student-choice/spec.md#scenario-two-electives-overlap
	test('two electives at the same time are warned about in the picker', async ({ browser }) => {
		test.setTimeout(120_000)
		const page = await signInAs(browser, learner)
		await page.goto(PICKER, { waitUntil: 'domcontentloaded' })
		await pick(page, 'Curriculum plan', `Profiel ${fx.run}`)
		await pick(page, 'For which child (or yourself)', 'Keuze')
		await pick(page, 'Electives', name('Scheikunde'))
		await pick(page, 'Electives', name('Aardrijkskunde'))
		const clash = page.getByTestId('elective-clash')
		await expect(clash).toHaveCount(1, { timeout: 30_000 })
		await expect(clash).toContainText(name('Scheikunde'))
		await expect(clash).toContainText(name('Aardrijkskunde'))
		await expect(clash).toContainText('meet at the same time')
		await expect(clash).toContainText('Tuesday')
		await page.context().close()
	})

	// @e2e openspec/changes/timetabling-student-choice-placement/specs/timetable-student-choice/spec.md#scenario-a-chosen-elective-appears
	// @e2e openspec/changes/timetabling-student-choice-placement/specs/timetable-student-choice/spec.md#scenario-no-duplicates
	test('the chosen elective shows in My timetable, and a lesson reached twice shows once', async ({ browser }) => {
		const page = await signInAs(browser, learner)
		await page.goto(TIMETABLE, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('.my-timetable__session', { hasText: name('Drama') })).toHaveCount(1, { timeout: 30_000 })
		await expect(page.locator('.my-timetable__session', { hasText: name('Wiskunde') })).toHaveCount(1)
		await page.context().close()
	})

	// @e2e openspec/changes/timetabling-student-choice-placement/specs/timetable-student-choice/spec.md#scenario-a-withdrawn-enrolment-disappears
	test('a withdrawn elective leaves My timetable', async ({ browser }) => {
		const admin = await fx.api()
		const res = await admin.post(`/index.php/apps/openregister/api/objects/${dramaEnrolment}/transition`, { data: { action: 'withdraw' } })
		expect(res.status(), await res.text()).toBeLessThan(300)
		const page = await signInAs(browser, learner)
		await page.goto(TIMETABLE, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('.my-timetable__session', { hasText: name('Wiskunde') })).toHaveCount(1, { timeout: 30_000 })
		await expect(page.locator('.my-timetable__session', { hasText: name('Drama') })).toHaveCount(0)
		await page.context().close()
	})
})
