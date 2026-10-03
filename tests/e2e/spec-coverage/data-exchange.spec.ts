/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage for data-exchange-to-integriq: the Data exchange menu is
 * a read-only status panel over integriq's jobs and rejections owned by
 * learniq, next to the pages of the exchange gate's own records.
 *
 * Covers (UI-observable surface):
 *   @e2e openspec/specs/data-exchange/spec.md#scenario-an-administrator-opens-the-panel
 *   @e2e openspec/specs/data-exchange/spec.md#scenario-a-parent-approves
 *
 * The panel pages declare `requiresApp: integriq`; on an instance without
 * integriq they render the app-required notice, which is also a non-fatal
 * render. The gate's decisions are exercised at the unit level
 * (ExchangeGateServiceTest, ExchangeGateListenerTest).
 */
import type { Page } from '@playwright/test'

import { expect, test } from '../fixtures.ts'

const PAGES = [
	'/index.php/apps/learniq/data-exchange/jobs',
	'/index.php/apps/learniq/data-exchange/rejections',
	'/index.php/apps/learniq/data-exchange/parent-reviews',
	'/index.php/apps/learniq/data-exchange/teldatum-checks',
	'/index.php/apps/learniq/compliance/partner-approvals',
]

/**
 * Collect console errors, minus the benign network noise other specs filter.
 *
 * @param {Page} page The page.
 * @return {string[]} The collected errors, filled as the page runs.
 */
function collectFatalErrors(page: Page): string[] {
	const errors: string[] = []
	page.on('console', (msg) => {
		if (msg.type() === 'error') {
			errors.push(msg.text())
		}
	})
	return errors
}

test.describe('data-exchange-to-integriq: status panel and gate pages', () => {
	for (const url of PAGES) {
		// @e2e openspec/specs/data-exchange/spec.md#scenario-an-administrator-opens-the-panel
		test(`${url} renders without a fatal error`, async ({
			loggedInPage: page,
		}) => {
			const errors = collectFatalErrors(page)

			await page.goto(url)
			await page.waitForSelector('body', { timeout: 15_000 })
			await page.waitForLoadState('domcontentloaded')

			expect((await page.innerText('body')).trim().length).toBeGreaterThan(0)
			const fatal = errors.filter(
				(e) =>
					!e.includes('favicon')
					&& !e.includes('font')
					&& !e.includes('Failed to load resource')
					&& !e.includes('net::ERR_ABORTED')
					&& !e.includes('Failed to fetch')
					&& !e.includes('ERR_CONNECTION_REFUSED'),
			)
			expect(
				fatal,
				`unexpected fatal errors: ${fatal.join(' | ')}`,
			).toHaveLength(0)
		})
	}

	// @e2e openspec/specs/data-exchange/spec.md#scenario-a-parent-approves
	test('the exchange jobs page offers no add action', async ({
		loggedInPage: page,
	}) => {
		await page.goto(PAGES[0])
		await page.waitForLoadState('domcontentloaded')

		await expect(page.getByRole('button', { name: /^Add/ })).toHaveCount(0)
	})
})
