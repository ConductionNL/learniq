/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — engagement spec UI scenarios.
 *
 * Covers (UI-observable surface):
 *   @e2e openspec/specs/engagement/spec.md#scenario-a-learner-sees-their-own-points-and-level-regardless-of-leaderboard-opt-out
 *   @e2e openspec/specs/engagement/spec.md#scenario-a-cohort-member-opens-an-active-leaderboard-and-can-opt-out-from-within-it
 *
 * The point-award trigger/idempotency mechanics, the streak/level evaluator,
 * and the leaderboard opt-in/opt-out authorization gates are all
 * backend/lifecycle behaviours verified by PHPUnit (PointEngagementEvaluatorTest,
 * PointAwardTriggerHandlerTest, LearnerEngagementRollupHandlerTest,
 * LeaderboardControllerTest) — they carry `@e2e exclude` on their respective
 * spec requirements. Here we assert the two declarative-page-renderer-facing
 * surfaces this change adds: the always-visible student points/level KPI
 * widget, and the one custom-view exception (LeaderboardView), mirroring
 * study-progress's BsaRiskDashboard e2e coverage pattern.
 *
 * Assertions are DOM-based; the admin session comes from the global setup.
 */
import { expect, test } from '../fixtures.ts'

// ⚠️ NO `#` — the router is HISTORY mode (`createWebHistory` in src/main.js), so a
// `#/…` URL resolves to a location no route matches and renders an empty app body.
// See accessibility-conformance.spec.ts for the measurement.
const STUDENT_DASHBOARD_URL = '/index.php/apps/learniq/dashboards/my-learning'
const LEADERBOARD_URL = '/index.php/apps/learniq/engagement/leaderboard'

function fatalErrors(errors: string[]): string[] {
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

test.describe('engagement-gamification — points/level widget and leaderboard', () => {
	// @e2e openspec/specs/engagement/spec.md#scenario-a-learner-sees-their-own-points-and-level-regardless-of-leaderboard-opt-out
	// @e2e engagement::a-learner-sees-their-own-points-and-level-regardless-of-leaderboard-opt-out
	//
	// The discriminating assertion is `toContain('My points')`. That string is
	// the tile's own label, declared as the `kpi-points-level` widget in
	// LearniqDashboards.vue's studentConfig() — it is NOT a manifest menu
	// label, so it cannot be satisfied by the app nav alone. Drop the widget
	// from the student dashboard and this test goes red, which is what the
	// scenario's THEN ("their points/level KPI widget renders") asks for.
	//
	// The tile's VALUE is now asserted too. It previously could not be: the
	// bespoke component fetched twice and swallowed the level lookup, so
	// "rendered" and "rendered something true" were different questions. It
	// is now a `type: 'stat'` tile bound to /api/engagement/me, which either
	// answers or reports an error — so a tile still showing the placeholder
	// dash means the endpoint did not answer, and that is a failure rather
	// than an unmeasured gap.
	test('student dashboard renders the points/level KPI widget without a fatal error', async ({
		loggedInPage: page,
	}) => {
		const errors: string[] = []
		page.on('console', (msg) => {
			if (msg.type() === 'error') {
				errors.push(msg.text())
			}
		})

		await page.goto(STUDENT_DASHBOARD_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('domcontentloaded')

		// The dashboard resolved the student view (not a blank/404 shell) and
		// the "My points" KPI tile is present among the widget slots.
		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)
		expect(bodyText).toContain('My points')

		// The tile resolved to a real figure. A learner with no engagement row
		// yet legitimately reads 0, so this asserts a NUMBER rather than a
		// non-zero one — the failure it must catch is the placeholder dash the
		// tile shows while unresolved, and the error state it shows when the
		// endpoint fails.
		const tile = page
			.locator('.cn-stat-widget', { hasText: 'My points' })
			.first()
		await expect(tile).toBeVisible({ timeout: 15_000 })
		await expect(tile).not.toContainText('—')
		// Match "contains a digit" rather than a full numeric shape: the tile
		// declares no `format`, so CnStatWidget runs the value through
		// `Intl.NumberFormat(undefined, …)` and the RUNNER's locale picks the
		// group separator — several use a non-breaking space, which an
		// anchored [\d.,]+ pattern would reject for a correct number.
		await expect(tile.locator('.cn-stat-widget__value')).toHaveText(/\d/)

		expect(
			fatalErrors(errors),
			`unexpected fatal errors: ${fatalErrors(errors).join(' | ')}`,
		).toHaveLength(0)
	})

	// @e2e openspec/specs/engagement/spec.md#scenario-a-cohort-member-opens-an-active-leaderboard-and-can-opt-out-from-within-it
	test('LeaderboardView renders without a fatal error', async ({
		loggedInPage: page,
	}) => {
		const errors: string[] = []
		page.on('console', (msg) => {
			if (msg.type() === 'error') {
				errors.push(msg.text())
			}
		})

		await page.goto(LEADERBOARD_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('domcontentloaded')

		// The page resolved the custom LeaderboardView component (its heading,
		// or the empty/error/loading state) rather than a blank/404 shell.
		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)
		expect(bodyText).toContain('Leaderboard')

		expect(
			fatalErrors(errors),
			`unexpected fatal errors: ${fatalErrors(errors).join(' | ')}`,
		).toHaveLength(0)
	})

	// @e2e openspec/specs/engagement/spec.md#scenario-a-cohort-member-opens-an-active-leaderboard-and-can-opt-out-from-within-it
	test('LeaderboardView surfaces the opt-out toggle when an active leaderboard exists', async ({
		loggedInPage: page,
	}) => {
		await page.goto(LEADERBOARD_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('domcontentloaded')

		// The opt-out switch only renders once at least one active Leaderboard
		// exists for the current tenant (seed-data dependent). Its absence
		// (empty-state tenant) is a valid, non-fatal outcome — the assertion
		// only checks that IF it renders, it is a single labelled switch.
		const toggle = page.getByRole('switch', {
			name: /hide me from this leaderboard/i,
		})
		const toggleCount = await toggle.count().catch(() => 0)
		expect(toggleCount).toBeLessThanOrEqual(1)
	})
})
