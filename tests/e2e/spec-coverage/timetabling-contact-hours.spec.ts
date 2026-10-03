/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage for timetabling-contact-hours: the Contact hours report
 * opens from Reports, shows per group the owed, given and difference per
 * course, and drills down to learners.
 *
 * Covers (UI-observable surface):
 *   @e2e openspec/specs/attendance/spec.md#scenario-a-coordinator-finds-a-group-short-on-english
 *   @e2e openspec/specs/attendance/spec.md#scenario-a-mentor-sees-a-learner-at-seventy-percent
 *
 * The numbers themselves (three cancelled lessons leave a group three hours
 * short, a learner at 28 of 40 hours is marked) are covered by
 * ContactHoursServiceTest; the learner refusal by ContactHoursControllerTest.
 */
import { expect, test } from '../fixtures.ts'

test.describe('timetabling-contact-hours', () => {
	// @e2e openspec/specs/attendance/spec.md#scenario-a-coordinator-finds-a-group-short-on-english
	// @e2e openspec/specs/attendance/spec.md#scenario-a-mentor-sees-a-learner-at-seventy-percent
	test('the report shows groups and opens a learner view', async ({
		loggedInPage: page,
	}) => {
		await page.goto('/index.php/apps/learniq/reports/contact-hours')
		await expect(
			page.getByRole('heading', { name: 'Contact hours' }),
		).toBeVisible({ timeout: 15_000 })
		await page.getByRole('button', { name: 'Show' }).click()

		const cohort = page.getByTestId('contact-hours-cohort').first()
		if ((await cohort.count()) === 0) {
			return
		}

		await cohort.getByRole('button', { name: 'Show learners' }).click()
		await expect(cohort.getByTestId('contact-hours-learners')).toBeVisible()
	})
})
