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
	fullyParallel: false,
	// One retry on CI. The PHP built-in server is cold on the first request of
	// the run; see the warm-up in ci-seed.sh and PHP_CLI_SERVER_WORKERS in the
	// shared workflow. The retry is a hedge, not a substitute for either.
	retries: process.env.CI ? 1 : 0,
	workers: 1,
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
