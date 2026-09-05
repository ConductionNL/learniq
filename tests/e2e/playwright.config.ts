// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * CI regression config for the shared `E2E Tests (Playwright)` job.
 *
 * WHY A SECOND CONFIG EXISTS
 * --------------------------
 * The shared workflow (ConductionNL/.github/.github/workflows/quality.yml)
 * runs the suite as:
 *
 *     CONFIG="${{ inputs.playwright-test-path }}/playwright.config.ts"
 *     if [ ! -f "$CONFIG" ] && [ -f "playwright.config.ts" ]; then
 *       CONFIG="playwright.config.ts"
 *     fi
 *     npx playwright test --config="$CONFIG"
 *
 * Note what is missing: `--project`. Whichever config it picks, EVERY project
 * declared in that config runs. `playwright-test-path: tests/e2e` in the caller
 * makes the workflow's FIRST lookup hit this file, so the project list CI runs
 * is decided here rather than inherited from the root config's local-dev
 * project list. The root config is untouched and stays the entry point for
 * local runs and for `LEARNIQ_DOCS_CAPTURE=1 … --project docs-capture`.
 *
 * REPORT + OUTPUT PATHS
 * ---------------------
 * The workflow uploads:
 *
 *     server/apps/<app>/playwright-report/          (report)
 *     server/apps/<app>/tests/e2e/playwright-report/
 *     server/apps/<app>/test-results/               (traces, on failure)
 *
 * The root config writes its HTML report to `test-results/playwright-report`
 * — which matches NEITHER report path. Both upload steps declare
 * `if-no-files-found: ignore`, so with the root config's paths the run would
 * upload an EMPTY `playwright-report` artifact and say nothing about it: a red
 * run with no report to read, which is exactly when you need one. Writing the
 * report to the app root makes the first upload path match.
 *
 * ── TIME BUDGET: EVERY NUMBER BELOW IS MEASURED ─────────────────────────────
 *
 * The shared workflow caps this job at `timeout-minutes: 45`. A suite that
 * does not finish inside the cap reports `cancelled`, which is NOT a verdict —
 * it is the failure mode where the gate measures nothing and the artifact
 * upload never runs. Run 31030901245 (workers: 1) died exactly that way at
 * 45m28s. So the numbers here are sized from real runs, not from intuition,
 * and every one of them moves in the STRICT direction.
 *
 *   run 30835724202  workers: 4  → 24.2 min wall | 94.0 min of test-time
 *                                | 408 tests, mean 13.8 s, slowest PASS 22.4 s
 *   run 30889902343  workers: 6  → 29.2 min wall | 169.7 min of test-time
 *                                | 407 tests, mean 25.0 s, slowest PASS 37.4 s
 *
 * Read those two rows together. Going 4 -> 6 workers made the WALL CLOCK 21%
 * WORSE while inflating every individual test by 81%. The GitHub runner has
 * 4 cores and already hosts `php -S` with PHP_CLI_SERVER_WORKERS=8; a 5th and
 * 6th Chromium do not get a core, they take one away from the server that is
 * answering their own requests. 4 is not a compromise between 1 and 6, it is
 * the measured optimum, and it is what this config uses.
 */

import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'
import { baseUrl } from './base-url.ts'

/** Repo root — `tests/e2e/` is two levels down. */
const APP_ROOT = path.resolve(__dirname, '..', '..')

