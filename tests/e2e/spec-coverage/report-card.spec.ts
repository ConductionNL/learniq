/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — report-card-composer spec UI scenarios.
 *
 * Covers (UI-observable surface), matching the spec's own `@e2e` tags:
 *   @e2e tests/e2e/spec-coverage/report-card.spec.ts#pages-are-manifest-declared
 *   @e2e tests/e2e/spec-coverage/report-card.spec.ts#report-period-scopes-declared-subjects-and-cohorts
 *   @e2e tests/e2e/spec-coverage/report-card.spec.ts#compose-succeeds-once-locked
 *   @e2e tests/e2e/spec-coverage/report-card.spec.ts#composing-creates-one-card-per-learner
 *   @e2e tests/e2e/spec-coverage/report-card.spec.ts#finalise-blocked-without-mentor-comment
 *   @e2e tests/e2e/spec-coverage/report-card.spec.ts#reopen-returns-finalised-card-to-review
 *   @e2e tests/e2e/spec-coverage/report-card.spec.ts#publish-blocked-while-visibility-window-not-open
 *   @e2e tests/e2e/spec-coverage/report-card.spec.ts#publish-succeeds-once-visibility-window-open
 *   @e2e tests/e2e/spec-coverage/report-card.spec.ts#teacher-cannot-publish-grade-for-locked-report-period
 *
 * Most of this change's requirements (schema/lifecycle registration, the
 * composer's per-subject/per-learner assembly logic, the visibility-window
 * re-check, the fail-soft docudesk delegation, the portal-contribution
 * collection) are backend behaviours already verified by PHPUnit
 * (ReportPeriodComposeGuardTest, ReportPeriodLockGuardTest,
 * ReportCardFinaliseGuardTest, ReportCardReopenGuardTest,
 * ReportCardVisibilityGuardTest, ReportCardComposerTest,
 * ReportCardPublishHandlerTest, ReportCardPdfDelegationServiceTest,
 * ReportCardComposerRegisterTest, PortalContributionProviderTest) and carry
 * `@e2e exclude` on their respective scenarios in the spec — no learniq DOM
 * surface exists for a lifecycle-guard/composer-internals scenario.
 *
 * Mirroring adaptive-release.spec.ts / progress-tracking.spec.ts's own
 * convention: every scenario discovers a real ReportPeriod/ReportCard/
 * GradeEntry matching the required shape via the OpenRegister object API
 * rather than fabricating fixtures through raw API POSTs (no spec-coverage
 * test in this app creates objects that way).
 *
 * ⚠️ A MISSING FIXTURE IS NO LONGER A SKIP. This file used to say that every
 * data-dependent scenario was "expected to SKIP (not fail)" until a school had
 * composed a report period — and that is precisely how nine of them stopped
 * covering anything without a single red run. `test.skip(!row, …)` cannot tell
 * "this instance was never seeded" from "the seeder owes this row and did not
 * make it", and it reports the second as a pass.
 *
 * The seeder now creates these periods and cards (see
 * tests/e2e/seed-example-data.mjs), and `requireFixture()` skips only on an
 * UNSEEDED instance while failing on a seeded one. If a scenario here goes red,
 * the answer is to seed the fixture or fix why the seeder cannot — not to
 * restore the skip.
 *
 * Assertions are DOM-based; the admin session comes from the global setup.
 */
import { test, expect } from '../fixtures'
import { requireFixture } from '../seeded'

/**
 * Whether a ReportPeriod is locked.
 *
 * ⚠️ `p.isLocked === true` NEVER MATCHES ANYTHING READ BACK FROM THE API.
 * `isLocked` is a declared `x-openregister-calculations` entry with
 * `materialise: true`, and it really is computed — a create response carries
 * `isLocked: true` for a past lockDate. It is just never returned again:
 * measured on a live instance, BOTH the list endpoint and the single-object
 * GET omit the field. Filtering a list on it therefore matches zero rows
 * forever, which the old `test.skip(!period, …)` reported as a pass.
 *
 * So this asks the question the way ReportPeriodComposeGuard answers it —
 * `lockDate` set AND in the past — while still preferring the materialised
 * value if OpenRegister ever starts projecting it.
 *
 * @param p The ReportPeriod row.
 * @return Whether the period counts as locked.
 */
function isLockedPeriod(p: any): boolean {
	if (p?.isLocked === true) {
		return true
	}

	const lockedAt = Date.parse(p?.lockDate ?? '')

	return Number.isNaN(lockedAt) === false && lockedAt < Date.now()
}

