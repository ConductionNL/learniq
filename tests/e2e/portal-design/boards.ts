// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * What every board check reads: a page of a portal, its texts, the order of
 * its blocks, the theme it renders in, an axe scan and a screenshot.
 *
 * Every reading is taken from the rendered page, never from the data behind
 * it, because the question is what a visitor sees. Theme readings are soft
 * (reported, not failing) when the portal runs on the fallback theme: the
 * designed token sets ship in a thematiq release the instance may not have.
 *
 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md
 */

import type { Browser, Page, TestInfo } from '@playwright/test'

import AxeBuilder from '@axe-core/playwright'
import { expect, request, test } from '@playwright/test'
import * as fs from 'node:fs'
import * as path from 'node:path'
import { baseUrl } from '../base-url.ts'
import {
	SITE_TOKEN_KEY,
	siteUrl,
	waitForAccountPage,
} from '../helpers/portal-fixture.ts'

/** The designed theme of each portal, and what it must render as. */
export interface DesignedTheme {
	/** The thematiq token set the portal wants. */
	theme: string
	/** A word every heading font-family must contain. */
	headingFont: string
	/** A word the body font-family must contain. */
	bodyFont: string
	/** The primary colour, as the browser computes it. */
	primary: string
	/** The button radius in pixels. */
	buttonRadius: number
}

export const SHOTS = path.join(__dirname, 'shots')

/**
 * Open a page of a portal by its route and wait until it rendered.
 *
 * @param {Page} page The page.
 * @param {string} portal The portal slug.
 * @param {string} route The route, for example `/` or `/zoeken`.
 * @return {Promise<void>}
 */
export async function openSitePage(
	page: Page,
	portal: string,
	route: string,
): Promise<void> {
	const url =
		route === '/'
			? siteUrl(portal)
			: `${siteUrl(portal)}&route=${encodeURIComponent(route)}`
	await page.goto(url)
	await page
		.locator('main, #site-main, [data-testid="widget-grid"]')
		.first()
		.waitFor({ timeout: 30_000 })
	await expect
		.poll(
			async () =>
				(
					await page
						.locator('main')
						.first()
						.innerText()
						.catch(() => '')
				).trim().length,
			{ timeout: 20_000 },
		)
		.toBeGreaterThan(0)
}

/**
 * Every text must be visible on the page, in any element.
 *
 * @param {Page} page The page.
 * @param {string[]} texts The texts of the board.
 * @return {Promise<void>}
 */
export async function expectTexts(page: Page, texts: string[]): Promise<void> {
	for (const text of texts) {
		await expect(
			page.getByText(text, { exact: false }).first(),
			`"${text}" is on the page`,
		).toBeVisible({ timeout: 15_000 })
	}
}

/**
 * The widget keys of the page's grid, in the order they render.
 *
 * @param {Page} page The page.
 * @return {Promise<string[]>} The keys.
 */
export async function widgetOrder(page: Page): Promise<string[]> {
	return await page.evaluate(() =>
		Array.from(document.querySelectorAll('[data-widget-key]')).map(
			(el) => el.getAttribute('data-widget-key') ?? '',
		),
	)
}

/**
 * The expected keys appear in this order (other keys may sit between them).
 *
 * @param {Page} page The page.
 * @param {string[]} expected The board's block order.
 * @return {Promise<void>}
 */
export async function expectWidgetOrder(
	page: Page,
	expected: string[],
): Promise<void> {
	const found = await widgetOrder(page)
	let at = 0
	for (const key of found) {
		if (key === expected[at]) {
			at++
		}
	}
	expect(
		at,
		`blocks in order ${expected.join(' > ')}; rendered ${found.join(' > ')}`,
	).toBe(expected.length)
}

/**
 * The theme the portal says it uses, from the public site content.
 *
 * @param {string} portal The portal slug.
 * @return {Promise<string>} The theme id, or '' when unknown.
 */
