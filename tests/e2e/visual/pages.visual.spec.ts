/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Gate-26 visual-coverage — one executed browser test per page component that
 * had no visual proof.
 *
 * ── WHY THE COMPONENT NAME IN EACH CASE IS AN ASSERTION OPERAND, NOT PROSE ──
 *
 * Gate-26 decides coverage with a PLAIN SUBSTRING SEARCH over the raw text of
 * every file under `tests/e2e/visual/**` (`is_covered()` -> `needle in
 * visual_corpus`). Comments are never stripped. So a file containing nothing
 * but a sentence mentioning a view satisfies the gate for that view, with no
 * baseline, no navigation and no assertion — reproduced on doriath as
 * 10 -> 9 -> 10 findings and filed as ConductionNL/.github#358.
 *
 * That makes "the name appears in this file" worthless as evidence by itself.
 * Every `component` string below is therefore fed to `expect()`:
 * `manifestComponentFor(route)` reads `src/manifest.json` and the case asserts
 * the manifest really maps that route to that component. A typo, a rename or a
 * deleted manifest entry fails the test instead of silently continuing to pay
 * the gate. The token is load-bearing.
 *
 * ── WHAT EACH CASE ASSERTS, AND WHY "THE PAGE RENDERED" IS NOT ENOUGH ──
 *
 * Nextcloud paints its header, sidebar and app shell on EVERY url, including
 * ones the router cannot match. `expect(body).not.toBeEmpty()` therefore passes
 * on a screen that rendered nothing of this app at all — which is exactly how
 * 43 navigations elsewhere in this suite pass while pointing at a location no
 * route matches. Each case here asserts, in order:
 *
 *   1. the manifest maps the route to the component (above);
 *   2. the component's OWN root element is visible — no other screen in the app
 *      and nothing in the Nextcloud chrome renders that class;
 *   3. a piece of copy only this component renders.
 *
 * Steps 2 and 3 are what a screenshot baseline is supposed to buy and what a
 * baseline of the nav sidebar would not. A shot of the wrong screen satisfies
 * gate-26 identically; a missing root class here does not.
 *
 * ── WHY NO `toHaveScreenshot()` ──
 *
 * This directory IS executed by the config CI runs (`tests/e2e/
 * playwright.config.ts` has `testDir: __dirname` and ignores only
 * `docs-screenshots.spec.ts`), which is the opposite of doriath, where
 * `testIgnore: ['**\/visual\/**']` made baselines dropped here a green that
 * never ran. Because these DO run on CI, a PNG generated on a developer box
 * would be compared against CI's renderer on every run — different fonts,
 * different device scale — and the first CI run would go red for a reason that
 * has nothing to do with the app. A red gate is not a stronger gate. The DOM
 * assertions below are environment-stable and check the same property a
 * baseline is meant to check: that this route really renders THIS component.
 */
import { test as authed, expect } from '../fixtures'
import { createObject, firstObjectId } from '../or-api'
import manifest from '../../../src/manifest.json'

// `import type { Page } from '@playwright/test'` trips
// `n/no-unpublished-import` because Playwright is a devDependency; every other
// spec in this repo spells the type inline for the same reason.
type Page = import('@playwright/test').Page

/**
 * ⚠️ THE APP BASE IS RESOLVED AT RUNTIME, NOT HARDCODED.
 *
 * `src/main.js` builds the router with
 * `createWebHistory(generateUrl('/apps/scholiq'))`, and `generateUrl` emits the
 * `/index.php` prefix ONLY when the instance does not serve pretty urls. CI runs
 * a bare `php -S`, which has no rewrite, so there the base IS
 * `/index.php/apps/scholiq` — the form hardcoded throughout the rest of this
 * suite. On an Apache instance with mod_rewrite the base is `/apps/scholiq`, and
 * a `/index.php/apps/scholiq/compliance` url then has a pathname the router
 * cannot strip its base from: no route matches, the router falls back to the
 * default route, and the browser shows the ADMIN DASHBOARD.
 *
 * Measured here: with the hardcoded prefix, all 12 cases below rendered
 * `heading "Administrator · Dashboard"` instead of the page under test. Nothing
 * about that is visible to an assertion of the form
 * `expect(bodyText.length).toBeGreaterThan(0)`.
 *
 * Asking the page for its own base makes the spec correct on both.
 */
