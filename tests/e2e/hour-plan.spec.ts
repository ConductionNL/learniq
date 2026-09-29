import { expect, test } from './fixtures.ts'
import { createObject } from './or-api.ts'

/**
 * timetabling-multi-year-hour-plan: a coordinator fills an hour plan, sees a
 * year below its norm, and the teaching activities list the group.
 *
 * The admin makes a two-year programme with two courses, an active plan for
 * the intake year of a group in its second year, and a group in that year.
 *
 * @spec openspec/changes/timetabling-multi-year-hour-plan/specs/school-structure/spec.md#scenario-a-coordinator-plans-three-years-of-a-programme
 * @spec openspec/changes/timetabling-multi-year-hour-plan/specs/school-structure/spec.md#scenario-a-year-below-its-norm-is-marked
 * @spec openspec/changes/timetabling-multi-year-hour-plan/specs/school-structure/spec.md#scenario-a-timetabler-exports-next-year-s-activities
 */
test.describe('Hour plans', () => {
	test('enter hours, see a shortfall, list the activities', async ({
		loggedInPage: page,
	}) => {
		const suffix = Date.now().toString(36)
		const courseA = await createObject(page, 'course', {
			name: `Nederlands ${suffix}`,
		})
		const courseB = await createObject(page, 'course', {
			name: `Engels ${suffix}`,
		})
		test.skip(
			!courseA || !courseB,
			'The course schema is not importable on this instance.',
		)
		const programmeId = await createObject(page, 'programme', {
			name: `Medewerker marketing ${suffix}`,
			courseIds: [courseA, courseB],
		})
		const planId = await createObject(page, 'hour-plan', {
			name: `Urenplan ${suffix}`,
			programmeId,
			intakeYear: '2025-2026',
			durationYears: 2,
			lines: [{ courseId: courseA, programmeYear: 2, contactHours: 300 }],
			yearNorms: [{ programmeYear: 2, contactHours: 700 }],
		})
		test.skip(
			!planId,
			'The hour-plan schema is not importable on this instance.',
		)

		await page.goto(`/index.php/apps/learniq/hour-plans/${planId}`, {
			waitUntil: 'domcontentloaded',
		})
		await expect(page.getByText('400 hours short of the norm')).toBeVisible({
			timeout: 30_000,
		})
		await page
			.getByLabel(`Contact hours for Engels ${suffix}, Year 2`)
			.fill('340')
		await page.getByLabel(`Contact hours for Engels ${suffix}, Year 2`).blur()
		await expect(page.getByText('60 hours short of the norm')).toBeVisible()
		await page.getByRole('button', { name: 'Save' }).click()
		await page.getByRole('button', { name: 'Activate' }).click()
		await expect(page.getByText('The hour plan is active.')).toBeVisible()

		await createObject(page, 'cohort', {
			name: `MV2A ${suffix}`,
			programmeId,
			programmeYear: 2,
			academicYear: '2026-2027',
		})
		await page.goto('/index.php/apps/learniq/teaching-activities', {
			waitUntil: 'domcontentloaded',
		})
		await page.getByText('School year').click()
		await page.getByRole('option', { name: '2026-2027' }).click()
		await expect(
			page.getByRole('heading', {
				name: `MV2A ${suffix}, year 2 of the programme`,
			}),
		).toBeVisible({ timeout: 30_000 })
	})
})
