import type { Page } from '@playwright/test'

import { test as base } from '@playwright/test'

/**
 * Navigate to the app and verify the session is active.
 * The storage state (cookies) is loaded from the global setup.
 * If for any reason the session expired, we fall back to a fresh login.
 */
async function ensureLoggedIn(page: Page): Promise<void> {
	// Probe the session with an OCS endpoint, NOT Nextcloud's dashboard app.
	//
	// This used to `page.goto('/index.php/apps/dashboard/')` and wait for
	// `domcontentloaded` within 20s, described as "a lightweight dashboard
	// check". It is not lightweight on an instance with many apps installed:
	// the dashboard pulls a widget bundle from every one of them. Measured on
	// the shared dev instance (44 custom apps): the HTML itself arrives in
	// 2.7s, but `domContentLoadedEventEnd` lands at **28.5s** — past the 20s
	// timeout. Because `loggedInPage` is the shared fixture, that single
	// number failed EVERY e2e spec, and it failed in the fixture rather than
	// in an assertion, so it read as "the app is broken" rather than "the
	// probe is too heavy".
	//
	// `/ocs/v2.php/cloud/user` returns a few hundred bytes, requires a real
	// session, and loads no app bundles at all — so it answers the only
	// question this function asks.
	const probe = await page.request.get('/ocs/v2.php/cloud/user?format=json', {
		headers: { 'OCS-APIRequest': 'true' },
		failOnStatusCode: false,
	})

	if (probe.ok()) {
		return
	}

	// Not authenticated — land on the login page and fall through to the
	// credential flow below.
	await page.goto('/index.php/login', {
		waitUntil: 'domcontentloaded',
		timeout: 30_000,
	})

	const url = page.url()
	if (url.includes('/login')) {
		// Session expired — fall back to fresh login
		const username = process.env.NC_ADMIN_USER ?? 'admin'
		const password = process.env.NC_ADMIN_PASS ?? 'admin'

		const passwordInput = page.locator('input[name="password"]')
		if (await passwordInput.isVisible({ timeout: 5_000 }).catch(() => false)) {
			await page.locator('input[name="user"]').fill(username)
			await page.locator('input[name="password"]').fill(password)
			await page
				.locator('#submit, button[type="submit"], input[type="submit"]')
				.first()
				.click()
			await page
				.waitForURL((url) => !url.pathname.includes('/login'), {
					timeout: 20_000,
				})
				.catch(() => {
					// Continue even if waitForURL times out
				})
		}
	}
}

type LearniqFixtures = {
	loggedInPage: Page
}

/**
 * Playwright fixture: `loggedInPage`.
 *
 * Provides a page that is pre-authenticated as the NC admin user.
 * Authentication is loaded from the globalSetup-saved storageState.
 * A lightweight dashboard check confirms the session is active.
 *
 * Usage:
 *   import { test } from '../fixtures'
 *   test('my test', async ({ loggedInPage }) => { ... })
 */
export const test = base.extend<LearniqFixtures>({
	loggedInPage: async ({ page }, use) => {
		await ensureLoggedIn(page)
		await use(page)
	},
})

export { expect } from '@playwright/test'