const ENTRY_URL = '/index.php/apps/scholiq/'
let appBase: string | null = null

/**
 * The router base this instance actually uses, resolved once per worker.
 *
 * @param page An authenticated page (navigated to the app in the process).
 * @return The app base path, without a trailing slash.
 */
async function resolveAppBase(page: Page): Promise<string> {
	if (appBase) return appBase
	await page.goto(ENTRY_URL)
	const base = await page.evaluate(
		() => (window as unknown as { OC: { generateUrl: (_p: string) => string } })
			.OC.generateUrl('/apps/scholiq'),
	)
	expect(base, 'OC.generateUrl did not resolve the scholiq app base').toBeTruthy()
	appBase = base.replace(/\/+$/, '')
	return appBase
}

/**
 * The component `src/manifest.json` declares for a manifest route.
 *
 * @param route The manifest `route` value, e.g. `/compliance/regulations/:slug`.
 * @return The declared component name, or null when no entry declares that route.
 */
function manifestComponentFor(route: string): string | null {
	const pages = (manifest as { pages?: Array<{ route?: string, component?: string }> }).pages ?? []
	const entry = pages.find((p) => p.route === route)
	return entry?.component ?? null
}

/**
 * Console errors raised while the page loaded, minus the network/font noise
 * every spec in this repo filters.
 *
 * @param page The page to listen on.
 * @return A live array the caller reads after navigating.
 */
function collectFatalErrors(page: Page): string[] {
	const errors: string[] = []
	page.on('console', (msg) => {
		if (msg.type() === 'error') errors.push(msg.text())
	})
	return errors
}

/**
 * Assert the collected console errors contain nothing fatal.
 *
 * @param errors The array returned by collectFatalErrors.
 */
function assertNoFatalErrors(errors: string[]): void {
	const fatal = errors.filter(
		(e) =>
			!e.includes('favicon')
			&& !e.includes('font')
			&& !e.includes('Failed to load resource')
			&& !e.includes('net::ERR_ABORTED')
			&& !e.includes('Failed to fetch')
			&& !e.includes('ERR_CONNECTION_REFUSED'),
	)
	expect(fatal, `unexpected fatal errors: ${fatal.join(' | ')}`).toHaveLength(0)
}

/**
 * Navigate to an app route and wait for the component root to appear.
 *
 * ⚠️ NO `#`. `src/main.js` builds the router with
 * `createWebHistory(generateUrl('/apps/scholiq'))`, so a `#/x` url resolves to
 * a location no route matches and renders the Nextcloud chrome with an empty
 * app body.
 *
 * @param page The page to drive.
 * @param path The app-relative path, e.g. `/compliance`.
 * @param root A selector only the expected component renders.
 * @return void
 */
async function openPage(page: Page, path: string, root: string): Promise<void> {
	await goTo(page, path)
	await expect(page.locator(root)).toBeVisible({ timeout: 20_000 })
}

/**
 * Navigate to an app route using the instance's real router base.
 *
 * @param page The page to drive.
 * @param path The app-relative path.
 * @return void
 */
async function goTo(page: Page, path: string): Promise<void> {
	const base = await resolveAppBase(page)
	await page.goto(`${base}${path}`)
}

