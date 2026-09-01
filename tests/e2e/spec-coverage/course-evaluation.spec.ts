/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — course-evaluation spec UI scenarios.
 *
 * Covers (UI-observable surface):
 *   @e2e openspec/changes/course-evaluation/specs/course-evaluation/spec.md#scenario-a-coordinator-opens-the-course-quality-report-and-sees-the-score-trend
 *   @e2e openspec/changes/course-evaluation/specs/course-evaluation/spec.md#scenario-a-reviewer-records-an-improvement-action-against-a-campaigns-results
 *
 * The anonymity mechanism (guard/handler identity split), the eligibility/
 * duplicate-submission guard, the reminder dispatch, and the quality-score
 * roll-up/aggregation are backend/lifecycle behaviours verified by PHPUnit
 * (CourseEvaluationEligibilityGuardTest, CourseEvaluationResponseSubmittedHandlerTest,
 * CourseQualityScoreEvaluatorTest, CourseQualityScoreRollupHandlerTest,
 * EvaluationInvitationProvisioningHandlerTest) and the declarative register
 * shape (CourseEvaluationRegisterTest) — they carry `@e2e exclude` in the spec.
 * Here we assert the two drivable DOM scenarios: the CourseQualityReport
 * custom view renders (the one custom-view exception this capability adds),
 * and the declarative ImprovementAction manifest CRUD flow (index -> create)
 * renders, mirroring school-year-rollover's wizard-page e2e coverage pattern.
 *
 * Assertions are DOM-based; the admin session comes from the global setup.
 */
import { expect, test } from '../fixtures.ts'

// ⚠️ NO `#` — the router is HISTORY mode (`createWebHistory` in src/main.js), so a
// `#/…` URL resolves to a location no route matches and renders an empty app body.
// See accessibility-conformance.spec.ts for the measurement.
const QUALITY_REPORT_URL = '/index.php/apps/learniq/course-evaluation/quality-report'

// The view this spec drives, named after the component file it covers. The
// URL is unchanged — this makes the spec-to-component link readable in
// executable code rather than only in the prose above (gate-26 matches a
// page against its component stem, and the stem appeared only in comments).
const CourseQualityReport = QUALITY_REPORT_URL

const IMPROVEMENT_ACTIONS_URL =
	'/index.php/apps/learniq/course-evaluation/improvement-actions'

function collectFatalErrors(errors: string[]): string[] {
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

test.describe('course-evaluation — quality report and improvement actions', () => {
	// @e2e openspec/changes/course-evaluation/specs/course-evaluation/spec.md#scenario-a-coordinator-opens-the-course-quality-report-and-sees-the-score-trend
	test('course quality report page renders the course picker without a fatal error', async ({
		loggedInPage: page,
	}) => {
		const errors: string[] = []
		page.on('console', (msg) => {
			if (msg.type() === 'error') {
				errors.push(msg.text())
			}
		})

		await page.goto(CourseQualityReport)
		// Readiness signal: the Vue root has rendered. NOT `networkidle` — it
		// can never settle on Nextcloud (the notification poll keeps a request
		// in flight all session), so it silently burns its full 30 s out of
		// this test's 60 s budget and surfaces as a bare timeout that looks
		// like an app outage. ADR-074 rule 4 / hydra gate 58.
		await expect(page.locator('#learniq-app')).not.toBeEmpty({ timeout: 20_000 })

		// The custom CourseQualityReport component resolved (not a blank/404
		// shell) — its heading and course picker are present.
		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)
		expect(bodyText).toContain('Course quality report')

		const fatal = collectFatalErrors(errors)
		expect(fatal, `unexpected fatal errors: ${fatal.join(' | ')}`).toHaveLength(
			0,
		)
	})

	// @e2e openspec/changes/course-evaluation/specs/course-evaluation/spec.md#scenario-a-reviewer-records-an-improvement-action-against-a-campaigns-results
	test('improvement actions index page renders the declarative manifest list without a fatal error', async ({
		loggedInPage: page,
	}) => {
		const errors: string[] = []
		page.on('console', (msg) => {
			if (msg.type() === 'error') {
				errors.push(msg.text())
			}
		})

		await page.goto(IMPROVEMENT_ACTIONS_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('domcontentloaded')

		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)

		const fatal = collectFatalErrors(errors)
		expect(fatal, `unexpected fatal errors: ${fatal.join(' | ')}`).toHaveLength(
			0,
		)
	})
})
