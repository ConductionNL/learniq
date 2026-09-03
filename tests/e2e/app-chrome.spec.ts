/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The bottom-left app chrome, in a browser (ADR-114).
 *
 * gate-107 reads the manifest and can prove the entries are DECLARED. It
 * cannot prove they RENDER, and this programme has already produced three
 * defects of exactly that shape: an icon name that is not registered renders
 * NO glyph (not a fallback, not a console error), an entry whose `route` names
 * a page the app does not host renders a row that goes nowhere, and
 * `nav.includePersonalSettings: false` silently removed the entry that reaches
 * the user's notification preferences.
 *
 * Every card here points at a page that ALREADY EXISTED and had no menu entry
 * at all. That is what this spec is really for: the cards are the first entry
 * point those seven routes have ever had, so a card pointing at a route that
 * does not resolve would leave them exactly as unreachable as before, while
 * looking fixed.
 *
 * ⚠️ SCOPE EVERY SELECTOR TO `[data-testid="cn-nav"]`. An unscoped selector
 * also matches Nextcloud's own user menu, which is attached-but-hidden:
 * `waitFor({state:'attached'})` passes on it and the click never becomes
 * actionable, so the spec fails with "Target page has been closed" — a timeout
 * wearing a crash's clothes.
 *
 * ⚠️ SETTINGS ENTRIES ARE ATTACHED, NOT VISIBLE, inside a collapsed foldout.
 */

import { expect, test } from '@playwright/test'

const APP_BASE = '/apps/learniq'

test.describe('app chrome (ADR-114)', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(`${APP_BASE}/`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible({
			timeout: 30_000,
		})
	})

	test('the footer reads Documentation, Reports, Features & roadmap, each with a glyph', async ({
		page,
	}) => {
		const footer = page.locator(
			'[data-testid="cn-nav"] .cn-app-nav__footer-list',
		)
		await expect(footer).toBeAttached({ timeout: 15_000 })

		const rows = footer.locator('li')
		const texts = (await rows.allInnerTexts())
			.map((t) => t.trim())
			.filter(Boolean)

		const seen = texts.filter((t) => /Documentation|Reports|roadmap/i.test(t))
		expect(seen.length).toBe(3)
		expect(seen[0]).toMatch(/Documentation/i)
		expect(seen[1]).toMatch(/Reports/i)
		expect(seen[2]).toMatch(/roadmap/i)

		for (const row of await rows.all()) {
			await expect(
				row.locator('svg, .material-design-icon').first(),
			).toBeAttached()
		}
	})

	test('Reports lists all seven readings', async ({ page }) => {
		const nav = page.locator('[data-testid="cn-nav"]')
		await nav.locator('[data-testid="cn-nav-entry-ReportsMenu"]').click()
		await expect(page).toHaveURL(/\/apps\/learniq\/reports(\?|$)/, {
			timeout: 15_000,
		})

		for (const label of [
			'Study progress risk',
			'Engagement flags',
			'Group trends',
			'Skills gaps',
			'Item statistics',
			'Course quality',
			'Workplace visits',
		]) {
			await expect(
				page.getByText(label, { exact: false }).first(),
			).toBeVisible({ timeout: 15_000 })
		}
	})

	test('every carded route resolves, because the card is its only entry point', async ({
		page,
	}) => {
		// All seven had NO menu entry — reachable only by someone who already
		// knew the URL. A card pointing at a route that does not resolve would
		// leave them as unreachable as before, while looking fixed.
		for (const path of [
			'/study-progress/risk-dashboard',
			'/progress/engagement-flags',
			'/progress/group-trend-heatmap',
			'/competencies/skills-gap',
			'/assessments/item-statistics',
			'/course-evaluation/quality-report',
			'/bpv/visit-reports',
		]) {
			await page.goto(`${APP_BASE}${path}`)
			await expect(page).toHaveURL(
				new RegExp(`${path.replace(/\//g, '\\/')}(\\?|$)`),
				{ timeout: 15_000 },
			)
			await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible()
		}
	})

	test('the export and the thresholds are deliberately not carded', async ({
		page,
	}) => {
		// Two neighbouring orphans stay orphans, because a gate finding is a
		// question rather than an instruction. AuditPackExport BUILDS an export
		// and EngagementRiskThresholds CONFIGURES the thresholds that produce
		// the flags — both are things you DO, and ADR-097 keeps work out of a
		// page of readings. If a later sweep cards them, this fails.
		await page.goto(`${APP_BASE}/reports`)
		const main = page.locator('main, .app-content').first()
		await expect(main).toBeVisible({ timeout: 30_000 })
		await expect(main.getByText('Audit pack', { exact: false })).toHaveCount(0)
		await expect(main.getByText('threshold', { exact: false })).toHaveCount(0)
	})

	test('the settings foldout carries Personal settings, Admin settings and Flows', async ({
		page,
	}) => {
		const nav = page.locator('[data-testid="cn-nav"]')

		await expect(nav.locator('[data-testid="cn-nav-settings"]')).toBeAttached({
			timeout: 15_000,
		})
		await expect(
			nav.locator('[data-testid="cn-nav-personal-settings"]'),
		).toBeAttached()
		await expect(
			nav.locator('[data-testid="cn-nav-entry-FlowsMenu"]'),
		).toBeAttached()

		const admin = nav.locator('[data-testid="cn-nav-admin-settings"]')
		await expect(admin).toBeAttached()
		await expect(admin).toHaveAttribute('href', /\/settings\/admin\/learniq$/)
	})
})
