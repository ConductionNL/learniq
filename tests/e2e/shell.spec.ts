import { test, expect } from './fixtures'

/**
 * Shell smoke tests — verify the Scholiq SPA shell loads correctly.
 *
 * These tests navigate to /index.php/apps/learniq/ and check that:
 *   1. The CnAppRoot shell renders (no blank page / fatal error).
 *   2. The navigation contains the expected top-level menu items.
 *
 * @e2e apphost-adoption::spa-shell-and-deep-links-still-render
 */
test.describe('Scholiq shell', () => {
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
		// errors from other NC apps (Photos, Pipelinq, etc.) that are unrelated to Scholiq.
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
		// must see these.
		const expectedItems = ['Dashboard', 'Courses', 'Enrolments', 'Credentials']
		for (const item of expectedItems) {
			expect(
				pageContent,
				`Menu item "${item}" should be present in the page`,
			).toContain(item)
		}

		// "Compliance" used to be in the list above. It is NOT unconditional: the
		// manifest gates it on
		//     visibleIf: { "user.primaryRole": { in: ["compliance-officer", "hr"] } }
		// and the CI session resolves `primaryRole` to the default `learner`
		// (src/main.js: loadState('learniq', 'primaryRole', 'learner')). Asserting it
		// unconditionally contradicted the manifest's own declared visibility rule and
		// failed on CI run 30798535945.
		//
		// Inverted into a real assertion rather than dropped: for a session whose role
		// is not one of the gated roles, the entry MUST be absent. That is a stronger
		// check than the one it replaces — it proves `visibleIf` is enforced, which the
		// old assertion could not have detected being broken.
		// Read initial state from the `value` ATTRIBUTE, not `textContent`.
		// Nextcloud renders initial state as `<input type="hidden" value="<base64>">`,
		// so `textContent` is the empty string whether the state is set or not — a
		// property that cannot say "no" is not evidence. The previous version of this
		// probe used `textContent`, so `primaryRole` was ALWAYS null and the branch
		// below always ran, regardless of who was signed in.
		const primaryRole = await page.evaluate(() => {
			const el = document.querySelector('#initial-state-learniq-primaryRole')
			const raw = el?.getAttribute('value') ?? ''
			try {
				return raw ? JSON.parse(atob(raw)) : null
			} catch {
				return null
			}
		})

		// The gate is `visibleIf: { "user.primaryRole": { in: [...] } }`.
		// `admin` was ADDED to it by the `fix-dead-role-gates` change: Compliance
		// is the app's headline compliance-training feature and it was invisible
		// to every administrator, because the resolver returns `admin` first and
		// returns exactly one value. This assertion previously encoded that defect
		// as intended behaviour.
		const complianceEntitled = ['compliance-officer', 'hr', 'admin']
		const complianceNav = page
			.locator('#app-navigation-vue a, #app-navigation-vue button')
			.filter({ hasText: /^\s*Compliance\s*$/ })
		const complianceCount = await complianceNav.count()

		if (primaryRole !== null && complianceEntitled.includes(primaryRole)) {
			expect(
				complianceCount,
				`"Compliance" is visibleIf-gated on primaryRole in [${complianceEntitled.join(', ')}]; this session is "${primaryRole}", so it MUST appear in the app nav`,
			).toBeGreaterThan(0)
		} else {
			expect(
				complianceCount,
				`"Compliance" is visibleIf-gated on primaryRole in [${complianceEntitled.join(', ')}]; this session is "${primaryRole}", so it must not appear in the app nav`,
			).toBe(0)
		}
	})
})
