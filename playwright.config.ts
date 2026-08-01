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
	/* Reporter */
	reporter: [['list'], ['html', { open: 'never', outputFolder: 'test-results/playwright-report' }]],
	/* Shared settings */
	use: {
		baseURL: baseUrl(),
		/* Collect trace on first retry */
		trace: 'on-first-retry',
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