export async function portalTheme(portal: string): Promise<string> {
	const api = await request.newContext({ baseURL: baseUrl() })
	const res = await api.get(
		`/apps/portaliq/api/content/site?portal=${encodeURIComponent(portal)}`,
	)
	const body = res.ok() ? await res.json().catch(() => ({})) : {}
	await api.dispose()
	return String(body?.portal?.theme ?? body?.theme ?? '')
}

/**
 * Read the rendered theme and hold it against the design. Soft when the
 * portal runs on a fallback theme, so the run says so instead of failing on
 * a thematiq release that is not installed.
 *
 * @param {Page} page The page.
 * @param {string} portal The portal slug.
 * @param {DesignedTheme} design The designed theme.
 * @param {TestInfo} info The test info, for the annotation.
 * @return {Promise<void>}
 */
export async function expectTheme(
	page: Page,
	portal: string,
	design: DesignedTheme,
	info: TestInfo,
): Promise<void> {
	const theme = await portalTheme(portal)
	const reading = await page.evaluate(() => {
		const css = (el: Element | null, prop: string) =>
			el ? getComputedStyle(el).getPropertyValue(prop) : ''
		const heading = document.querySelector('main h1, main h2')
		const button = document.querySelector(
			'main .utrecht-button--primary-action, main button, main a.utrecht-button',
		)
		return {
			headingFont: css(heading, 'font-family'),
			bodyFont: css(document.body, 'font-family'),
			primary: getComputedStyle(document.documentElement)
				.getPropertyValue('--nldesign-color-primary')
				.trim(),
			buttonRadius: parseFloat(css(button, 'border-top-left-radius') || 'NaN'),
		}
	})
	info.annotations.push({
		type: 'theme',
		description: `${theme || 'unknown'}: ${JSON.stringify(reading)}`,
	})
	const check = theme === design.theme ? expect : expect.soft
	if (theme !== design.theme) {
		info.annotations.push({
			type: 'theme-fallback',
			description: `portal ${portal} runs on "${theme}", not the designed "${design.theme}"`,
		})
	}

	check(theme, 'the portal runs on its designed theme').toBe(design.theme)
	check(reading.headingFont.toLowerCase(), 'heading font').toContain(
		design.headingFont.toLowerCase(),
	)
	check(reading.bodyFont.toLowerCase(), 'body font').toContain(
		design.bodyFont.toLowerCase(),
	)
	check(reading.primary.toLowerCase(), 'primary colour token').toBe(
		design.primary.toLowerCase(),
	)
	check(reading.buttonRadius, 'button radius').toBe(design.buttonRadius)
}

/**
 * The page has no serious or critical axe finding.
 *
 * @param {Page} page The page.
 * @return {Promise<void>}
 */
export async function expectNoSeriousAxeFinding(page: Page): Promise<void> {
	const result = await new AxeBuilder({ page })
		.withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
		.analyze()
	const serious = result.violations.filter(
		(v) => v.impact === 'serious' || v.impact === 'critical',
	)
	expect(
		serious.map((v) => `${v.id}: ${v.nodes.length}`),
		'serious axe findings',
	).toEqual([])
}

/**
 * The page is no wider than the viewport (no horizontal scroll on a phone).
 *
 * @param {Page} page The page.
 * @return {Promise<void>}
 */
export async function expectNoHorizontalScroll(page: Page): Promise<void> {
	const overflow = await page.evaluate(
		() =>
			document.documentElement.scrollWidth
			- document.documentElement.clientWidth,
	)
	expect(overflow, 'horizontal overflow in pixels').toBeLessThanOrEqual(1)
}

/**
 * A full-page screenshot next to the board it stands for.
 *
 * @param {Page} page The page.
 * @param {string} portal The design id (= portal slug).
 * @param {string} board The board name, for example `Home`.
 * @param {TestInfo} info The test info (its project names the viewport).
 * @return {Promise<void>}
 */
export async function boardShot(
	page: Page,
	portal: string,
	board: string,
	info: TestInfo,
): Promise<void> {
	const dir = path.join(SHOTS, portal)
	fs.mkdirSync(dir, { recursive: true })
	await page.screenshot({
		path: path.join(dir, `${board}-${info.project.name}.png`),
		fullPage: true,
	})
}

