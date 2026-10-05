/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The simple structure, in a browser: what an administrator sees in the main
 * menu under three captions, and a page that left the menu one link away.
 *
 * The CI instance runs on the full structure (tests/e2e/ci-seed.sh sets it),
 * so this spec turns the setting to `simple` through the same endpoint the
 * admin section uses, and puts back what it found. The suite runs one worker,
 * serially, so no other spec sees the menu change under it.
 *
 * WHAT WOULD MAKE THIS PASS FOR THE WRONG REASON, and what stops it. A menu
 * that failed to build renders nothing, and "the nested entries are absent"
 * holds on an empty navigation. So the entries are asserted PRESENT and in
 * order first, and absence is only read after that.
 *
 * The signed-in user is the Nextcloud administrator, whose menu is the
 * teacher's menu plus Timetables and Compliance. The menus of the other roles
 * are held by tests/unit-js/structureProfile.test.mjs, which builds the menu
 * for every role with the library's own evaluator.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP_BASE = '/apps/learniq'
const SETTINGS_API = '/index.php/apps/learniq/api/settings'

/** The administrator's main menu in the simple structure, captions included, in order. */
const MAIN = [
	'cn-nav-caption-HomeCaption',
	'cn-nav-entry-Dashboard',
	'cn-nav-entry-GroupMyLearning',
	'cn-nav-entry-GroupsSimple',
	'cn-nav-caption-TeachingCaption',
	'cn-nav-entry-MyTimetableMenu',
	'cn-nav-entry-TimetablesMenu',
	'cn-nav-entry-GroupLearning',
	'cn-nav-entry-GradesSimple',
	'cn-nav-caption-LearnersCaption',
	'cn-nav-entry-LearnersSimple',
	'cn-nav-entry-AttendanceSimple',
	'cn-nav-entry-GroupProgress',
	'cn-nav-entry-CareSimple',
	'cn-nav-entry-GroupCompliance',
]

/** Entries the full structure nests under Learning and People. The simple one links to them. */
const NESTED = ['Courses', 'Assignments', 'RollCallMenu', 'Enrolments']

/**
 * The CSRF token for a write.
 *
 * @param api The request context.
 */
async function requestToken(api: APIRequestContext): Promise<string> {
	const answer = await api.get('/index.php/csrftoken')
	return (await answer.json()).token
}

/**
 * Store a structure and read back what the server kept.
 *
 * @param api The request context.
 * @param structure `simple` or `full`.
 */
async function store(api: APIRequestContext, structure: string): Promise<string> {
	const saved = await api.put(SETTINGS_API, {
		headers: {
			requesttoken: await requestToken(api),
			'Content-Type': 'application/json',
		},
		data: { menu_structure: structure },
	})
	expect(saved.status(), `PUT menu_structure=${structure}`).toBe(200)
	return String((await saved.json()).config.menu_structure)
}

/**
 * Close the first-run wizard when it is open: its modal takes every click.
 *
 * @param page The page.
 */
async function dismissSetupWizard(page: Page): Promise<void> {
	const modal = page.locator('[data-testid="cn-modal"]')
	if ((await modal.count()) === 0) {
		return
	}
	await modal.first().getByRole('button', { name: 'Close' }).click()
	await expect(modal).toHaveCount(0, { timeout: 15_000 })
}

test.describe('The simple structure', () => {
	test.setTimeout(300_000)

	let before = ''

	test.beforeAll(async ({ playwright, baseURL }, testInfo) => {
		const api = await playwright.request.newContext({
			baseURL,
			storageState: testInfo.project.use.storageState,
		})
		const current = await api.get(SETTINGS_API)
		expect(current.status(), 'GET /api/settings').toBe(200)
		before = String((await current.json()).menu_structure ?? '')
		expect(await store(api, 'simple')).toBe('simple')
		await api.dispose()
	})

	test.afterAll(async ({ playwright, baseURL }, testInfo) => {
		const api = await playwright.request.newContext({
			baseURL,
			storageState: testInfo.project.use.storageState,
		})
		// An instance that never set the key gets `simple` back, which is what
		// an unset key reads as.
		await store(api, before === 'full' ? 'full' : 'simple')
		await api.dispose()
	})

	// @e2e openspec/changes/simple-structure-profile/specs/navigation/spec.md#an-administrator-opens-learniq-on-the-simple-structure
	test('the menu shows the daily entries under three captions', async ({
		page,
	}) => {
		await page.goto(`${APP_BASE}/`, { waitUntil: 'domcontentloaded' })
		const nav = page.locator('[data-testid="cn-nav"]')
		await expect(nav).toBeVisible({ timeout: 60_000 })
		await dismissSetupWizard(page)

		for (const id of MAIN) {
			await expect(
				nav.getByTestId(id),
				`${id} must be in the menu`,
			).toBeVisible({ timeout: 30_000 })
		}

		// Order, read from the document: every caption and entry of the main
		// menu, in the order the navigation draws them.
		const drawn = await nav.evaluate((root, wanted) => {
			const ids = Array.from(root.querySelectorAll('[data-testid]')).map(
				(node) => node.getAttribute('data-testid') ?? '',
			)
			return ids.filter((id) => wanted.includes(id))
		}, MAIN)
		expect(drawn).toEqual(MAIN)

		// Only now is absence worth reading.
		for (const id of NESTED) {
			await expect(nav.getByTestId(`cn-nav-entry-${id}`)).toHaveCount(0)
		}
	})

	// @e2e openspec/changes/simple-structure-profile/specs/navigation/spec.md#a-list-that-left-the-menu-is-one-link-away
	test("today's register is one link away from Attendance, and a page still opens by address", async ({
		page,
	}) => {
		await page.goto(`${APP_BASE}/attendance/records`, {
			waitUntil: 'domcontentloaded',
		})
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible({
			timeout: 60_000,
		})
		await dismissSetupWizard(page)

		const name = /^(Today's register|Presentie van vandaag)$/i
		const link = page
			.getByRole('link', { name })
			.or(page.getByRole('button', { name }))
		await expect(link.first()).toBeVisible({ timeout: 60_000 })
		await link.first().click()
		await expect(page).toHaveURL(/\/attendance\/roll-call/, { timeout: 30_000 })

		// The deep link works without the menu entry.
		await page.goto(`${APP_BASE}/courses`, { waitUntil: 'domcontentloaded' })
		await expect(page).toHaveURL(/\/courses/)
	})
})
