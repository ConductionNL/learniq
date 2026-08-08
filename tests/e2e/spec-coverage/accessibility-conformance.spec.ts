/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — accessibility-conformance spec UI scenarios.
 *
 * Covers (UI-observable surface):
 *   @e2e openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-the-accessibility-statement-must-carry-the-dutch-government-model-s-mandatory-fields
 *   @e2e openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-known-limitations-must-be-evidence-backed-and-linked-from-the-published-statement
 *   @e2e openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-any-authenticated-user-must-be-able-to-report-an-accessibility-barrier
 *
 * The publish guard (AccessibilityStatementPublishGuard: no publish without
 * evaluation evidence, no fully-compliant status while a limitation is
 * open/mitigated) and the schema/lifecycle/RBAC shape are backend behaviour
 * verified by PHPUnit (AccessibilityStatementPublishGuardTest) — those
 * scenarios carry `@e2e exclude` in the spec.
 *
 * This file proves the DECLARATIVE pages (ScholiqAccessibilityStatement.vue,
 * the AccessibilityLimitation index, and the AccessibilityFeedbackCreate
 * no-id create-mode route) resolve and render without a fatal error, and —
 * where the register/seed state allows — that the mandatory-field labels,
 * the limitations table columns, and the feedback create form fields are
 * actually present.
 *
 * The limitation DETAIL page is the one place that creates its own fixture
 * (`../or-api`) rather than pointing at `00000000-…`: CnDetailPage's object
 * store `console.error`s `Error fetching <type>/<id>` on the 404 a bogus id
 * produces, so "navigate to a non-existent id" and "assert no console errors"
 * cannot both hold. A real row satisfies both AND raises the bar from "the
 * component mounted" to "the component rendered this record's values".
 */
import { test, expect } from '../fixtures'
import { createObject, seededTenantId } from '../or-api'

// ⚠️ NO `#` — the router is HISTORY mode, not hash mode.
//
// src/main.js builds the router with `createWebHistory(generateUrl('/apps/scholiq'))`.
// vue-router's history mode strips the base from `location.pathname` and then
// APPENDS the untouched hash, so `/index.php/apps/scholiq/#/accessibility`
// resolves to the location `/#/accessibility` — which matches no declared route.
// `<router-view>` renders nothing and the page shows only the Nextcloud chrome.
//
// Measured on CI run 30798535945: every spec that navigated with `#/` failed with
// `Received string: "Keyboard navigation help / Skip to app navigation / …"` —
// the NC shell with an empty app body — while index-pages.spec.ts and
// detail-pages.spec.ts, which use the plain path form, passed 206/206.
const STATEMENT_URL = '/index.php/apps/scholiq/accessibility'
const LIMITATIONS_INDEX_URL = '/index.php/apps/scholiq/accessibility/limitations'
const FEEDBACK_INDEX_URL = '/index.php/apps/scholiq/accessibility/feedback'
const FEEDBACK_CREATE_URL = '/index.php/apps/scholiq/accessibility/feedback/new'

/**
 * Collect console errors on a page, filtering out the same benign noise
 * every other spec-coverage spec in this repo filters (favicon/font/network
 * blips unrelated to app logic).
 */
function collectFatalErrors(page: import('@playwright/test').Page): string[] {
	const errors: string[] = []
	page.on('console', (msg) => {
		if (msg.type() === 'error') {
			errors.push(msg.text())
		}
	})
	return errors
}

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

