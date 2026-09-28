import { expect, test } from './fixtures.ts'
import { createObject } from './or-api.ts'

/**
 * timetabling-lesson-note: a teacher adds a note, also for the next weeks,
 * and it shows on the lessons in "My timetable".
 *
 * The admin is the teacher here: the test makes a group with the admin as its
 * teacher and two lessons a week apart this week and next, adds a series note
 * from the first lesson and checks both lessons carry the topic. The learner
 * and substitute sides (a cover note never reaches a learner; a substitute
 * sees the cover lesson and its cover note) need three accounts and are
 * covered by TimetableControllerTest::testLearnerSeesLearnerNoteButNeverTheCoverNote
 * and ::testSubstituteSeesTheCoverNote, and LessonNoteAuthorGuardTest for the
 * refusal.
 *
 * @spec openspec/changes/timetabling-lesson-note/specs/personal-timetable/spec.md#scenario-a-teacher-sets-a-topic-for-next-week-s-lessons
 * @spec openspec/changes/timetabling-lesson-note/specs/personal-timetable/spec.md#scenario-a-learner-reads-the-topic-before-class
 */
test.describe('Lesson notes', () => {
	test('a teacher adds a note for this lesson and next week', async ({
		loggedInPage: page,
	}) => {
		const monday = new Date()
		monday.setHours(0, 0, 0, 0)
		monday.setDate(monday.getDate() - ((monday.getDay() + 6) % 7))
		const at = (weekOffset: number, hour: number) => {
			const d = new Date(monday)
			d.setDate(d.getDate() + 1 + weekOffset * 7)
			d.setHours(hour, 0, 0, 0)
			return d.toISOString()
		}

		const suffix = Date.now().toString(36)
		const cohortId = await createObject(page, 'cohort', {
			name: `E2E notes ${suffix}`,
			teacherIds: ['admin'],
			learnerIds: [],
		})
		test.skip(!cohortId, 'The cohort schema is not importable on this instance.')

		const title = `Wiskunde B ${suffix}`
		for (const week of [0, 1]) {
			await createObject(page, 'session', {
				cohortId,
				title,
				startsAt: at(week, 9),
				endsAt: at(week, 10),
				lifecycle: 'scheduled',
			})
		}

		await page.goto('/index.php/apps/learniq/my-timetable', {
			waitUntil: 'domcontentloaded',
		})
		const lesson = page.locator('.my-timetable__session', { hasText: title })
		await expect(lesson).toHaveCount(1, { timeout: 30_000 })

		await lesson
			.getByRole('button', { name: 'Add a note to this lesson' })
			.click()
		await page.getByLabel('Topic (optional)').fill('Hoofdstuk 4: kansrekening')
		await page.locator('#lesson-note-text').fill('Neem je rekenmachine mee.')
		await page.getByText('Also add it to this lesson in the next weeks').click()
		await page.getByRole('option', { name: 'The next week as well' }).click()
		await page.getByRole('button', { name: 'Save note' }).click()

		await expect(lesson).toContainText('Hoofdstuk 4: kansrekening')
		await page.getByRole('button', { name: 'Next week' }).click()
		await expect(
			page.locator('.my-timetable__session', { hasText: title }),
		).toContainText('Hoofdstuk 4: kansrekening', { timeout: 30_000 })
	})
})
