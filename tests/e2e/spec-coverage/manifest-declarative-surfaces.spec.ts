/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Gate-19 e2e coverage — the "the frontend is declarative, with exactly these
 * named custom views, and no PHP CRUD controller" scenarios.
 *
 * Six specs make the same three-part claim about their own feature area:
 *
 *   1. index/detail screens are DECLARATIVE `src/manifest.json` pages;
 *   2. exactly one named set of custom Vue views exists for that area;
 *   3. no PHP CRUD controller is added for the area's schemas.
 *
 * ── WHY EACH PART IS ASSERTED THE WAY IT IS ──
 *
 * Part 1 and part 2 are properties of the manifest, and the strongest honest
 * browser evidence for them is BOTH halves together: the manifest is read here
 * and asserted directly, AND the named custom view is driven in a real browser
 * so "declared" cannot drift from "renders". A manifest assertion alone would
 * pass over a component that 500s; a navigation alone would pass over a screen
 * that was quietly converted from declarative to custom.
 *
 * Part 3 — "no PHP CRUD controller" — is an ABSENCE claim, so it gets a
 * positive control: `assertRouteAnswers()` proves the probe can see a route
 * that does exist before `assertNoCrudController()` concludes anything from a
 * 404. Every probe prints its status code.
 *
 * ⚠️ These scenarios were previously "covered" by `@e2e` markers written INSIDE
 * the spec files as HTML comments (`<!-- @e2e tests/e2e/spec-coverage/
 * pupil-dossier.spec.ts -->`). Gate-19 reads `@e2e` tags out of the TEST suite,
 * never out of the spec, so those markers proved nothing and the gate correctly
 * kept reporting the scenarios as uncovered.
 */
import { test, expect } from '../fixtures'
import manifest from '../../../src/manifest.json'

// `import type { Page } from '@playwright/test'` trips
// `n/no-unpublished-import` because Playwright is a devDependency; every other
// spec in this repo spells the type inline for the same reason.
type Page = import('@playwright/test').Page

type ManifestPage = {
	id?: string
	route?: string
	type?: string
	component?: string
	config?: { schema?: string }
}

const PAGES: ManifestPage[] = (manifest as { pages?: ManifestPage[] }).pages ?? []

/**
 * ⚠️ Resolved at runtime, never hardcoded — see
 * tests/e2e/visual/pages.visual.spec.ts for the measurement. `generateUrl`
 * emits `/index.php` only on an instance without pretty urls (CI's `php -S`);
 * on Apache the base is `/apps/scholiq` and a hardcoded prefix silently lands
 * on the app's DEFAULT route.
 */
let appBase: string | null = null

/**
 * The router base this instance actually uses.
 *
 * @param page An authenticated page.
 * @return The app base path without a trailing slash.
 */
async function resolveAppBase(page: Page): Promise<string> {
	if (appBase) return appBase
	await page.goto('/index.php/apps/scholiq/')
	const base = await page.evaluate(
		() => (window as unknown as { OC: { generateUrl: (_p: string) => string } })
			.OC.generateUrl('/apps/scholiq'),
	)
	expect(base, 'OC.generateUrl did not resolve the scholiq app base').toBeTruthy()
	appBase = base.replace(/\/+$/, '')
	return appBase
}

/**
 * Every manifest page whose route starts with one of `prefixes`.
 *
 * @param prefixes Route prefixes that delimit the feature area.
 * @return The matching manifest page entries.
 */
function pagesUnder(prefixes: string[]): ManifestPage[] {
	return PAGES.filter((p) => typeof p.route === 'string'
		&& prefixes.some((x) => (p.route as string).startsWith(x)))
}

/**
 * Assert the area's screens are declarative apart from an exact set of custom
 * views.
 *
 * @param prefixes       Route prefixes that delimit the feature area.
 * @param expectedCustom The `[route, component]` pairs that MAY be custom.
 */
function assertDeclarativeExcept(
	prefixes: string[],
	expectedCustom: Array<[string, string]>,
): void {
	const area = pagesUnder(prefixes)
	expect(area.length, `no manifest pages found under ${prefixes.join(', ')}`)
		.toBeGreaterThan(expectedCustom.length)

	const custom = area
		.filter((p) => p.type === 'custom')
		.map((p) => [p.route as string, p.component as string] as [string, string])
		.sort((a, b) => a[0].localeCompare(b[0]))
	expect(custom).toEqual([...expectedCustom].sort((a, b) => a[0].localeCompare(b[0])))

	// Every other page in the area is a declarative renderer type AND declares
	// no component of its own — "declarative" means the manifest renderer draws
	// it, not that somebody wrote `type: "custom"` somewhere else.
	for (const p of area.filter((x) => x.type !== 'custom')) {
		expect(['index', 'detail', 'dashboard', 'logs'], `${p.route} is not a declarative page type`)
			.toContain(p.type)
		expect(p.component, `${p.route} is declarative but names a component`).toBeFalsy()
	}
}

/**
 * POSITIVE CONTROL for the absence probe: a route that DOES exist answers.
 *
 * Without this, a 404 from `assertNoCrudController` is indistinguishable from
 * a probe that could never have reached anything.
 *
 * @param page An authenticated page.
 * @return void
 */
