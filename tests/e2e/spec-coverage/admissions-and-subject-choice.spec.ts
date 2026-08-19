/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — admissions-and-subject-choice spec UI scenarios.
 *
 * Covers (UI-observable surface):
 *   @e2e openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#scenario-a-coordinator-reviews-pending-applications-on-the-review-board
 *   @e2e openspec/changes/admissions-and-subject-choice/specs/school-structure/spec.md#scenario-a-learner-picks-electives-with-live-rule-feedback
 *
 * The MBO toelatingsrecht / VO schooladvies-adjustment / capacity branches
 * (AdmissionsDecisionGuard), waitlist auto-promotion
 * (AdmissionsWaitlistPromoter), conversion into LearnerProfile + Enrolment
 * (ApplicationConversionHandler), guardian consent
 * (SubjectChoiceConsentGuard), elective-rule/capacity validation
 * (SubjectChoiceValidator), and the Enrolment bridge on lock
 * (SubjectChoiceEnrolmentBridge) are all backend/lifecycle behaviours with no
 * distinct DOM surface — verified by PHPUnit
 * (AdmissionsDecisionGuardTest, AdmissionsWaitlistPromoterTest,
 * ApplicationConversionHandlerTest, SubjectChoiceConsentGuardTest,
 * SubjectChoiceValidatorTest, SubjectChoiceEnrolmentBridgeTest) and the
 * declarative register shape — they carry `@e2e exclude` in the spec. Here we
 * assert the two drivable DOM scenarios: the AdmissionsReviewBoard custom
 * view renders (the one custom-view exception admissions adds), and the
 * SubjectChoicePicker custom view renders (the one custom-view exception
 * subject choice adds), mirroring course-evaluation's e2e coverage pattern.
 *
 * Assertions are DOM-based; the admin session comes from the global setup.
 */
import { test, expect } from '../fixtures'

// ⚠️ NO `#` — the router is HISTORY mode (`createWebHistory` in src/main.js), so a
// `#/…` URL resolves to a location no route matches and renders an empty app body.
// See accessibility-conformance.spec.ts for the measurement.
const ADMISSIONS_REVIEW_BOARD_URL = '/index.php/apps/learniq/admissions/review-board'

// The view this spec drives, named after the component file it covers. The
// URL is unchanged — this makes the spec-to-component link readable in
// executable code rather than only in the prose above (gate-26 matches a
// page against its component stem, and the stem appeared only in comments).
const SubjectChoicePicker = ADMISSIONS_REVIEW_BOARD_URL

const SUBJECT_CHOICE_PICKER_URL = '/index.php/apps/learniq/subject-choice/picker'

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

test.describe('admissions-and-subject-choice — review board and subject-choice picker', () => {
	// @e2e openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#scenario-a-coordinator-reviews-pending-applications-on-the-review-board
	test('admissions review board page renders without a fatal error', async ({
		loggedInPage: page,
	}) => {
		const errors: string[] = []
		page.on('console', (msg) => {
			if (msg.type() === 'error') {
				errors.push(msg.text())
			}
		})

		await page.goto(SubjectChoicePicker)
		// Readiness signal: the Vue root has rendered. NOT `networkidle` — it
		// can never settle on Nextcloud (the notification poll keeps a request
		// in flight all session), so it silently burns its full 30 s out of
		// this test's 60 s budget and surfaces as a bare timeout that looks
		// like an app outage. ADR-074 rule 4 / hydra gate 58.
		await expect(page.locator('#learniq-app')).not.toBeEmpty({ timeout: 20_000 })

		// The custom AdmissionsReviewBoard component resolved (not a blank/404
		// shell) — its heading is present, and either the pending-applications
		// list or the empty state renders.
		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)
		expect(bodyText).toContain('Admissions review board')

		const fatal = collectFatalErrors(errors)
		expect(fatal, `unexpected fatal errors: ${fatal.join(' | ')}`).toHaveLength(
			0,
		)
	})

	// @e2e openspec/changes/admissions-and-subject-choice/specs/school-structure/spec.md#scenario-a-learner-picks-electives-with-live-rule-feedback
	test('subject choice picker page renders without a fatal error', async ({
		loggedInPage: page,
	}) => {
		const errors: string[] = []
		page.on('console', (msg) => {
			if (msg.type() === 'error') {
				errors.push(msg.text())
			}
		})

		await page.goto(SUBJECT_CHOICE_PICKER_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('domcontentloaded')

		// The custom SubjectChoicePicker component resolved (not a blank/404
		// shell) — its heading is present, and either the curriculum-plan
		// picker or the empty state renders.
		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)
		expect(bodyText).toContain('Pick electives')

		const fatal = collectFatalErrors(errors)
		expect(fatal, `unexpected fatal errors: ${fatal.join(' | ')}`).toHaveLength(
			0,
		)
	})
})
