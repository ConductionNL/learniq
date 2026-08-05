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
 * local runs and for `SCHOLIQ_DOCS_CAPTURE=1 … --project docs-capture`.
 *
 * WHAT ACTUALLY DIFFERS FROM THE ROOT CONFIG
 * ------------------------------------------
 * 1. REPORT + OUTPUT PATHS. This is the load-bearing difference. The workflow
 *    uploads:
 *
 *        server/apps/<app>/playwright-report/          (report)
 *        server/apps/<app>/tests/e2e/playwright-report/
 *        server/apps/<app>/test-results/               (traces, on failure)
 *
 *    The root config writes its HTML report to `test-results/playwright-report`
 *    — which matches NEITHER report path. Both upload steps declare
 *    `if-no-files-found: ignore`, so with the root config's paths the run would
 *    have uploaded an EMPTY `playwright-report` artifact and said nothing about
 *    it: a red run with no report to read, which is exactly when you need one.
 *    Writing the report to the app root makes the first upload path match.
 *
 * 2. PROJECT LIST. Only `chromium`. The root config's `docs-capture` project is
 *    already env-gated behind `SCHOLIQ_DOCS_CAPTURE`, so it would not have run
 *    here anyway — but stating the CI project list explicitly means a future
 *    project added for local use cannot silently start running in CI. There is
 *    no `visual` project in this repo, so nothing pixel-diff based is excluded;
 *    `docs-screenshots.spec.ts` is ignored because re-shooting the committed
 *    tutorial PNGs is the dedicated `Journeydoc Capture` job's work, not a
 *    regression run's.
 *
 * 3. `retries: 1` on CI. The root config sets no retries at all, so a single
 *    flake fails the job with no signal about whether it is reproducible. One
 *    retry plus `trace: 'on-first-retry'` means a flake is both survivable and
 *    diagnosable.
 *
 * `testDir` is `__dirname` and `globalSetup` / `storageState` keep the paths
 * the repo's own `global-setup.ts` actually writes — the auth file is written
 * to the CWD-relative `test-results/.auth/admin.json`, and the workflow runs
 * playwright from the app root, so an app-root-anchored path is the same file.
 * Do not "tidy" that to a `__dirname`-relative path without also changing
 * global-setup.ts: `testDir` resolves against the CONFIG directory while
 * `storageState` and `globalSetup` resolve against the CWD, and a mismatch
 * produces a silently unauthenticated run rather than an error.
 */

import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'

import { baseUrl } from './base-url'

const APP_ROOT = path.resolve(__dirname, '..', '..')

export default defineConfig({
	testDir: __dirname,
	globalSetup: path.resolve(__dirname, 'global-setup.ts'),
	/* Includes ~15-20s of login overhead on the CI PHP built-in server. */
	timeout: 60_000,
	expect: { timeout: 15_000 },
	fullyParallel: false,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: [
		['html', { open: 'never', outputFolder: path.join(APP_ROOT, 'playwright-report') }],
		['list'],
	],
	outputDir: path.join(APP_ROOT, 'test-results'),

	use: {
		baseURL: baseUrl(),
		/* Written by global-setup.ts, relative to the CWD the workflow runs in. */
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
