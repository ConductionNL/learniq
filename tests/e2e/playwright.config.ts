// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

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
 * declared in that config runs. The root `playwright.config.ts` declares a
 * `docs-capture` project (journeydoc screenshot re-shoots, ADR-030) alongside
 * `chromium`; it is already env-gated there, but relying on an env gate to
 * keep a CI job honest is one `SCHOLIQ_DOCS_CAPTURE` away from re-shooting 52
 * committed PNGs on a PR run. `playwright-test-path: tests/e2e` in the caller
 * makes the workflow's FIRST lookup hit this file, which declares only the
 * regression project. The root config is untouched and remains the entry point
 * for local runs and `--project docs-capture`.
 *
 * The report/output paths differ from the root config deliberately. The
 * workflow's upload step collects
 *
 *     server/apps/scholiq/playwright-report/
 *     server/apps/scholiq/test-results/
 *
 * so on CI the artifacts must land at the APP ROOT. The root config writes its
 * HTML report to `test-results/playwright-report` — one level too deep — which
 * the upload step would not match, and `if-no-files-found: ignore` means that
 * mismatch uploads an EMPTY artifact instead of failing: a red run with no
 * report to read.
 */

import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'

import { baseUrl } from './base-url'

/** Repo root — `tests/e2e/` is two levels down. */
const APP_ROOT = path.resolve(__dirname, '..', '..')

export default defineConfig({
	testDir: __dirname,
	globalSetup: path.resolve(__dirname, 'global-setup.ts'),
	timeout: 60_000,
	expect: { timeout: 15_000 },
	// ⚠️ PARALLEL ON PURPOSE — the suite does not fit in the job's time budget
	// single-threaded.
	//
	// The shared workflow caps this job at `timeout-minutes: 45`, sized from
	// suites of "4-10 min" (planix 0.8, doriath 4.2, opencatalogi 10.0). Scholiq
	// declares 276 manifest pages and the suite is 433 tests; run single-worker
	// it took 1.0h (run 30798535945) and 1.4h (run 30810841150), and run
	// 30817505312 was CANCELLED mid-suite at exactly 45 minutes. A suite that
	// cannot finish inside the cap reports `cancelled`, which is not a result.
	//
	// Parallelism is safe here, but NOT for the reason this comment used to
	// give ("no spec creates, mutates or deletes objects" — three do):
	//
	//   * nextcloud-app.spec.ts POSTs /api/settings and PUTs
	//     notification-preferences (admin app-config + per-user prefs);
	//   * accessibility-conformance.spec.ts creates an AccessibilityStatement +
	//     AccessibilityLimitation fixture and submits the AccessibilityFeedback
	//     create form.
	//
	// What actually makes those safe under parallelism is that each writes a
	// key or a ROW no other spec reads back, and no spec asserts on a global
	// collection COUNT — the count assertions in the suite
	// (course-authoring-ux, adaptive-release, progress-tracking, dashboard,
	// index-pages) are all scoped to one parent object or are `>= 1` /
	// `<= 1` shape checks. Audited before raising the worker count; re-audit
	// before adding a spec that deletes a row or asserts an exact global count.
	//
	// The one shared mutable file is the auth storageState, which globalSetup
	// writes once before any worker starts and workers only read.
	//
	// The server side is provisioned for it: the shared workflow sets
	// PHP_CLI_SERVER_WORKERS=8, so `php -S` is no longer the single-request
	// bottleneck it was when `workers: 1` was chosen.
	fullyParallel: true,
	// One retry on CI. The PHP built-in server is cold on the first request of
	// the run; see the warm-up in ci-seed.sh and PHP_CLI_SERVER_WORKERS in the
	// shared workflow. The retry is a hedge, not a substitute for either.
	retries: process.env.CI ? 1 : 0,
	// 6, not 8: PHP_CLI_SERVER_WORKERS=8 is the server's ceiling and each
	// Playwright worker drives a full Chromium plus the SPA's boot fan-out, so
	// matching them 1:1 would queue requests behind each other again. At 4 the
	// suite took 24.2 min (run 30835724202) against a 20-minute budget; 6 keeps
	// two server workers in hand for the seed/warm-up traffic.
	workers: process.env.CI ? 6 : 1,
	reporter: [
		['list'],
		['html', { open: 'never', outputFolder: path.join(APP_ROOT, 'playwright-report') }],
	],
	outputDir: path.join(APP_ROOT, 'test-results'),

	use: {
		baseURL: baseUrl(),
		// Written by global-setup.ts. Playwright removes `outputDir` BEFORE
		// running globalSetup (createRemoveOutputDirsTask precedes
		// createGlobalSetupTask), so keeping the auth file inside it is safe.
		storageState: path.join(APP_ROOT, 'test-results', '.auth', 'admin.json'),
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
		headless: true,
	},

	projects: [
		{
			name: 'chromium',
			testIgnore: ['**/docs-screenshots.spec.ts'],
			use: { ...devices['Desktop Chrome'] },
		},
	],
})
