/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Quiet hours through OpenRegister's delivery window
 * (notification-quiet-hours-through-openregister, task 2.2). A teacher with
 * no delivery window turns quiet hours on from 22:00 to 07:00 in learniq's
 * settings dialog, reloads, and reads the same values back, switched on. The
 * window is also read from `GET /apps/openregister/api/notification-delivery-window`
 * as her, so the values come from OpenRegister and not from the browser.
 *
 * NOT BUILT YET. Tasks 1.1 to 1.3 of the change are open: the settings dialog
 * is nextcloud-vue's CnNotificationPreferences section, which draws the
 * notification switches and no quiet-hours block, and nothing in `src/`
 * calls the delivery-window endpoint. The test is written against the board
 * (LqMeldingen) and the design's labels and skips until the panel exists.
 *
 * Covers:
 *   @e2e openspec/changes/notification-quiet-hours-through-openregister/specs/scholiq-notifications/spec.md#scenario-a-teacher-sets-quiet-hours
 */
import type { APIRequestContext, Page } from '@playwright/test'
import type { TempUser } from './live-fixtures.ts'

import { expect, test } from './fixtures.ts'
import { apiAs, LiveFixtures, signInAs } from './live-fixtures.ts'

const WINDOW = '/index.php/apps/openregister/api/notification-delivery-window'

/**
 * Open learniq and its user settings dialog, on the quiet-hours block.
 *
 * @param page A signed-in page.
 * @return The quiet-hours section.
 */
async function openQuietHours(page: Page) {
	await page.goto('/index.php/apps/learniq/')
	const nav = page.locator('[data-testid="cn-nav"]')
	await expect(nav).toBeVisible({ timeout: 60_000 })
	// The settings foldout is collapsed; its entries are attached, not visible.
	await nav.locator('[data-testid="cn-nav-settings"]').click()
	await nav.locator('[data-testid="cn-nav-personal-settings"]').click()
	const section = page.getByRole('dialog').locator('section').filter({ hasText: 'Quiet hours' }).first()
	await expect(section).toBeVisible({ timeout: 30_000 })
	return section
}

test.describe('quiet hours through the delivery window', () => {
	const fx = new LiveFixtures()
	let teacher: TempUser
	let api: APIRequestContext

	test.beforeAll(async () => {
		teacher = await fx.user('quiet', ['instructors'])
		api = await apiAs(teacher.id, teacher.password)
	})

	test.afterAll(async () => {
		// Clear her window, then remove her.
		await api?.put(WINDOW, { data: { enabled: false } }).catch(() => null)
		await api?.dispose()
		await fx.teardown()
	})

	// @e2e openspec/changes/notification-quiet-hours-through-openregister/specs/scholiq-notifications/spec.md#scenario-a-teacher-sets-quiet-hours
	test('a teacher sets 22:00 to 07:00 and reads it back after a reload', async ({ browser }) => {
		test.skip(
			true,
			'Not built: tasks 1.1-1.3 are open. The settings dialog (CnNotificationPreferences) has no quiet-hours block and nothing in src/ calls the delivery-window endpoint.',
		)
		test.setTimeout(180_000)
		const none = await api.get(WINDOW)
		expect(none.status(), await none.text()).toBe(200)
		expect((await none.json()).enabled).toBe(false)

		const page = await signInAs(browser, teacher)
		let section = await openQuietHours(page)
		await section.getByRole('checkbox', { name: 'Turn on quiet hours' }).check()
		await section.getByLabel('Start').fill('22:00')
		await section.getByLabel('End').fill('07:00')
		await section.getByRole('button', { name: 'Save' }).click()

		// OpenRegister holds the window, with her browser's time zone.
		await expect.poll(async () => (await (await api.get(WINDOW)).json()).enabled, { timeout: 15_000 }).toBe(true)
		const stored = await (await api.get(WINDOW)).json()
		expect(stored.start).toBe('22:00')
		expect(stored.end).toBe('07:00')
		expect(String(stored.timezone ?? '')).not.toBe('')

		// Reopen the panel after a reload: the values come back from OpenRegister.
		await page.reload()
		section = await openQuietHours(page)
		await expect(section.getByRole('checkbox', { name: 'Turn on quiet hours' })).toBeChecked()
		await expect(section.getByLabel('Start')).toHaveValue('22:00')
		await expect(section.getByLabel('End')).toHaveValue('07:00')
		await page.context().close()
	})
})
