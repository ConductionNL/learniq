// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * The board checks of the four designed school portals
 * (school-design: wilgenboom, vaartveld, esdoornveen, warmtepompacademie).
 *
 * Run against any instance that loaded the example sets with
 * `occ learniq:example-set:load <set>`:
 *
 *   PLAYWRIGHT_BASE_URL=http://localhost:8092 npx playwright test -c tests/e2e/portal-design.config.ts
 *
 * Two viewports, as the boards are drawn: 1440 wide (desktop boards) and
 * 390 wide (Mobiel boards). One worker: the specs sign in as the story's
 * people and share their sessions with nothing else.
 *
 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md
 */

import { defineConfig, devices } from '@playwright/test'
import { baseUrl } from './base-url.ts'

export default defineConfig({
	testDir: './portal-design',
	testMatch: /\.spec\.ts$/,
	timeout: 180_000,
	workers: 1,
	fullyParallel: false,
	reporter: [
		['list'],
		['json', { outputFile: '../../test-results/portal-design.json' }],
	],
	outputDir: '../../test-results/portal-design-output',
	use: {
		baseURL: baseUrl(),
		locale: 'nl-NL',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		headless: true,
		...(process.env.PORTAL_DESIGN_CHROME
			? { launchOptions: { executablePath: process.env.PORTAL_DESIGN_CHROME } }
			: {}),
	},
	projects: [
		{
			name: 'desktop',
			use: {
				...devices['Desktop Chrome'],
				viewport: { width: 1440, height: 1000 },
			},
		},
		{
			name: 'phone',
			use: { ...devices['Pixel 7'], viewport: { width: 390, height: 844 } },
		},
	],
})
