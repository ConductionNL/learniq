/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * menu-six-main-items, Task 9 (test-plan TC-1, TC-2): an admin, a teacher and
 * a learner each open the nav. Each sees exactly one Dashboard entry, and the
 * top level each role sees is written to the test report as evidence.
 *
 * The literal "eight top-level items" of TC-1 no longer holds, and should not:
 * later decided work moved Payments to shillinq and added Confidential notes,
 * App settings and a returned Insight group (recorded in tasks.md Task 8). So
 * this spec asserts the invariant that survived (one Dashboard per role, no
 * empty nav) and records the actual top level rather than pinning a count
 * that other changes are entitled to move.
 *
 * TC-2's direct URLs: the three old role dashboards still render for admin.
 *
 * @e2e openspec/specs/navigation/spec.md#requirement-the-dashboard-entry-resolves-by-role-instead-of-three-separate-rows
 */
import type { Page } from '@playwright/test'

import { expect, test } from './fixtures.ts'
import { LiveFixtures, signInAs } from './live-fixtures.ts'

const APP = '/index.php/apps/learniq'

/**
 * The top-level entries of the main nav (not the footer), by visible text.
 *
 * @param page A page with the app open.
 * @return The labels, in order.
 */
async function topLevel(page: Page): Promise<string[]> {
	const nav = page.locator('[data-testid="cn-nav"]')
	await expect(nav).toBeVisible({ timeout: 60_000 })
	await expect(
		nav.locator('> ul > li, .app-navigation__list > li').first(),
	).toBeVisible({
		timeout: 30_000,
	})
	const labels = await nav.evaluate((el) => {
		const list =
			el.querySelector('.app-navigation__list') ?? el.querySelector('ul')
		if (!list) return []
		return [...list.children]
			.map((li) => {
				const link = li.querySelector(
					'.app-navigation-entry__name, .app-navigation-entry-link',
				)
				return (link?.textContent ?? '').trim()
			})
			.filter(Boolean)
	})
	return labels
}

test.describe('menu per role', () => {
	test.describe.configure({ mode: 'serial', timeout: 360_000 })

	const fx = new LiveFixtures()

	test.afterAll(async () => {
		await fx.teardown()
	})

	test('admin: one Dashboard, and the old role dashboards still render', async ({
		loggedInPage: page,
	}) => {
		await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' })
		const items = await topLevel(page)
		test.info().annotations.push({
			type: 'admin top level',
			description: items.join(' | '),
		})
		expect(items.filter((i) => i === 'Dashboard')).toHaveLength(1)

		for (const path of [
			'/dashboards/admin',
			'/dashboards/teaching',
			'/dashboards/my-learning',
		]) {
			const res = await page.goto(`${APP}${path}`, {
				waitUntil: 'domcontentloaded',
			})
			expect(res?.status(), `${path}`).toBe(200)
			await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible({
				timeout: 60_000,
			})
			await expect(page.locator('.cn-page-renderer__empty')).toHaveCount(0)
		}
	})

	for (const role of [
		{ name: 'teacher', groups: ['instructors'] },
		{ name: 'learner', groups: ['learners'] },
	]) {
		test(`${role.name}: one Dashboard entry, and a nav that is not empty`, async ({
			browser,
		}) => {
			const user = await fx.user(role.name, role.groups)
			const page = await signInAs(browser, user)
			try {
				await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' })
				const items = await topLevel(page)
				test.info().annotations.push({
					type: `${role.name} top level`,
					description: items.join(' | '),
				})
				expect(items.length).toBeGreaterThan(1)
				expect(items.filter((i) => i === 'Dashboard')).toHaveLength(1)
			} finally {
				await page.context().close()
			}
		})
	}
})
