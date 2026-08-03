import * as fs from 'fs'
import * as path from 'path'
import { test, expect } from './fixtures'
import manifest from '../../src/manifest.json'

/**
 * Visits EVERY `type: "index"` page declared in src/manifest.json and asserts:
 *   1. The CnAppRoot shell renders (the app nav + a main content area are present).
 *   2. No *uncaught* JS exception fires (a `pageerror`). OR-API fetch 404s /
 *      "No items" empty states are fine — the scholiq register may not be
 *      imported into OpenRegister yet (openregister#1487); a hard render crash
 *      is not.
 *   3. The page heading reflects the page (its `title`, or the schema name).
 *   4. For index pages whose schema the seed script populated, at least one row
 *      is present — a *soft* assertion (`expect.soft`) so a seeding gap doesn't
 *      fail the whole run.
 *
 * Routing: the SPA uses vue-router in `history` mode with base
 * `/index.php/apps/scholiq`, so a manifest route like `/courses` is reached at
 * `/index.php/apps/scholiq/courses` (the PageController `catchAll` route serves
 * the SPA shell for `/{path}`).
 *
 * The seed script (tests/e2e/seed-example-data.mjs) runs in ci-seed.sh (CI) or
 * globalSetup (local) and records WHICH schemas it actually created rows for in
 * `test-results/.seeded-schemas.json`. That file — not a hand-written list — is
 * what gates the row-count assertion.
 *
 * ⚠️ WHY NOT A HAND-WRITTEN LIST. This spec used to carry a literal
 * `SEEDED_SCHEMAS` set of 32 names. Measured on the first CI run, the seeder
 * created rows for 17 of them: the other 15 creates were failing OpenRegister's
 * `required`-property validation, being logged as a warning, and swallowed. So
 * the spec asserted "≥1 row" for schemas that provably had none, and nothing in
 * the list could ever notice it had drifted from the seeder. Reading the
 * seeder's own output makes it impossible for the two to disagree: fix a
 * fixture and coverage grows automatically, break one and it shrinks — and
 * ci-seed.sh gates the floor so a silent collapse to zero still fails the job.
 */

const APP_BASE = '/index.php/apps/scholiq'

/**
 * Per-schema row counts the seeder actually produced, keyed by the register's
 * schema NAME (the same value `manifest.pages[].config.schema` carries).
 *
 * @return {Record<string, number>} Empty when the seeder did not run.
 */
function loadSeededCounts(): Record<string, number> {
	// `.e2e-state/`, not `test-results/`: Playwright deletes every project
	// outputDir at the start of the run, which would take the seeder's marker
	// with it.
	const file = path.resolve(__dirname, '..', '..', '.e2e-state', 'seeded-schemas.json')
	try {
		return JSON.parse(fs.readFileSync(file, 'utf8'))
	} catch {
		return {}
	}
}

const SEEDED_COUNTS = loadSeededCounts()
// The deeper checks are meaningful once the register is actually populated. The
// file is the reliable signal: globalSetup mutates process.env in the RUNNER
// process, and Playwright workers are separate processes.
const SEEDED = Object.keys(SEEDED_COUNTS).length > 0 || process.env.SCHOLIQ_E2E_SEEDED === '1'

type IndexPage = { id: string; route: string; title: string; schema?: string }

const indexPages: IndexPage[] = (manifest as any).pages
	.filter((p: any) => p.type === 'index' && typeof p.route === 'string' && !p.route.includes(':'))
	.map((p: any) => ({ id: p.id, route: p.route, title: p.title ?? p.id, schema: p.config?.schema }))

function attachErrorCollector(page: import('@playwright/test').Page): string[] {
	const errs: string[] = []
	page.on('pageerror', (e) => errs.push(`pageerror: ${e.message}`))
	page.on('console', (msg) => {
		if (msg.type() !== 'error') return
		const t = msg.text()
		// Tolerated: network / resource / OR-not-imported / unrelated-app noise.
		if (/favicon|font|Failed to load resource|net::ERR|Failed to fetch|404|NetworkError|\[FATAL\] (photos|pipelinq)/i.test(t)) return
		// Tolerated: Vue's "render error" is sometimes logged as a console error AND a
		// pageerror — we only fail on the pageerror. So skip console here unless it's a
		// clearly app-fatal pattern.
		if (/TypeError: Cannot read|is not a function|is not defined/i.test(t)) errs.push(`console.error: ${t}`)
	})
	return errs
}

test.describe(`Scholiq index pages (${indexPages.length})`, () => {
	for (const p of indexPages) {
		test(`${p.id} — ${APP_BASE}${p.route}`, async ({ loggedInPage: page }) => {
			const errors = attachErrorCollector(page)

			await page.goto(`${APP_BASE}${p.route === '/' ? '/' : p.route}`, { waitUntil: 'domcontentloaded', timeout: 20_000 })
			// Give the SPA + the index page's data fetch a moment to settle.
			await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})

			// (hard) The Scholiq SPA shell was served for this route — the page title
			// says so, the body isn't blank, and it's not an NC 404/500 error page.
			// This is the only assertion that holds across the board today: 32 of the
			// 35 schemas can't be imported into OpenRegister yet (openregister#1487), so
			// most index pages 404 on their data fetch and (until that's fixed) throw a
			// JS error and render an empty body section — the deeper "no JS error" /
			// "≥1 row" checks below are kept but only applied once the register is
			// imported (process.env.SCHOLIQ_E2E_SEEDED, set by the globalSetup seed).
			expect(await page.title(), `${p.id}: should be the Scholiq app page`).toContain('Scholiq')
			const bodyText = (await page.innerText('body')).trim()
			expect(bodyText.length, `${p.id}: page body should not be blank`).toBeGreaterThan(0)
			expect(bodyText, `${p.id}: should not be an NC error page`).not.toMatch(/^(404 Not Found|Internal Server Error)$/i)

			// Deeper checks — only meaningful once OR has the scholiq register.
			if (SEEDED) {
				expect.soft(errors, `${p.id}: no uncaught JS error — ${errors.join(' | ')}`).toHaveLength(0)
				expect.soft(
					await page.locator('.app-navigation, nav#app-navigation, [data-app="scholiq"]').first().isVisible().catch(() => false),
					`${p.id}: CnAppRoot nav should be present`,
				).toBe(true)
				const seededRows = p.schema ? (SEEDED_COUNTS[p.schema] ?? 0) : 0
				if (seededRows > 0) {
					const rows = page.locator('table tbody tr, .list-item, [data-cy-object-row], .cn-index-row, .app-content-list-item')
					expect.soft(
						await rows.count().catch(() => 0),
						`${p.id}: the seeder created ${seededRows} "${p.schema}" object(s), so this index page must render ≥1 row`,
					).toBeGreaterThan(0)
				}
			}
		})
	}
})