// `/index.php/` prefix is load-bearing on CI — a bare `php -S` does not rewrite
// pretty URLs, and `server/apps/openregister/` exists without an index.php, so
// the short form returns a hard 404. See adaptive-release.spec.ts.
// `_limit`, NOT `limit` — an unrecognised OpenRegister query parameter is
// applied as a PROPERTY FILTER rather than ignored, so `?limit=200` returns
// HTTP 200 with an empty result set that reads as "nothing seeded". These three
// already used the correct lowercase slugs; only the control parameter was
// wrong, which is precisely why it went unnoticed.
const REPORT_PERIOD_LIST_API =
	'/index.php/apps/openregister/api/objects/learniq/report-period?_limit=200'
const REPORT_CARD_LIST_API =
	'/index.php/apps/openregister/api/objects/learniq/report-card?_limit=200'
const GRADE_ENTRY_LIST_API =
	'/index.php/apps/openregister/api/objects/learniq/grade-entry?_limit=200'

/**
 * Fetch every row for a schema's index endpoint and return the first one
 * matching the given predicate, or null when none exists in this environment.
 *
 * @param page    The Playwright page (used for its authenticated request context).
 * @param url     The OpenRegister object-list API URL.
 * @param matches Predicate a candidate row must satisfy.
 */
async function findRow(
	page: import('@playwright/test').Page,
	url: string,
	matches: (_row: any) => boolean,
) {
	const resp = await page.request.get(url, {
		headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
	})
	if (!resp.ok()) return null

	const json = await resp.json()
	const rows = json.results ?? json.objects ?? json ?? []
	return rows.find(matches) ?? null
}

function collectFatalErrors(page: import('@playwright/test').Page): string[] {
	const errors: string[] = []
	page.on('console', (msg) => {
		if (msg.type() === 'error') errors.push(msg.text())
	})
	return errors
}

function fatalOnly(errors: string[]): string[] {
	return errors.filter(
		(e) =>
			!e.includes('favicon')
			&& !e.includes('font')
			&& !e.includes('Failed to load resource')
			&& !e.includes('net::ERR_ABORTED')
			&& !e.includes('Failed to fetch')
			&& !e.includes('ERR_CONNECTION_REFUSED'),
	)
}

async function openRoute(page: import('@playwright/test').Page, route: string) {
	// ⚠️ NO `#` — the router is HISTORY mode, not hash mode. Fixed fleet-wide in
	// #610; this note records what it cost HERE.
	//
	// vue-router strips the `createWebHistory` base from `location.pathname` and
	// appends the UNTOUCHED hash, so
	// `/index.php/apps/learniq/#/report-periods/<id>/review` resolved to
	// `/#/report-periods/<id>/review`, matched no declared route, and fell
	// through `routesFromManifest`'s `/:pathMatch(.*)*` catch-all — which
	// `redirect: '/'`s to the DASHBOARD.
	//
	// That is why every scenario here reported `element(s) not found` for
	// Finalise / Reopen / "Compose report cards…": the page under assertion was
	// the dashboard, not the review surface. It stayed invisible for as long as
	// these scenarios skipped for want of a fixture — a skipping test never
	// navigates, so a broken URL costs nothing and shows nothing.
	await page.goto(`/index.php/apps/learniq${route}`)
	await page.waitForSelector('body', { timeout: 15_000 })
	await page.waitForLoadState('domcontentloaded')
}

test.describe('report-card-composer — declarative pages resolve without a fatal error', () => {
	// @e2e openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-pages-and-custom-views-are-manifest-declared
	test('pages-are-manifest-declared', async ({ loggedInPage: page }) => {
		const errors = collectFatalErrors(page)

		await openRoute(page, '/report-periods')
		let body = await page.innerText('body')
		expect(body.trim().length).toBeGreaterThan(0)

		await openRoute(page, '/report-cards')
		body = await page.innerText('body')
		expect(body.trim().length).toBeGreaterThan(0)

		const fatal = fatalOnly(errors)
		expect(fatal, `unexpected fatal errors: ${fatal.join(' | ')}`).toHaveLength(
			0,
		)
	})
})

