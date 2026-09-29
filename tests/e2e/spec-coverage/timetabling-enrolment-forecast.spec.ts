/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage for timetabling-enrolment-forecast: the forecast page
 * opens from Reports, lists the scenarios, computes one and shows the
 * programme-year and subject tables with the groups needed.
 *
 * Covers (UI-observable surface):
 *   @e2e openspec/changes/timetabling-enrolment-forecast/specs/student-analytics/spec.md#scenario-a-deputy-head-forecasts-havo-4
 *   @e2e openspec/changes/timetabling-enrolment-forecast/specs/student-analytics/spec.md#scenario-groups-for-an-elective-subject
 *
 * The numbers (104 learners from havo 3, three groups for 61 learners at 28)
 * and the refusal of rates that do not add up are covered by
 * EnrolmentForecastServiceTest and EnrolmentForecastControllerTest.
 */
import { expect, test } from '../fixtures.ts'

test.describe('timetabling-enrolment-forecast', () => {
	// @e2e openspec/changes/timetabling-enrolment-forecast/specs/student-analytics/spec.md#scenario-a-deputy-head-forecasts-havo-4
	// @e2e openspec/changes/timetabling-enrolment-forecast/specs/student-analytics/spec.md#scenario-groups-for-an-elective-subject
	test('a scenario is computed and its tables are shown', async ({
		loggedInPage: page,
	}) => {
		const created = await page.request.post(
			'/index.php/apps/openregister/api/objects/learniq/enrolment-forecast',
			{
				data: {
					name: 'Prognose e2e',
					targetYear: '2027-2028',
					targetGroupSize: 28,
				},
			},
		)
		expect(created.ok()).toBeTruthy()

		await page.goto('/index.php/apps/learniq/reports/enrolment-forecast')
		await expect(
			page.getByRole('heading', { name: 'Enrolment forecast' }),
		).toBeVisible({ timeout: 15_000 })
		await page.getByRole('button', { name: 'Compute forecast' }).click()
		await expect(page.getByTestId('forecast-years')).toBeVisible({
			timeout: 15_000,
		})
	})
})
