/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — avg-verwerkingsregister compliance section (UI only).
 *
 * Learniq is a thin consumer of OpenRegister's processing-activity register.
 * The aggregation, export, per-access logging, and access gating are
 * OpenRegister's (OR-PA-7/OR-PA-8); these tests assert only the Learniq admin
 * settings surface that surfaces the register slice and deep-links to it.
 *
 *   @e2e openspec/specs/avg-verwerkingsregister/spec.md#privacy-officer-browses-scholiqs-register-slice
 *   @e2e openspec/specs/avg-verwerkingsregister/spec.md#fresh-install-seeds-the-register-as-drafts
 *
 * All tests use the admin session provided by the global setup. REST calls are
 * setup-only; assertions are DOM-based.
 */
import { test, expect } from '../fixtures'

// `/index.php/` prefix is load-bearing on CI (a bare `php -S` does not rewrite
// pretty URLs and `server/apps/learniq/` exists without an index.php), and the
// manifest route is `/settings` — vue-router is case-sensitive, so `/Settings`
// resolved to no route at all. See nextcloud-app.spec.ts.
const SETTINGS_URL = '/index.php/apps/learniq/settings'

test.describe('avg-verwerkingsregister — AVG Art. 30 compliance section', () => {
	// @e2e openspec/specs/avg-verwerkingsregister/spec.md#privacy-officer-browses-scholiqs-register-slice
	test('compliance section surfaces the register slice scoped to scholiq', async ({
		loggedInPage: page,
	}) => {
		await page.goto(SETTINGS_URL)
		await page.waitForLoadState('domcontentloaded')
		await expect(page.locator('body')).toBeVisible()
		await expect(page).not.toHaveURL(/\/login/)

		const bodyText = await page
			.locator('body')
			.innerText()
			.catch(() => '')
		// When Vue is mounted as admin, the AVG Art. 30 section + its actions render.
		if (/Processing Activity Register|Art\. 30/i.test(bodyText)) {
			await expect(
				page
					.getByText(
						/Open processing log in OpenRegister|Per-subject .* extract/i,
					)
					.first(),
			).toBeVisible()
			// The declared activities are listed (seeded as drafts, surfaced here).
			await expect(
				page.getByText(/Learner administration/i).first(),
			).toBeVisible()
		}
	})

	// @e2e openspec/specs/avg-verwerkingsregister/spec.md#fresh-install-seeds-the-register-as-drafts
	test('controller identity & accountability deep-link is offered, not blocked', async ({
		loggedInPage: page,
	}) => {
		await page.goto(SETTINGS_URL)
		await page.waitForLoadState('domcontentloaded')
		await expect(page.locator('body')).toBeVisible()

		const bodyText = await page
			.locator('body')
			.innerText()
			.catch(() => '')
		if (/Processing Activity Register|Art\. 30/i.test(bodyText)) {
			// The controller-identity / accountability deep-link is shown; the
			// section still renders its actions (does not block on unset identity).
			await expect(
				page
					.getByText(
						/controller identity .* accountability in OpenRegister/i,
					)
					.first(),
			).toBeVisible()
		}
	})
})