authed.describe('gate-26 — every page component renders its own screen', () => {

	authed('Compliance dashboard', async ({ loggedInPage: page }) => {
		expect(manifestComponentFor('/compliance')).toBe('ScholiqCompliance')
		const errors = collectFatalErrors(page)
		await goTo(page, '/compliance')
		// The header action is rendered by this view only.
		await expect(page.getByRole('button', { name: /View in LaunchPad/i })).toBeVisible({ timeout: 20_000 })
		await expect(page.getByText(/Compliance/).first()).toBeVisible()
		assertNoFatalErrors(errors)
	})

	authed('Learner home dashboard', async ({ loggedInPage: page }) => {
		expect(manifestComponentFor('/learner')).toBe('ScholiqLearnerHome')
		const errors = collectFatalErrors(page)
		await goTo(page, '/learner')
		// The single widget this view mounts.
		await expect(page.getByText(/My mandatory training/i).first()).toBeVisible({ timeout: 20_000 })
		assertNoFatalErrors(errors)
	})

	authed('AI processing disclosure', async ({ loggedInPage: page }) => {
		expect(manifestComponentFor('/ai-processing-disclosure')).toBe('ScholiqAiProcessingDisclosure')
		const errors = collectFatalErrors(page)
		await openPage(page, '/ai-processing-disclosure', '.ai-processing-disclosure')
		await expect(
			page.getByText(/A school-verifiable record of where this school's AI-assisted processing runs/i),
		).toBeVisible({ timeout: 20_000 })
		assertNoFatalErrors(errors)
	})

	authed('Course package import', async ({ loggedInPage: page }) => {
		expect(manifestComponentFor('/course-packages/import')).toBe('CoursePackageImportView')
		const errors = collectFatalErrors(page)
		await openPage(page, '/course-packages/import', '.course-package-import')
		await expect(page.locator('.course-package-import__heading')).toHaveText(/Import course package/i)
		await expect(
			page.getByText(/IMS Common Cartridge 1\.3/i),
		).toBeVisible()
		assertNoFatalErrors(errors)
	})

	authed('Timetable conflict queue', async ({ loggedInPage: page }) => {
		expect(manifestComponentFor('/timetable-conflict-queue')).toBe('TimetableConflictQueue')
		const errors = collectFatalErrors(page)
		await openPage(page, '/timetable-conflict-queue', '.timetable-conflict-queue')
		await expect(page.locator('.timetable-conflict-queue__title')).toHaveText(/Timetable conflicts/i)
		await expect(page.locator('.timetable-conflict-queue__subtitle')).toContainText(/Nothing here is auto-resolved/i)
		assertNoFatalErrors(errors)
	})

	authed('Book a parent-teacher conversation', async ({ loggedInPage: page }) => {
		expect(manifestComponentFor('/conferences/book')).toBe('BookConferenceSlotsView')
		const errors = collectFatalErrors(page)
		await openPage(page, '/conferences/book', '.book-conference-slots')
		await expect(
			page.getByRole('heading', { name: /Book a parent-teacher conversation/i }),
		).toBeVisible()
		assertNoFatalErrors(errors)
	})

	authed('Conference schedule board', async ({ loggedInPage: page }) => {
		expect(manifestComponentFor('/conferences/schedule-board')).toBe('ConferenceScheduleBoard')
		const errors = collectFatalErrors(page)
		await openPage(page, '/conferences/schedule-board', '.conference-schedule-board')
		await expect(
			page.getByRole('heading', { name: /Conference schedule board/i }),
		).toBeVisible()
		await expect(page.locator('.conference-schedule-board__toolbar')).toBeVisible()
		assertNoFatalErrors(errors)
	})

	authed('Regulation detail, resolved by business slug', async ({ loggedInPage: page }) => {
		expect(manifestComponentFor('/compliance/regulations/:slug')).toBe('RegulationDetailPage')
		const errors = collectFatalErrors(page)
		// `AVG` is seeded by tests/e2e/seed-example-data.mjs. Resolving a REAL
		// slug is what distinguishes "the route mounted the component" from
		// "the component resolved this record" — the not-found branch renders a
		// different subtree entirely.
		await openPage(page, '/compliance/regulations/AVG', '.regulation-detail')
		await expect(page.getByText(/Regulation not found/i)).toHaveCount(0)
		assertNoFatalErrors(errors)
	})

	authed('Item author', async ({ loggedInPage: page }) => {
		expect(manifestComponentFor('/assessments/items/:id/author')).toBe('ItemAuthorView')
		const errors = collectFatalErrors(page)
		const itemId = await ensureItem(page)
		await openPage(page, `/assessments/items/${itemId}/author`, '.item-author')
		await expect(page.locator('.item-author__heading')).toHaveText(/Edit item/i)
		await expect(page.locator('label[for="item-title"]')).toHaveText(/Item title/i)
		assertNoFatalErrors(errors)
	})

	authed('Item analysis', async ({ loggedInPage: page }) => {
		expect(manifestComponentFor('/assessments/items/:itemId/analysis')).toBe('ItemAnalysisView')
		const errors = collectFatalErrors(page)
		const itemId = await ensureItem(page)
		await openPage(page, `/assessments/items/${itemId}/analysis`, '.item-analysis')
		await expect(page.locator('.item-analysis__sub-heading').first())
			.toHaveText(/Statistics per assessment/i)
		assertNoFatalErrors(errors)
	})

	authed('Grade impact', async ({ loggedInPage: page }) => {
		expect(manifestComponentFor('/grades/entries/:id/impact')).toBe('GradeImpactDetail')
		const errors = collectFatalErrors(page)
		const entryId = await ensureGradeEntry(page)
		await openPage(page, `/grades/entries/${entryId}/impact`, '.grade-impact')
		await expect(page.locator('.grade-impact__header h2')).toHaveText(/Grade impact/i)
		await expect(page.getByRole('heading', { name: /This grade/i })).toBeVisible()
		assertNoFatalErrors(errors)
	})

	authed('Exam-board case dossier', async ({ loggedInPage: page }) => {
		expect(manifestComponentFor('/exam-board/exemptions/:id')).toBe('ExamCaseDossierView')
		expect(manifestComponentFor('/exam-board/fraud-cases/:id')).toBe('ExamCaseDossierView')
		const errors = collectFatalErrors(page)
		const caseId = await ensureExemptionCase(page)
		await openPage(page, `/exam-board/exemptions/${caseId}`, '.exam-case-dossier')
		await expect(page.locator('.exam-case-dossier__header h2')).toHaveText(/Exemption request/i)
		await expect(page.getByText(/Case not found/i)).toHaveCount(0)
		assertNoFatalErrors(errors)
	})
})

/**
 * The uuid of an assessment Item, created when the collection is empty.
 *
 * ⚠️ A null here is a FAILURE, not a skip. A fixture that could not be created
 * makes every assertion downstream vacuous, and a test that reports on its own
 * fixture rather than on the code is the shape that stays green for years.
 *
 * @param page An authenticated page.
 * @return The item uuid.
 */
async function ensureItem(page: Page): Promise<string> {
	const existing = await firstObjectId(page, 'item')
	if (existing) return existing
	const id = await createObject(page, 'item', {
		title: 'Gate-26 fixture item',
		interactionType: 'choice',
		stem: 'Which of these is a fixture?',
	})
	expect(id, 'could not create an `item` fixture — the schema may not have imported').toBeTruthy()
	return id as string
}

/**
 * The uuid of a GradeEntry, created when the collection is empty.
 *
 * @param page An authenticated page.
 * @return The grade-entry uuid.
 */
async function ensureGradeEntry(page: Page): Promise<string> {
	const existing = await firstObjectId(page, 'grade-entry')
	if (existing) return existing
	const id = await createObject(page, 'grade-entry', {
		learnerId: '00000000-0000-0000-0000-0000000000e1',
		componentId: 'gate26-component',
		value: '7.5',
		period: '2026-P1',
	})
	expect(id, 'could not create a `grade-entry` fixture — the schema may not have imported').toBeTruthy()
	return id as string
}

/**
 * The uuid of an ExemptionCase, created when the collection is empty.
 *
 * @param page An authenticated page.
 * @return The exemption-case uuid.
 */
async function ensureExemptionCase(page: Page): Promise<string> {
	const existing = await firstObjectId(page, 'exemption-case')
	if (existing) return existing
	// Every required property of the ExemptionCase schema, in its declared
	// format. `submittedAt` is `format: date-time` — a bare `YYYY-MM-DD` is
	// rejected with a 400, which `createObject` reports as a null rather than a
	// throw, so the failure would otherwise surface as a confusing locator
	// timeout three lines later.
	const id = await createObject(page, 'exemption-case', {
		learnerId: '00000000-0000-0000-0000-0000000000e1',
		curriculumPlanId: '00000000-0000-0000-0000-0000000000c1',
		componentId: 'gate26-component',
		groundsKind: 'work-experience',
		groundsDescription: 'Gate-26 fixture exemption request.',
		submittedAt: new Date().toISOString(),
	})
	expect(id, 'could not create an `exemption-case` fixture — the schema may not have imported').toBeTruthy()
	return id as string
}