async function assertRouteAnswers(page: Page): Promise<void> {
	const resp = await page.request.get('/index.php/apps/scholiq/api/health', {
		headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
	})
	const type = resp.headers()['content-type'] ?? ''
	expect(
		`${resp.status()} ${type}`,
		'positive control: a route that DOES exist must answer as a controller',
	).toMatch(/^200 application\/json/)
}

/**
 * Assert scholiq exposes no PHP CRUD endpoint for a schema.
 *
 * ⚠️ **A 404 IS NOT THE SIGNAL HERE, AND ASSERTING ONE WOULD BE WRONG.**
 * `appinfo/routes.php` declares a `catchAll` that serves the SPA shell for
 * `/{path}`, so EVERY path under the app answers **200 text/html** whether a
 * controller exists or not. Measured:
 *
 *   /apps/scholiq/api/health           -> 200 application/json   (real controller)
 *   /apps/scholiq/api/conference-round -> 200 text/html          (SPA catch-all)
 *   /apps/scholiq/api/zzz-not-a-thing  -> 200 text/html          (SPA catch-all)
 *
 * The discriminator is therefore the CONTENT TYPE, not the status. An earlier
 * revision of this helper asserted `404` and failed all six cases — an absence
 * probe that could never have produced the answer it was looking for, which is
 * exactly the failure mode `assertRouteAnswers` exists to catch.
 *
 * ⚠️ **CONCURRENT ON PURPOSE, AND THE SEQUENTIAL VERSION DID NOT FIT.**
 * Every one of these probes hits the catch-all, which renders the FULL SPA
 * shell — so each is a page-sized response, not a cheap 404. The
 * parent-conferences case probes 4 slugs x 2 spellings = 8 of them, and awaiting
 * them one at a time blew the 40 s per-test budget on CI (run 31519400527:
 * `Test timeout of 40000ms exceeded` at `apiRequestContext.get`, while the same
 * test passed locally at 1 worker). Firing them together costs one round of
 * latency instead of eight and **changes no assertion** — every path is still
 * probed and every content type is still checked.
 *
 * ⚠️ Do NOT "fix" a recurrence by dropping the plural spelling or by raising
 * `timeout`. The plural is half the coverage, and the per-test timeout is sized
 * against the slowest PASSING test in the suite (see
 * tests/e2e/playwright.config.ts).
 *
 * @param page  An authenticated page.
 * @param slugs The schema slugs, e.g. `['conference-round', 'conference-slot']`.
 */
async function assertNoCrudController(page: Page, slugs: string[]): Promise<void> {
	const paths = slugs.flatMap((slug) => [
		`/index.php/apps/scholiq/api/${slug}`,
		`/index.php/apps/scholiq/api/${slug}s`,
	])
	const results = await Promise.all(paths.map(async (path) => {
		const resp = await page.request.get(path, {
			headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
		})
		return `${path} -> ${resp.status()} ${resp.headers()['content-type'] ?? ''}`
	}))
	for (const line of results) {
		expect(line, 'a scholiq CRUD controller answers for this schema').toMatch(/text\/html/)
	}
}

