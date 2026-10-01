// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Config for the primary school teacher-parent flows (po-parent-flows.spec.ts).
 *
 * No global setup: the suite's global setup seeds the generated example set,
 * which would put data of every segment into the primary school the spec
 * checks. The target is named explicitly, as everywhere (base-url.ts).
 *
 *   PO_FLOW_E2E=1 PLAYWRIGHT_BASE_URL=http://localhost:8090 \
 *   PO_FLOW_OIDC_ISSUER=http://host.docker.internal:4180 \
 *   npx playwright test --config tests/e2e/po-flow.config.ts
 */

import { defineConfig, devices } from '@playwright/test'
import { baseUrl } from './base-url.ts'

export default defineConfig({
	testDir: __dirname,
	testMatch: /po-parent-flows\.spec\.ts$/,
	timeout: 180_000,
	workers: 1,
	reporter: [['list']],
	outputDir: '../../test-results/po-flow-output',
	use: {
		...devices['Desktop Chrome'],
		baseURL: baseUrl(),
		viewport: { width: 1280, height: 1000 },
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		headless: true,
		// PO_FLOW_CHROME runs the flows in an installed Chrome instead of
		// Playwright's own build, e.g. /opt/google/chrome/chrome.
		...(process.env.PO_FLOW_CHROME
			? { launchOptions: { executablePath: process.env.PO_FLOW_CHROME } }
			: {}),
	},
})
