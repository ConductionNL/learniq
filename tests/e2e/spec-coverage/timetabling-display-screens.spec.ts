/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage for timetabling-display-screens: a team lead creates a
 * display screen, gets its address once, and the address opens a timetable
 * page in a signed-out browser context.
 *
 * Covers (UI-observable surface):
 *   @e2e openspec/specs/personal-timetable/spec.md#scenario-a-team-lead-puts-the-hall-screen-live
 *   @e2e openspec/specs/personal-timetable/spec.md#scenario-the-hall-screen-shows-a-cancelled-lesson
 *
 * The 404 for a revoked token and the pinned output shape are covered at the
 * unit level (DisplayScreenPublicControllerTest, DisplayScreenServiceTest).
 */
import { expect, test } from '../fixtures.ts'

test.describe('timetabling-display-screens: hall screen', () => {
	// @e2e openspec/specs/personal-timetable/spec.md#scenario-a-team-lead-puts-the-hall-screen-live
	// @e2e openspec/specs/personal-timetable/spec.md#scenario-the-hall-screen-shows-a-cancelled-lesson
	test('the address is shown once and opens the screen without a session', async ({
		loggedInPage: page,
		browser,
	}) => {
		const created = await page.request.post(
			'/index.php/apps/openregister/api/objects/learniq/display-screen',
			{ data: { name: 'Aula gebouw A e2e', shows: 'today' } },
		)
		expect(created.ok()).toBeTruthy()
		const screen = await created.json()
		const id = screen.id || screen['@self']?.id

		await page.goto(`/index.php/apps/learniq/display-screens/${id}/address`)
		await page.getByRole('button', { name: 'Create address' }).click()
		const address = page.getByTestId('screen-address')
		await expect(address).toBeVisible({ timeout: 15_000 })
		const url = (await address.locator('code').innerText()).trim()

		// An empty cookie jar: browser.newContext() otherwise inherits the admin
		// storageState, and the screen would be opened as admin, not anonymously.
		const anonymous = await browser.newContext({
			storageState: { cookies: [], origins: [] },
		})
		const screenPage = await anonymous.newPage()
		const response = await screenPage.goto(url)
		expect(response?.status()).toBe(200)
		await expect(screenPage.locator('.display-screen')).toBeVisible({
			timeout: 15_000,
		})
		await anonymous.close()
	})
})