test.describe('declarative frontends with named custom views', () => {

	/*
	 * ⚠️ **SPLIT IN TWO ON PURPOSE — DO NOT RECOMBINE.**
	 * parent-conferences is the only area declaring TWO custom views, so as one
	 * test it drove two full SPA loads plus the eight catch-all probes and became
	 * the slowest test in the suite by 7.6 s, sitting ON the 40 s per-test cap:
	 * measured at **39.1 s in the run that PASSED and 42.8 s in the one that
	 * failed — the same commit, `916c5ed`** (runs 31526866579 vs 31526872644).
	 * A test with 0.9 s of headroom fails on runner contention alone.
	 *
	 * Both halves are kept: each view is still navigated to in a real browser and
	 * still asserted to render, the manifest claim is still asserted in full in
	 * BOTH tests, and the absence probe still runs with its positive control.
	 * Nothing is skipped, loosened or re-timed — the same work is spread across
	 * two per-test budgets instead of one. Both carry the same `@e2e` anchor, so
	 * gate-19 coverage of the scenario is unchanged.
	 *
	 * ⚠️ Do NOT "speed this up" with `waitUntil: 'domcontentloaded'`. Measured on
	 * a clean rig: the test went from 23.3 s passing to **25.1–26.3 s FAILING** —
	 * the shell has not bootstrapped the router at DOMContentLoaded, so the view
	 * never mounts. It is slower AND wrong. (`networkidle` is the other trap: it
	 * hides the race it appears to fix.)
	 */

	// @e2e openspec/specs/parent-conferences/spec.md#booking-and-coordinator-resolution-use-the-two-named-custom-views-only
	test('parent-conferences: BookConferenceSlotsView is one of exactly two custom pages, and it renders', async ({ loggedInPage: page }) => {
		assertDeclarativeExcept(['/conferences'], [
			['/conferences/book', 'BookConferenceSlotsView'],
			['/conferences/schedule-board', 'ConferenceScheduleBoard'],
		])
		const base = await resolveAppBase(page)

		await page.goto(`${base}/conferences/book`)
		await expect(page.locator('.book-conference-slots')).toBeVisible({ timeout: 20_000 })
	})

	// @e2e openspec/specs/parent-conferences/spec.md#booking-and-coordinator-resolution-use-the-two-named-custom-views-only
	test('parent-conferences: ConferenceScheduleBoard renders and no PHP CRUD controller answers', async ({ loggedInPage: page }) => {
		assertDeclarativeExcept(['/conferences'], [
			['/conferences/book', 'BookConferenceSlotsView'],
			['/conferences/schedule-board', 'ConferenceScheduleBoard'],
		])
		const base = await resolveAppBase(page)

		await page.goto(`${base}/conferences/schedule-board`)
		await expect(page.locator('.conference-schedule-board')).toBeVisible({ timeout: 20_000 })

		await assertRouteAnswers(page)
		await assertNoCrudController(page, ['conference-round', 'conference-slot', 'conference-signup', 'conference-report'])
	})

	// @e2e openspec/specs/timetabling/spec.md#render-the-timetabling-surface-declaratively-with-named-views
	test('timetabling: TimetableConflictQueue is the only custom page', async ({ loggedInPage: page }) => {
		assertDeclarativeExcept(['/timetable', '/rooms', '/exam-accommodations'], [
			['/timetable-conflict-queue', 'TimetableConflictQueue'],
		])
		const base = await resolveAppBase(page)

		await page.goto(`${base}/timetable-conflict-queue`)
		await expect(page.locator('.timetable-conflict-queue')).toBeVisible({ timeout: 20_000 })
		await expect(page.locator('.timetable-conflict-queue__title')).toHaveText(/Timetable conflicts/i)

		await assertRouteAnswers(page)
		await assertNoCrudController(page, ['room', 'timetable-conflict', 'exam-accommodation'])
	})

	// @e2e openspec/specs/exam-board/spec.md#pages-are-manifest-declared-with-one-shared-dossier-view-exception
	test('exam-board: ExamCaseDossierView is the only custom page, shared by both case types', async ({ loggedInPage: page }) => {
		assertDeclarativeExcept(['/exam-board'], [
			['/exam-board/exemptions/:id', 'ExamCaseDossierView'],
			['/exam-board/fraud-cases/:id', 'ExamCaseDossierView'],
		])
		const base = await resolveAppBase(page)

		await page.goto(`${base}/exam-board/exemptions`)
		await expect(page.locator('#scholiq-app')).not.toBeEmpty({ timeout: 20_000 })
		await expect(page.locator('.exam-case-dossier')).toHaveCount(0)

		await assertRouteAnswers(page)
		await assertNoCrudController(page, ['exemption-case', 'fraud-case'])
	})

	// @e2e openspec/specs/pupil-dossier/spec.md#pages-are-manifest-declared-with-one-shared-timeline-view-exception
	test('pupil-dossier: PupilDossierTimelineView is the only custom page', async ({ loggedInPage: page }) => {
		assertDeclarativeExcept(['/pupil-dossier'], [
			['/pupil-dossier/timeline', 'PupilDossierTimelineView'],
		])
		const base = await resolveAppBase(page)

		await page.goto(`${base}/pupil-dossier/timeline`)
		await expect(page.locator('.pupil-dossier-timeline')).toBeVisible({ timeout: 20_000 })

		await assertRouteAnswers(page)
		await assertNoCrudController(page, ['dossier-note', 'behaviour-incident', 'wellbeing-check-in'])
	})

	// @e2e openspec/specs/report-card/spec.md#pages-and-custom-views-are-manifest-declared
	test('report-card: RapportvergaderingReviewView is the only custom page', async ({ loggedInPage: page }) => {
		assertDeclarativeExcept(['/report-cards', '/report-periods'], [
			['/report-periods/:id/review', 'RapportvergaderingReviewView'],
		])
		const base = await resolveAppBase(page)

		await page.goto(`${base}/report-periods`)
		await expect(page.locator('#scholiq-app')).not.toBeEmpty({ timeout: 20_000 })

		await assertRouteAnswers(page)
		await assertNoCrudController(page, ['report-period', 'report-card'])
	})

	// @e2e openspec/specs/bpv/spec.md#pages-are-manifest-declared-with-one-signing-exception
	test('bpv: the signing view is the only custom page', async ({ loggedInPage: page }) => {
		assertDeclarativeExcept(['/bpv'], [
			['/bpv/praktijkovereenkomsten/:pokId/sign', 'CnSignatureCapture'],
		])
		const base = await resolveAppBase(page)

		await page.goto(`${base}/bpv/placements`)
		await expect(page.locator('#scholiq-app')).not.toBeEmpty({ timeout: 20_000 })

		await assertRouteAnswers(page)
		await assertNoCrudController(page, ['bpv-placement', 'praktijkopleider', 'praktijkovereenkomst', 'werkproces-assessment', 'bpv-visit-report'])
	})
})
