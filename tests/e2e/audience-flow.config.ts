// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Config for the pupil, trainer and assessor flows.
 *
 * No global setup, for the same reason po-flow.config.ts has none: the
 * suite's global setup seeds the generated example set, and these three suites
 * seed their own rows and take them away again. The target is named
 * explicitly, as everywhere (base-url.ts).
 *
 *   AUDIENCE_FLOW_E2E=1 PLAYWRIGHT_BASE_URL=http://localhost:8090 \
 *   npx playwright test --config tests/e2e/audience-flow.config.ts
 *
 * One suite at a time, because all three change the portal's sign-in modes
 * while they run:
 *
 *   npx playwright test --config tests/e2e/audience-flow.config.ts pupil-flows
 */

import { defineConfig, devices } from '@playwright/test'
import { baseUrl } from './base-url.ts'

export default defineConfig({
	testDir: __dirname,
	testMatch: /(pupil|trainer|assessor)-flows\.spec\.ts$/,
	timeout: 180_000,
	workers: 1,
	// Serial across files as well: each suite adds `nextcloud` to the portal's
	// sign-in modes and puts the list back, so two at once would race.
	fullyParallel: false,
	reporter: [['list']],
	outputDir: '../../test-results/audience-flow-output',
	use: {
		...devices['Desktop Chrome'],
		baseURL: baseUrl(),
		viewport: { width: 1280, height: 1000 },
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		headless: true,
		// AUDIENCE_FLOW_CHROME runs the flows in an installed Chrome instead of
		// Playwright's own build, e.g. /opt/google/chrome/chrome.
		...(process.env.AUDIENCE_FLOW_CHROME
			? { launchOptions: { executablePath: process.env.AUDIENCE_FLOW_CHROME } }
			: {}),
	},
})
