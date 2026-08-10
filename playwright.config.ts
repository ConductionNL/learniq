import { defineConfig, devices } from '@playwright/test'
import { baseUrl } from './tests/e2e/base-url'

/**
 * Playwright configuration for Scholiq E2E tests.
 *
 * The target instance MUST be named explicitly via PLAYWRIGHT_BASE_URL
 * (or BASE_URL, which the shared CI quality workflow exports, or the
 * historical PW_BASE_URL). There is deliberately no default — see
 * tests/e2e/base-url.ts.
 *
 * Run: PLAYWRIGHT_BASE_URL=http://localhost:8088 npx playwright test
 * UI mode: PLAYWRIGHT_BASE_URL=... npx playwright test --ui
 */
export default defineConfig({
	testDir: './tests/e2e',
	/* Maximum time one test can run (includes login overhead of ~15-20s) */
	timeout: 60_000,
	/*
	 * Stop the whole run on our own clock, before CI kills it.
	 *
	 * The shared ConductionNL quality workflow caps this job at
	 * `timeout-minutes: 45`. A job cancelled by that cap produces NO verdict:
	 * Playwright never prints its tally, the `if: failure()` trace upload never
	 * fires, and the `if: always()` report upload does not run on a cancelled
	 * job either. The run you most need to read is then the one that leaves
	 * nothing behind — and it still shows up as "fail" in `gh pr checks` while
	 * carrying no information. Runs cancelled at ~45m16s have been observed in
	 * this fleet.
	 *
	 * Measured overhead in that job before `Run Playwright tests` even starts
	 * is 2.0-2.4 min, and the upload steps after it take seconds, so 38m leaves
	 * roughly 7 minutes of margin under the cap while guaranteeing both a
	 * failure count and the artifacts that explain it.
	 */
	globalTimeout: 38 * 60_000,
	/* Reporter */
	reporter: [['list'], ['html', { open: 'never', outputFolder: 'test-results/playwright-report' }]],
	/* Shared settings */
	use: {
		baseURL: baseUrl(),
		/*
		 * Keep the trace of every FAILED test.
		 *
		 * This was `on-first-retry`, which only writes a trace when a retry
		 * actually happens — and this config sets no `retries` key at all, so
		 * Playwright's default of 0 applied and there was never a first retry
		 * to trigger on. The result: this suite has written ZERO traces for its
		 * entire history, while the file read as though tracing were
		 * configured. Nothing in it hinted otherwise, because the `retries`
		 * half of the pair was not written down anywhere.
		 *
		 * `retain-on-failure` captures every test and retains only the failures.
		 * It is strictly more informative than `on-first-retry` and, crucially,
		 * does not depend on the retry count — so it cannot be silently
		 * disabled by a setting in a different part of the file.
		 */
		trace: 'retain-on-failure',
		/* Screenshot on failure */
		screenshot: 'only-on-failure',
		/* Headless */
		headless: true,
		/* Reuse authentication session across tests within a worker */
		storageState: 'test-results/.auth/admin.json',
	},
	/* Global setup: log in once and save session */
	globalSetup: './tests/e2e/global-setup.ts',
	/* Configure projects */
	projects: [
		// Default regression project. Excludes the docs capture spec so
		// PR pipelines don't reshoot screenshots on every push.
		{
			name: 'chromium',
			testIgnore: ['**/docs-screenshots.spec.ts'],
			use: { ...devices['Desktop Chrome'] },
		},
		// Documentation capture project (ADR-030 / journeydoc).
		//
		// ⚠️ This was documented as "Opt-in: npx playwright test --project
		// docs-capture", but it WAS NOT opt-in: a project listed here runs
		// whenever no `--project` filter is given, and `npm run test:e2e` gives
		// none. Every plain regression run therefore reshot and OVERWROTE the 52
		// committed PNGs under docs/static/screenshots/tutorials/ — 52 modified
		// binary files in the working tree, on a run nobody asked to capture.
		// Observed directly: a `npx playwright test` regression run left all 52
		// dirty in `git diff --name-only`.
		//
		// Gate it on an env var so the documented behaviour is the real behaviour:
		//   SCHOLIQ_DOCS_CAPTURE=1 npx playwright test --project docs-capture
		...(process.env.SCHOLIQ_DOCS_CAPTURE
			? [{
				name: 'docs-capture',
				testMatch: /docs-screenshots\.spec\.ts$/,
				use: {
					...devices['Desktop Chrome'],
					viewport: { width: 1280, height: 800 },
				},
				timeout: 90_000,
			}]
			: []),
	],
	/* Output folder */
	outputDir: 'test-results',
})