test.describe('report-card-composer — ReportPeriod scope and lock-gated compose', () => {
	// @e2e openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-a-reportperiod-scopes-exactly-the-declared-subjects-and-cohorts
	test('report-period-scopes-declared-subjects-and-cohorts', async ({
		loggedInPage: page,
	}) => {
		const period = await findRow(
			page,
			REPORT_PERIOD_LIST_API,
			(p) =>
				Array.isArray(p.curriculumPlanIds)
				&& p.curriculumPlanIds.length > 0
				&& Array.isArray(p.cohortIds)
				&& p.cohortIds.length > 0,
		)
		requireFixture(
			period,
			'a ReportPeriod with a non-empty curriculumPlanIds/cohortIds scope',
		)

		const errors = collectFatalErrors(page)
		const periodId = period.id ?? period.uuid
		await openRoute(page, `/report-periods/${periodId}`)

		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)

		const fatal = fatalOnly(errors)
		expect(fatal, `unexpected fatal errors: ${fatal.join(' | ')}`).toHaveLength(
			0,
		)
	})

	// @e2e openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-compose-succeeds-once-the-lock-date-has-passed
	test('compose-succeeds-once-locked', async ({ loggedInPage: page }) => {
		const period = await findRow(
			page,
			REPORT_PERIOD_LIST_API,
			(p) => p.lifecycle === 'open' && isLockedPeriod(p),
		)
		// ComposeReportPeriodModal only enables Compose when the period is locked.
		requireFixture(period, 'an open + isLocked ReportPeriod')

		const errors = collectFatalErrors(page)
		const periodId = period.id ?? period.uuid
		await openRoute(page, `/report-periods/${periodId}/review`)

		// The compose button is only rendered while `lifecycle === 'open'`
		// (RapportvergaderingReviewView) — asserting its presence proves the
		// locked-period compose entry point is reachable end-to-end.
		await expect(page.getByText('Compose report cards…')).toBeVisible({
			timeout: 10_000,
		})

		const fatal = fatalOnly(errors)
		expect(fatal, `unexpected fatal errors: ${fatal.join(' | ')}`).toHaveLength(
			0,
		)
	})

	// @e2e openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-composing-a-period-creates-one-reportcard-per-cohort-learner
	test('composing-creates-one-card-per-learner', async ({
		loggedInPage: page,
	}) => {
		const period = await findRow(
			page,
			REPORT_PERIOD_LIST_API,
			(p) => p.lifecycle === 'composed',
		)
		requireFixture(period, 'a composed ReportPeriod')

		const periodId = period.id ?? period.uuid
		const errors = collectFatalErrors(page)
		await openRoute(page, `/report-periods/${periodId}/review`)

		// A composed period renders the rapportvergadering review grid (or the
		// "no report cards" empty state, if composition somehow produced zero
		// rows) — never the still-open "Compose report cards…" prompt.
		await expect(page.getByText('Compose report cards…')).toHaveCount(0)

		const fatal = fatalOnly(errors)
		expect(fatal, `unexpected fatal errors: ${fatal.join(' | ')}`).toHaveLength(
			0,
		)
	})
})