export default defineConfig({
	testDir: __dirname,
	globalSetup: path.resolve(__dirname, 'global-setup.ts'),

	// 40 s, down from 60 s. Sized on the slowest PASSING test measured under
	// the worker count this config actually uses: 22.4 s (run 30835724202,
	// detail-pages.spec.ts › AccessibilityFeedback). 40 s is 1.79x that, so
	// nothing that passes today is anywhere near the cap — but a HUNG test now
	// costs 40 s instead of 60 s. That distinction is the whole point: passes
	// finish fast, failures sit on the cap, so the per-test timeout is a tax
	// paid almost entirely by failures. It was never a limit any passing test
	// was pressing against, and cutting it buys back a third of the budget on
	// the exact runs that need it most.
	//
	// Do NOT raise this to make something pass. If a test needs more than 40 s
	// on a suite whose mean is 13.8 s, the test or the code is wrong.
	timeout: 40_000,
	expect: { timeout: 15_000 },

	// Below the shared workflow's `timeout-minutes: 45`, on purpose. Without
	// it, a suite that overruns is KILLED by the runner: the process dies mid
	// test, Playwright prints no tally, the reporter writes no HTML, and both
	// upload steps (`if-no-files-found: ignore`) upload nothing. The job then
	// reports `cancelled`, which reads like neither a pass nor a fail.
	//
	// With it, Playwright itself stops the run, prints
	// `N passed / N failed / N did not run`, flushes the HTML report, and exits
	// non-zero — a red run WITH evidence. 38 min leaves ~7 min of the job cap
	// for the report writer and the two artifact uploads (measured at ~90 s for
	// 7.0 MB + 6.1 MB in run 30889902343).
	//
	// Headroom against the measured 24.2 min at these settings: 57%.
	globalTimeout: 38 * 60_000,

	// PARALLEL ON PURPOSE — see the time-budget block above. The suite is
	// 433 tests and does not fit the job cap single-threaded (1.0 h at
	// workers: 1, run 30798535945).
	//
	// Parallelism is safe here, but NOT for the reason a previous revision of
	// this comment gave ("no spec creates, mutates or deletes objects" — three
	// do):
	//
	//   * nextcloud-app.spec.ts POSTs /api/settings and PUTs
	//     notification-preferences (admin app-config + per-user prefs);
	//   * accessibility-conformance.spec.ts creates an AccessibilityStatement +
	//     AccessibilityLimitation fixture and submits the AccessibilityFeedback
	//     create form.
	//
	// What actually makes those safe is that each writes a key or a ROW no
	// other spec reads back, and no spec asserts on a global collection COUNT —
	// the count assertions in the suite (course-authoring-ux, adaptive-release,
	// progress-tracking, dashboard, index-pages) are all scoped to one parent
	// object or are `>= 1` / `<= 1` shape checks. Audited before raising the
	// worker count; RE-AUDIT before adding a spec that deletes a row or asserts
	// an exact global count.
	//
	// The one shared mutable file is the auth storageState, which globalSetup
	// writes once before any worker starts and workers only read. There is no
	// per-worker login, so Nextcloud's brute-force throttle is never engaged.
	fullyParallel: true,
	workers: process.env.CI ? 4 : 1,

	// ZERO retries on CI, deliberately.
	//
	// A retry can only ever turn red into green — it cannot turn green into
	// red — so it adds no information about correctness; it only hides
	// instability and doubles the cost of every failure. At 433 tests that cost
	// is the difference between finishing and being cancelled: run 30889902343
	// spent a full minute replaying ONE failing test against a budget it was
	// already close to. `trace: 'retain-on-failure'` replaces what
	// `trace: 'on-first-retry'` was buying — the trace is captured on the FIRST
	// failure instead of requiring a second run to produce one.
	retries: 0,

	reporter: [
		['list'],
		[
			'html',
			{
				open: 'never',
				outputFolder: path.join(APP_ROOT, 'playwright-report'),
			},
		],
	],
	outputDir: path.join(APP_ROOT, 'test-results'),

	use: {
		baseURL: baseUrl(),
		// Written by global-setup.ts, anchored to the APP ROOT rather than the
		// process CWD. This is load-bearing: `testDir` resolves against the
		// CONFIG directory while `storageState` and `globalSetup` resolve
		// against the CWD, and a CWD-relative path that does not exist does NOT
		// fail — Playwright runs the whole suite LOGGED OUT and the assertions
		// pass or fail for entirely the wrong reason. Do not "tidy" this to a
		// relative path without also changing global-setup.ts.
		//
		// Playwright removes `outputDir` BEFORE running globalSetup
		// (createRemoveOutputDirsTask precedes createGlobalSetupTask), so
		// keeping the auth file inside it is safe.
		storageState: path.join(APP_ROOT, 'test-results', '.auth', 'admin.json'),
		// Not 'on-first-retry' — there are no retries. A first failure must
		// carry its own trace or the failure ships with no evidence.
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		headless: true,
	},

	projects: [
		{
			name: 'chromium',
			// Re-shooting the committed tutorial PNGs is the dedicated
			// `Journeydoc Capture` job's work, not a regression run's.
			testIgnore: ['**/docs-screenshots.spec.ts'],
			use: { ...devices['Desktop Chrome'] },
		},
	],
})
