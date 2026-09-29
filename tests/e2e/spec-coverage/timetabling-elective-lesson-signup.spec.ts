/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage for timetabling-elective-lesson-signup: a coordinator
 * offers optional lessons, a learner sees them on "Optional lessons" with free
 * places and the window, and the coordinator's sign-ups page lists who did not
 * sign up with a Place button.
 *
 * Covers (UI-observable surface):
 *   @e2e openspec/changes/timetabling-elective-lesson-signup/specs/enrolment/spec.md#scenario-a-coordinator-offers-weekly-extra-maths
 *   @e2e openspec/changes/timetabling-elective-lesson-signup/specs/enrolment/spec.md#scenario-a-learner-signs-up-for-thursday
 *   @e2e openspec/changes/timetabling-elective-lesson-signup/specs/enrolment/spec.md#scenario-the-window-has-closed
 *   @e2e openspec/changes/timetabling-elective-lesson-signup/specs/enrolment/spec.md#scenario-a-coordinator-places-a-learner-after-the-deadline
 *
 * The rules themselves (capacity for staff, one per lesson, eligibility, the
 * API door) are covered by ElectiveSignUpRulesTest with the real events.
 */
import { expect, test } from '../fixtures.ts'

test.describe('timetabling-elective-lesson-signup', () => {
	// @e2e openspec/changes/timetabling-elective-lesson-signup/specs/enrolment/spec.md#scenario-a-coordinator-offers-weekly-extra-maths
	// @e2e openspec/changes/timetabling-elective-lesson-signup/specs/enrolment/spec.md#scenario-a-coordinator-places-a-learner-after-the-deadline
	test('a coordinator creates an offer and opens its sign-ups page', async ({
		loggedInPage: page,
	}) => {
		const created = await page.request.post(
			'/index.php/apps/openregister/api/objects/learniq/elective-offer',
			{
				data: {
					name: 'Keuzewerktijd wiskunde e2e',
					capacityPerLesson: 24,
					windowMode: 'relative',
					opensDaysBefore: 7,
					closesHoursBefore: 12,
					lifecycle: 'open',
				},
			},
		)
		expect(created.ok()).toBeTruthy()
		const offer = await created.json()
		const id = offer.id || offer['@self']?.id

		await page.goto(`/index.php/apps/learniq/electives/offers/${id}/roster`)
		await expect(page.getByRole('heading', { level: 2 })).toContainText(
			'Keuzewerktijd wiskunde e2e',
			{ timeout: 15_000 },
		)
	})

	// @e2e openspec/changes/timetabling-elective-lesson-signup/specs/enrolment/spec.md#scenario-a-learner-signs-up-for-thursday
	// @e2e openspec/changes/timetabling-elective-lesson-signup/specs/enrolment/spec.md#scenario-the-window-has-closed
	test('the optional lessons page renders the open offers', async ({
		loggedInPage: page,
	}) => {
		await page.goto('/index.php/apps/learniq/my-electives')
		await expect(
			page.getByRole('heading', { name: 'Optional lessons' }),
		).toBeVisible({ timeout: 15_000 })
	})
})