/**
 * Sign in with the `nextcloud` mode as a story account, or skip with the reason.
 *
 * The spin-up sets one password on the story accounts
 * (`occ user:resetpassword --password-from-env`) and hands it in as
 * `PORTAL_DESIGN_PASSWORD`. The account must have a portal account with its
 * learniq claims; the spec grants one when the instance has none.
 *
 * @param {Browser} browser The browser.
 * @param {string} portal The portal slug.
 * @param {string} user The Nextcloud user id.
 * @param {object} viewport The viewport of the project.
 * @return {Promise<Page>} The signed-in page.
 */
export async function signInAs(
	browser: Browser,
	portal: string,
	user: string,
	viewport: { width: number; height: number },
): Promise<Page> {
	const pass = process.env.PORTAL_DESIGN_PASSWORD ?? ''
	test.skip(
		pass === '',
		'PORTAL_DESIGN_PASSWORD is not set: the story accounts have random passwords until the spin-up sets one',
	)
	const page = await (
		await browser.newContext({ locale: 'nl-NL', viewport })
	).newPage()
	await page.goto(`${siteUrl(portal)}&route=${encodeURIComponent('/mijn')}`)
	await page.getByTestId('site-account-signin').waitFor({ timeout: 20_000 })
	await page
		.getByRole('link', { name: 'Inloggen met uw account', exact: false })
		.first()
		.click()
	await page.locator('input[name="user"]').waitFor({ timeout: 20_000 })
	await page.locator('input[name="user"]').fill(user)
	await page.locator('input[name="password"]').fill(pass)
	await page.locator('button[type="submit"]').click()
	await page.waitForURL(/\/apps\/portaliq\/(site|portal)/, { timeout: 30_000 })
	await waitForAccountPage(page)
	await expect
		.poll(
			async () =>
				await page.evaluate(
					(key) => sessionStorage.getItem(key),
					SITE_TOKEN_KEY,
				),
			{ timeout: 20_000 },
		)
		.toBeTruthy()
	return page
}

/**
 * Make sure a story account can sign in to the portal: a portal account with its learniq claim.
 *
 * Writes nothing when the account exists. The rows it writes are named in
 * the run's annotations, so a shared instance can be cleaned by hand.
 *
 * @param {string} user The Nextcloud user id.
 * @param {string} audience The learniq audience.
 * @param {Record<string, string>} claims The learniq claims.
 * @param {string} displayName The name.
 * @return {Promise<void>}
 */
export async function ensurePortalAccount(
	user: string,
	audience: string,
	claims: Record<string, string>,
	displayName: string,
): Promise<void> {
	const admin = await request.newContext({
		baseURL: baseUrl(),
		httpCredentials: {
			username: process.env.NC_ADMIN_USER ?? 'admin',
			password: process.env.NC_ADMIN_PASS ?? 'admin',
		},
		extraHTTPHeaders: { 'OCS-APIRequest': 'true' },
	})
	const list = await admin.get(
		`/apps/openregister/api/objects/portaliq/portalAccount?subjectRef=${encodeURIComponent(user)}&_limit=50`,
	)
	const rows = list.ok() ? ((await list.json()).results ?? []) : []
	if (
		rows.some(
			(row: Record<string, unknown>) =>
				row.subjectRef === user && row.audience === audience,
		)
	) {
		await admin.dispose()
		return
	}
	const organisation =
		process.env.PORTAL_DESIGN_ORGANISATION ?? 'default-organisation'
	const res = await admin.post(
		'/apps/openregister/api/objects/portaliq/portalAccount',
		{
			data: {
				audience,
				subjectRef: user,
				organisation,
				email: `${user}@example.org`,
				verifiedEmail: true,
				displayName,
				status: 'active',
				claims: { learniq: claims },
			},
		},
	)
	expect(res.status(), await res.text()).toBeLessThan(300)
	await admin.dispose()
}
