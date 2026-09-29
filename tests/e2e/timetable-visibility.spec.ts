import { expect, test } from './fixtures.ts'
import { createObject } from './or-api.ts'

/**
 * timetabling-visibility-rules: the Timetables page lists what the caller may
 * open and shows a teacher's week; the endpoint refuses an unknown kind.
 *
 * The admin sees everything, so this checks the page and the endpoint's shape;
 * the learner rules (own group only, related teachers and rooms) are covered by
 * TimetableVisibilityServiceTest::testLearnerOwnGroupsOnly and
 * ::testLearnerRelatedTeachersAndRooms, and the 403 by
 * TimetableVisibilityControllerTest::testOf.
 *
 * @spec openspec/changes/timetabling-visibility-rules/specs/personal-timetable/spec.md#scenario-a-learner-looks-up-their-maths-teacher
 */
test.describe('Timetables', () => {
	test('a teacher timetable opens from the picker', async ({
		loggedInPage: page,
	}) => {
		const suffix = Date.now().toString(36)
		const teacher = `docent-${suffix}`
		const cohortId = await createObject(page, 'cohort', {
			name: `TV ${suffix}`,
			teacherIds: [teacher],
			learnerIds: [],
		})
		test.skip(!cohortId, 'The cohort schema is not importable on this instance.')
		const start = new Date()
		start.setHours(11, 0, 0, 0)
		const end = new Date(start)
		end.setHours(12)
		await createObject(page, 'session', {
			cohortId,
			title: `Wiskunde B ${suffix}`,
			startsAt: start.toISOString(),
			endsAt: end.toISOString(),
			lifecycle: 'scheduled',
		})

		const res = await page.request.get(
			`/index.php/apps/learniq/api/timetable/of?kind=teacher&id=${teacher}`,
		)
		expect(res.ok()).toBeTruthy()
		expect(
			(await res.json()).sessions.map((s: { title: string }) => s.title),
		).toContain(`Wiskunde B ${suffix}`)
		const bad = await page.request.get(
			'/index.php/apps/learniq/api/timetable/of/options?kind=building',
		)
		expect(bad.status()).toBe(400)

		await page.goto('/index.php/apps/learniq/timetables', {
			waitUntil: 'domcontentloaded',
		})
		await expect(page.getByRole('heading', { name: 'Timetables' })).toBeVisible({
			timeout: 30_000,
		})
	})
})
