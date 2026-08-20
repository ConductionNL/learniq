import { expect, test } from './fixtures.ts'

/**
 * Shell smoke tests — verify the Learniq SPA shell loads correctly.
 *
 * These tests navigate to /index.php/apps/learniq/ and check that:
 *   1. The CnAppRoot shell renders (no blank page / fatal error).
 *   2. The navigation contains the expected top-level menu items.
 *
 * @e2e apphost-adoption::spa-shell-and-deep-links-still-render
 */
test.describe('Learniq shell', () => {
	test('SPA loads without fatal JS error', async ({ loggedInPage: page }) => {
		const errors: string[] = []
		page.on('console', (msg) => {
			if (msg.type() === 'error') {
				errors.push(msg.text())
			}
		})

		await page.goto('/index.php/apps/learniq/')

		// Wait for the app root to be present
		await page.waitForSelector('body', { timeout: 15_000 })

		// The page should not be entirely blank
		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)

		// Filter out known non-fatal errors: network issues for fonts/icons, and
		// errors from other NC apps (Photos, Pipelinq, etc.) that are unrelated to Learniq.
		const fatalErrors = errors.filter(
			(e) =>
				!e.includes('favicon')
				&& !e.includes('font')
				&& !e.includes('Failed to load resource')
				&& !e.includes('net::ERR_ABORTED')
				&& !e.includes('Failed to fetch')
				&& !e.includes('ERR_CONNECTION_REFUSED')
				&& !e.includes('[FATAL] photos')
				&& !e.includes('Pipelinq'),
		)
		expect(
			fatalErrors,
			`Fatal JS errors: ${fatalErrors.join('; ')}`,
		).toHaveLength(0)
	})

	test('nav contains expected menu items', async ({ loggedInPage: page }) => {
		await page.goto('/index.php/apps/learniq/')

		// Wait for the document to be parsed. NOT `networkidle`: Nextcloud's
		// notification poll keeps a request in flight for the whole session, so
		// it never settles — it just burned its full timeout on every run and
		// then continued anyway (ADR-074 rule 4 / hydra gate 58).
		await page.waitForLoadState('domcontentloaded')

		const pageContent = await page.content()

		// Menu entries the manifest declares with no `visibleIf` gate — every session
		// must see these. menu-six-main-items adds the unconditional top-level
		// "Progress" and "My learning" groups (People/Learning/Dashboard were already
		// unconditional pre-change; the group itself carries no visibleIf even though
		// some of its individual children do).
		const expectedItems = [
			'Dashboard',
			'People',
			'Progress',
			'My learning',
			'Courses',
			'Enrolments',
			'Credentials',
		]
		for (const item of expectedItems) {
			expect(
				pageContent,
				`Menu item "${item}" should be present in the page`,
			).toContain(item)
		}

		// ── WHERE THE ROLE-GATE ASSERTION WENT, STATED RATHER THAN DROPPED ──
		//
		// This test used to read `primaryRole` from initial state and assert that the
		// visibleIf-gated Compliance leaf appeared for entitled roles and was absent
		// for everyone else. menu-six-main-items (2026-08-20) moved that leaf out of
		// the nav entirely: ADR-044 §4 cards-collapse turned it into a CARD on the
		// Compliance landing page, relabelled "Overview".
		//
		// Probing the nav for it now would assert 0 forever and keep passing while
		// testing nothing — the exact failure this file already warns about twice.
		// So the probe is removed rather than left to rot.
		//
		// COVERAGE THIS GIVES UP, NAMED: role-gated visibility of that leaf is no
		// longer asserted anywhere in this spec. `navCardEntry` still carries
		// `visibleIf`, so the behaviour still exists — it is the TEST that moved, and
		// a card-level gating assertion belongs with the card grid, not the shell.
		// Filed as follow-up work rather than pretended away.
		//
		// What IS asserted below is stronger than a label check: that the collapse
		// preserved reachability.
		const complianceGroupNav = page
			.locator('#app-navigation-vue a, #app-navigation-vue button')
			.filter({ hasText: /^\s*Compliance\s*$/ })
		expect(
			await complianceGroupNav.count(),
			'top-level "Compliance" group (GroupCompliance) has no visibleIf gate and must be visible to every session',
		).toBeGreaterThan(0)

		// The collapse must not have lost anything: every former leaf is now a card,
		// and a card whose route does not resolve renders aria-disabled rather than
		// vanishing (ADR-044 §5). Zero disabled cards means every route still exists.
		//
		// Navigate by CLICKING the nav entry, not by building a url. A hardcoded
		// `/index.php/apps/learniq/<path>` breaks on an instance that serves pretty
		// urls: `createWebHistory(generateUrl('/apps/learniq'))` gives the router a
		// base of `/apps/learniq`, the `/index.php`-prefixed pathname matches no
		// route, and the app silently renders the DEFAULT route instead — which is
		// how this assertion first failed, looking like a missing card grid.
		// Clicking is also the path a user actually takes.
		await complianceGroupNav.first().click()
		const grid = page.locator('.cn-nav-card-grid')
		await expect(grid).toBeVisible({ timeout: 30_000 })
		expect(
			await grid.locator('a').count(),
			'the Compliance landing page must render one card per former nav leaf',
		).toBeGreaterThan(0)
		expect(
			await grid.locator('[aria-disabled="true"]').count(),
			'a card rendering aria-disabled means its route no longer resolves — ADR-044 §5 forbids losing a reachable function',
		).toBe(0)
	})

	// REGRESSION GUARD FOR AN ORPHAN THIS REFACTOR ACTUALLY CREATED.
	//
	// menu-six-main-items first RETIRED DashboardAdmin/Teacher/Student from the
	// nav, on the reasoning that "their pages stay routable". They were — and
	// still unreachable. The top-level Dashboard routes to `/`, which renders
	// only the user's DEFAULT role view, so an admin who also teaches had no
	// way left to open the teaching dashboard. gate-53's removals-invariant
	// caught it; nothing in this suite would have.
	//
	// Routable is not reachable (ADR-044 §5). This asserts REACHABILITY: the
	// three role views hang off Dashboard as real links, and each one lands on
	// its own page rather than falling back to the default dashboard.
	test('every role dashboard stays reachable from the nav', async ({
		loggedInPage: page,
	}) => {
		await page.goto('/index.php/apps/learniq/')
		await page.waitForLoadState('domcontentloaded')

		// admin sees all three (canAdmin/canTeach/canLearn are all true for admin).
		for (const [label, slug] of [
			['Administration', '/dashboards/admin'],
			['Teaching', '/dashboards/teaching'],
			['Learner', '/dashboards/my-learning'],
		]) {
			const entry = page
				.locator('#app-navigation-vue a')
				.filter({ hasText: new RegExp(`^\\s*${label}\\s*$`) })
			expect(
				await entry.count(),
				`role dashboard "${label}" must be reachable from the nav, not merely routable`,
			).toBeGreaterThan(0)
			expect(
				await entry.first().getAttribute('href'),
				`"${label}" must link to its own page — a fallback to the default dashboard is the orphan this test exists to catch`,
			).toContain(slug)
		}
	})
})