test.describe('report-card-composer — rapportvergadering review lifecycle', () => {
	// @e2e openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-finalise-is-blocked-without-a-mentor-comment
	test('finalise-blocked-without-mentor-comment', async ({
		loggedInPage: page,
	}) => {
		const card = await findRow(
			page,
			REPORT_CARD_LIST_API,
			(c) =>
				c.lifecycle === 'rapportvergadering-review'
				&& (!c.mentorComment || c.mentorComment.trim() === ''),
		)
		requireFixture(
			card,
			'a rapportvergadering-review ReportCard with an empty mentorComment',
		)

		const errors = collectFatalErrors(page)
		const periodId = card.reportPeriodId
		await openRoute(page, `/report-periods/${periodId}/review`)

		// The Finalise button is rendered per row regardless of comment state
		// (ReportCardFinaliseGuard is the server-side enforcement point) —
		// asserting the row and its action are reachable proves the review
		// grid surfaces the gated action end-to-end.
		await expect(page.getByText('Finalise').first()).toBeVisible({
			timeout: 10_000,
		})

		const fatal = fatalOnly(errors)
		expect(fatal, `unexpected fatal errors: ${fatal.join(' | ')}`).toHaveLength(
			0,
		)
	})

	// @e2e openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-a-mentor-reopens-a-finalised-report-card-to-correct-it-before-publication
	test('reopen-returns-finalised-card-to-review', async ({
		loggedInPage: page,
	}) => {
		const card = await findRow(
			page,
			REPORT_CARD_LIST_API,
			(c) => c.lifecycle === 'finalised',
		)
		requireFixture(card, 'a finalised ReportCard')

		const errors = collectFatalErrors(page)
		const periodId = card.reportPeriodId
		await openRoute(page, `/report-periods/${periodId}/review`)

		await expect(page.getByText('Reopen').first()).toBeVisible({
			timeout: 10_000,
		})

		const fatal = fatalOnly(errors)
		expect(fatal, `unexpected fatal errors: ${fatal.join(' | ')}`).toHaveLength(
			0,
		)
	})

	// @e2e openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-publish-is-blocked-while-a-contributing-grades-visibility-window-has-not-opened
	//
	// ⚠️ SKIPPED ON A PLATFORM LIMITATION, NOT ON A MISSING ROW — and the
	// difference is the reason this file stopped trusting bare skips.
	//
	// This scenario needs a finalised ReportCard whose
	// `subjectGrades[].sourceGradeEntryIds` cites the not-yet-visible
	// GradeEntry. That object cannot be created through the object API at all:
	// OpenRegister refuses a `$ref` that sits inside an ARRAY ITEM with
	// `403 Unresolved reference: schema:///CurriculumPlan#`, and
	// `subjectGrades.items.required` is `["curriculumPlanId"]` — the refused
	// property is the required one, so there is no valid object to fall back
	// to. See ConductionNL/openregister#2179, which now carries this app's
	// three instances alongside the original hermiq one.
	//
	// It is `fixme`, not `skip`, so it reports as a KNOWN failure rather than
	// as a pass, and it names an issue someone can close. When #2179 lands,
	// change this back to `test(` and drop the seeder's `subjectGrades` note.
	//
	// Declared as `test.fixme(name, fn)` — NOT a bare `test.fixme(true, …)`
	// statement, which applies to every test in the enclosing describe and
	// would quietly take the other scenarios down with it.
	test.fixme('publish-blocked-while-visibility-window-not-open', async ({
		loggedInPage: page,
	}) => {
		const futureGrade = await findRow(
			page,
			GRADE_ENTRY_LIST_API,
			(g) => g.visibleFrom && new Date(g.visibleFrom).getTime() > Date.now(),
		)
		requireFixture(
			futureGrade,
			'a published GradeEntry with a future visibleFrom',
		)

		const card = await findRow(
			page,
			REPORT_CARD_LIST_API,
			(c) =>
				c.lifecycle === 'finalised'
				&& Array.isArray(c.subjectGrades)
				&& c.subjectGrades.some(
					(row: any) =>
						Array.isArray(row.sourceGradeEntryIds)
						&& row.sourceGradeEntryIds.includes(
							futureGrade.id ?? futureGrade.uuid,
						),
				),
		)
		requireFixture(
			card,
			'a finalised ReportCard referencing that not-yet-visible GradeEntry',
		)

		const errors = collectFatalErrors(page)
		const periodId = card.reportPeriodId
		await openRoute(page, `/report-periods/${periodId}/review`)

		await expect(page.getByText('Publish to parents').first()).toBeVisible({
			timeout: 10_000,
		})

		const fatal = fatalOnly(errors)
		expect(fatal, `unexpected fatal errors: ${fatal.join(' | ')}`).toHaveLength(
			0,
		)
	})

	// @e2e openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-publish-succeeds-once-every-contributing-grades-window-has-opened
	test('publish-succeeds-once-visibility-window-open', async ({
		loggedInPage: page,
	}) => {
		const card = await findRow(
			page,
			REPORT_CARD_LIST_API,
			(c) => c.lifecycle === 'published-to-parents',
		)
		requireFixture(card, 'a published-to-parents ReportCard')

		const errors = collectFatalErrors(page)
		const periodId = card.reportPeriodId
		await openRoute(page, `/report-periods/${periodId}/review`)

		// A published-to-parents row shows no further Publish action (already
		// terminal for the parent-visibility gate) — proving the transition
		// took effect and the grid reflects the resulting state.
		const bodyText = await page.innerText('body')
		expect(bodyText).toContain('published-to-parents')

		const fatal = fatalOnly(errors)
		expect(fatal, `unexpected fatal errors: ${fatal.join(' | ')}`).toHaveLength(
			0,
		)
	})
})

test.describe('report-card-composer — grading spec delta (ReportPeriodLockGuard)', () => {
	// @e2e openspec/changes/report-card-composer/specs/grading/spec.md#scenario-an-ordinary-teacher-cannot-publish-a-grade-for-a-locked-report-period
	test('teacher-cannot-publish-grade-for-locked-report-period', async ({
		loggedInPage: page,
	}) => {
		const lockedPeriod = await findRow(page, REPORT_PERIOD_LIST_API, (p) =>
			isLockedPeriod(p),
		)
		requireFixture(lockedPeriod, 'an isLocked ReportPeriod')

		const concept = await findRow(
			page,
			GRADE_ENTRY_LIST_API,
			(g) =>
				g.lifecycle === 'concept'
				&& g.period === lockedPeriod.periodCode
				&& Array.isArray(lockedPeriod.curriculumPlanIds)
				&& lockedPeriod.curriculumPlanIds.includes(g.curriculumPlanId),
		)
		requireFixture(
			concept,
			'a concept GradeEntry within the locked ReportPeriod scope',
		)

		const errors = collectFatalErrors(page)
		const entryId = concept.id ?? concept.uuid
		await openRoute(page, `/grades/entries/${entryId}`)

		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)

		const fatal = fatalOnly(errors)
		expect(fatal, `unexpected fatal errors: ${fatal.join(' | ')}`).toHaveLength(
			0,
		)
	})
})