test.describe('accessibility-conformance — the toegankelijkheidsverklaring statement page', () => {

	// @e2e openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-the-accessibility-statement-must-carry-the-dutch-government-model-s-mandatory-fields
	test('Accessibility statement page renders without a fatal error', async ({ loggedInPage: page }) => {
		const errors = collectFatalErrors(page)

		await page.goto(STATEMENT_URL)
		// Readiness signal: the Vue root has rendered. NOT `networkidle` — it
		// can never settle on Nextcloud (the notification poll keeps a request
		// in flight all session), so it silently burns its full 30 s out of
		// this test's 60 s budget and surfaces as a bare timeout that looks
		// like an app outage. ADR-074 rule 4 / hydra gate 58.
		await expect(page.locator('#scholiq-app')).not.toBeEmpty({ timeout: 20_000 })

		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)

		assertNoFatalErrors(errors)
	})

	// @e2e openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-the-accessibility-statement-must-carry-the-dutch-government-model-s-mandatory-fields
	test('a published statement shows channel identity, status, evaluation method/date, standard applied, feedback contact, and escalation route', async ({ loggedInPage: page }) => {
		await page.goto(STATEMENT_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		const bodyText = await page.innerText('body')
		// Soft: only meaningful once a published AccessibilityStatement is
		// seeded — the empty state (no statement published yet) is also a
		// valid render of this page and is proven not-a-fatal-error above.
		if (/Accessibility statement/i.test(bodyText) && !/No accessibility statement published yet/i.test(bodyText)) {
			await expect(page.getByText(/Channel/i).first()).toBeVisible()
			await expect(page.getByText(/Conformance status/i).first()).toBeVisible()
			await expect(page.getByText(/Evaluation method/i).first()).toBeVisible()
			await expect(page.getByText(/Evaluation date/i).first()).toBeVisible()
			await expect(page.getByText(/Standard applied/i).first()).toBeVisible()
			await expect(page.getByText(/Feedback contact/i).first()).toBeVisible()
			await expect(page.getByText(/Escalation route/i).first()).toBeVisible()
		}
	})

	// @e2e openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-any-authenticated-user-must-be-able-to-report-an-accessibility-barrier
	test('the "Report an accessibility problem" entry point is always present, regardless of whether a statement is published', async ({ loggedInPage: page }) => {
		await page.goto(STATEMENT_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		await expect(page.getByRole('button', { name: /Report an accessibility problem/i }).first()).toBeVisible()
	})
})

test.describe('accessibility-conformance — the known-limitations register', () => {

	// @e2e openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-known-limitations-must-be-evidence-backed-and-linked-from-the-published-statement
	test('Accessibility limitations index page renders without a fatal error', async ({ loggedInPage: page }) => {
		const errors = collectFatalErrors(page)

		await page.goto(LIMITATIONS_INDEX_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)

		assertNoFatalErrors(errors)
	})

	// @e2e openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-known-limitations-must-be-evidence-backed-and-linked-from-the-published-statement
	test('Accessibility limitation detail route resolves the registered component and renders a real record', async ({ loggedInPage: page }) => {
		// ⚠️ A REAL row, not `00000000-0000-0000-0000-000000000000`.
		//
		// The all-zeros id used to be "enough to prove the route resolves",
		// but it made this test self-contradictory: the object store
		// `console.error`s `Error fetching <type>/<id>` on the resulting 404
		// (twice — once per schema-slug resolution attempt), and the test's
		// own `assertNoFatalErrors` then failed on the very error the test
		// provoked. Measured on run 30835724202: the page DID render its
		// registered component correctly (heading "Accessibility limitation",
		// the Data widget, all 11 fields as `—`); only the deliberate 404
		// was red.
		//
		// Pointing at a seeded row removes the contradiction and raises the
		// bar: the page must now render the record's actual field values, not
		// the all-`—` placeholder grid an unresolvable id produces.
		const statementId = await createObject(page, 'accessibility-statement', {
			channelTitle: 'Scholiq (e2e fixture)',
			status: 'partially-compliant',
			evaluationMethod: 'self-assessment',
			lifecycle: 'draft',
		})
		const limitationId = await createObject(page, 'accessibility-limitation', {
			...(statementId ? { accessibilityStatementId: statementId } : {}),
			wcagCriterion: '2.1.1',
			severity: 'serious',
			affectedSurface: 'Course authoring — lesson reorder',
			description: 'The reorder control has no keyboard-operable equivalent.',
			justification: 'Scheduled for the next accessibility sprint.',
			workaround: 'Reorder lessons from the lesson detail page instead.',
			lifecycle: 'open',
		})
		expect(limitationId, 'an AccessibilityLimitation fixture must exist to drive its detail page').toBeTruthy()

		const errors = collectFatalErrors(page)

		await page.goto(`${LIMITATIONS_INDEX_URL}/${limitationId}`)
		// Readiness signal: the Vue root has rendered. NOT `networkidle` —
		// see ADR-074 rule 4 / hydra gate 58.
		await expect(page.locator('#scholiq-app')).not.toBeEmpty({ timeout: 20_000 })

		// The registered component resolved AND hydrated: its declared title
		// plus a field value that can only have come from the fetched record.
		await expect(page.getByRole('heading', { name: /Accessibility limitation/i }).first()).toBeVisible({ timeout: 20_000 })
		await expect(page.getByText('Course authoring — lesson reorder').first()).toBeVisible({ timeout: 20_000 })

		assertNoFatalErrors(errors)
	})
})

test.describe('accessibility-conformance — reporting a barrier (AccessibilityFeedback)', () => {

	// @e2e openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-any-authenticated-user-must-be-able-to-report-an-accessibility-barrier
	test('the feedback triage index renders without a fatal error', async ({ loggedInPage: page }) => {
		const errors = collectFatalErrors(page)

		await page.goto(FEEDBACK_INDEX_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)

		assertNoFatalErrors(errors)
	})

	// @e2e openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-any-authenticated-user-must-be-able-to-report-an-accessibility-barrier
	test('the "Report an accessibility problem" entry point opens the generic AccessibilityFeedback create form', async ({ loggedInPage: page }) => {
		const errors = collectFatalErrors(page)

		await page.goto(STATEMENT_URL)
		// Readiness signal: the Vue root has rendered. NOT `networkidle` —
		// see ADR-074 rule 4 / hydra gate 58.
		await expect(page.locator('#scholiq-app')).not.toBeEmpty({ timeout: 20_000 })

		await page.getByRole('button', { name: /Report an accessibility problem/i }).first().click()
		await page.waitForURL(/\/accessibility\/feedback\/new/, { timeout: 10_000 }).catch(() => {})

		expect(page.url()).toContain('/accessibility/feedback/new')

		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)

		assertNoFatalErrors(errors)
	})

	// @e2e openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-any-authenticated-user-must-be-able-to-report-an-accessibility-barrier
	test('a user fills and submits the AccessibilityFeedback create form and it lands as a submitted record', async ({ loggedInPage: page }) => {
		await page.goto(FEEDBACK_CREATE_URL)
		// Readiness signal: the Vue root has rendered. NOT `networkidle` —
		// see ADR-074 rule 4 / hydra gate 58.
		await expect(page.locator('#scholiq-app')).not.toBeEmpty({ timeout: 20_000 })

		// ⚠️ HARD, not soft. This used to be
		// `if (await affectedSurfaceField.isVisible().catch(() => false)) { … }`
		// — i.e. if the create form failed to mount, the ENTIRE body of this
		// test was skipped and it reported green having asserted nothing about
		// the requirement it is named after. That was defensible while the
		// register import was best-effort. It is not any more: ci-seed.sh is
		// now the workflow's `playwright-seed-command`, it verifies the
		// register, the six core schemas and the seeded-row floor, and it exits
		// non-zero (the step runs under `bash -e`) if any of them is missing.
		// So on CI the form not mounting can no longer mean "the environment
		// was not provisioned" — it can only mean the create form is broken,
		// which is exactly what this test exists to catch.
		const affectedSurfaceField = page.getByLabel(/Affected Surface/i).first()
		await expect(affectedSurfaceField, 'the AccessibilityFeedback create form must mount its fields — ci-seed.sh has already verified the register is provisioned').toBeVisible({ timeout: 15_000 })
		// The bare block is what is left of the old `if`. Kept so this change
		// reads as "the guard became an assertion" and not as a re-indent of
		// eighty lines; delete it in any commit that touches the body anyway.
		{
			await affectedSurfaceField.fill('Course authoring — lesson reorder')
			// ⚠️ The accessible name of a REQUIRED field carries the required
			// marker: the rendered control is `textbox "Description *"`, not
			// `"Description"` (measured — run 30835724202, error-context.md of
			// this very test). An ANCHORED `/^Description$/` therefore matches
			// nothing and burns the full 60s test timeout, while the
			// unanchored `/Affected Surface/i` one line above matched fine —
			// which is why only this one field looked "missing".
			await page.getByLabel(/^Description\s*\*?$/i).first().fill('The reorder control has no keyboard-operable equivalent.')

			// ⚠️ Severity is REQUIRED and is selected with the KEYBOARD, not by
			// clicking the option row. That is not a stylistic preference —
			// clicking the option is IMPOSSIBLE in this dialog.
			//
			// Measured, run 30889902343, from this test's own trace: the
			// `serious` option resolves, Playwright reports it "visible,
			// enabled and stable", and then every click attempt is refused for
			// the full test timeout with
			//
			//     <input id="nc-vue-124" placeholder="Tenant UUID (multi-tenant
			//     isolation)."> from <div role="dialog" aria-modal="true" …>
			//     subtree intercepts pointer events
			//
			// The NcSelect dropdown (`vs__dropdown-menu`, id `vs-nc-vue-123`)
			// is rendered INLINE in the form instead of teleported, and the
			// field rendered immediately after it — Tenant ID, `nc-vue-124` —
			// paints on top. Only the first option is reachable with a mouse;
			// `serious` is option-1. That is a real defect in the create dialog
			// (it is not specific to this schema — every required enum on every
			// CnDetailPage create form is affected) and it is reported
			// separately. It is NOT what this test is about, and the old
			// `.catch(() => {})` turned it into sixty seconds of silence.
			//
			// The keyboard path is immune to the overlap, and in an
			// accessibility-conformance spec it is the more appropriate
			// interaction anyway: type to filter, Enter to take the highlighted
			// option.
			const severityField = page.getByLabel(/Severity/i).first()
			await expect(severityField, 'Severity is a required field of AccessibilityFeedback and must be present on the create form').toBeVisible({ timeout: 10_000 })
			await severityField.click()
			// `keyboard.type` rather than `fill`: vue-select marks its search
			// input readonly when the select is not searchable, and `fill`
			// throws on a readonly input while typing into the focused control
			// works either way.
			await page.keyboard.type('serious')
			const seriousOption = page.getByRole('option', { name: /serious/i }).first()
			await expect(seriousOption, 'typing "serious" must leave the serious option offered').toBeVisible({ timeout: 10_000 })
			await page.keyboard.press('Enter')
			// The listbox closing is the proof the Enter was consumed as a
			// SELECTION rather than ignored. If it were ignored the options
			// would still be on screen — which is exactly the state run
			// 30889902343 captured. The assertion is only meaningful because
			// the option was asserted VISIBLE one line above: a `toBeHidden`
			// on an element that never rendered would pass for free.
			await expect(seriousOption, 'choosing a severity must close the listbox').toBeHidden({ timeout: 10_000 })

			const reporterField = page.getByLabel(/Reporter User Id/i).first()
			if (await reporterField.isVisible({ timeout: 2_000 }).catch(() => false)) {
				await reporterField.fill('admin')
			}

			// `tenant_id` is in AccessibilityFeedback's `required` list (as it
			// is on 116 of the register's 118 schemas), so CnDetailPage keeps
			// the Create button DISABLED until it is filled. Leaving it empty
			// made the click below a silent no-op — the test then asserted on
			// a submit that never happened.
			//
			// See scholiq#265: asking a barrier REPORTER to type a tenant UUID
			// is a real product defect against this spec's "any authenticated
			// user must be able to report a barrier" requirement. Until that is
			// decided, the test supplies the same tenant the seeder stamps on
			// every other row so the created record is coherent with them.
			const tenantField = page.getByLabel(/Tenant Id/i).first()
			if (await tenantField.isVisible({ timeout: 2_000 }).catch(() => false)) {
				await tenantField.fill(await seededTenantId(page))
			}

			// The Create button leaving the disabled state is itself the proof
			// that every required field is now satisfied — assert it rather
			// than clicking into the void.
			const submitButton = page.getByRole('button', { name: /^(Save|Create|Submit)$/i }).first()
			await expect(submitButton, 'the Create button must become enabled once every required field is filled').toBeEnabled({ timeout: 10_000 })
			await submitButton.click()
			// Give the create a bounded window to navigate off /new. NOT
			// `networkidle` — see ADR-074 rule 4 / hydra gate 58. Either
			// outcome (navigation or a toast) is accepted just below, so a
			// timeout here is not itself a failure.
			await page.waitForURL((u) => !u.pathname.includes('/accessibility/feedback/new'), { timeout: 10_000 }).catch(() => {})

			// A successful create either navigates off /new (to the new
			// record's detail route) or shows a success toast — both are
			// acceptable evidence the record was created in `submitted`
			// state (AccessibilityFeedback's initial lifecycle value).
			const stillOnCreateRoute = page.url().includes('/accessibility/feedback/new')
			const successToast = await page.getByText(/created|saved|submitted/i).first().isVisible({ timeout: 5_000 }).catch(() => false)
			expect(!stillOnCreateRoute || successToast, 'submitting the AccessibilityFeedback create form should either navigate off /new or show a success confirmation').toBeTruthy()
		}
	})
})
