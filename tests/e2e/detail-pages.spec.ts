import * as fs from 'fs'
import * as path from 'path'
import { test, expect } from './fixtures'
import manifest from '../../src/manifest.json'

/**
 * Smoke-tests EVERY `type: "detail"` page declared in src/manifest.json with a
 * placeholder object id, asserting the detail renderer shows a "not found" /
 * detail shell without an *uncaught* JS exception. (A missing object → empty
 * detail / "not found" message is fine; a hard render crash is not.)
 *
 * Each detail route has one or more `:param` segments — they're replaced with a
 * placeholder that produces a syntactically valid URL.
 */

const APP_BASE = '/index.php/apps/scholiq'

/**
 * True once the seeder has actually populated the register.
 *
 * Reads the seeder's own output file rather than `process.env` — globalSetup
 * mutates the environment of the RUNNER process, and Playwright test workers
 * are separate processes, so the env var is not a signal this file can rely on.
 * The env var is still honoured as a fallback for a hand-driven local run.
 */
function isSeeded(): boolean {
	// `.e2e-state/`, not `test-results/`: Playwright deletes every project
	// outputDir at the start of the run, which would take the seeder's marker
	// with it.
	const file = path.resolve(__dirname, '..', '..', '.e2e-state', 'seeded-schemas.json')
	try {
		return Object.keys(JSON.parse(fs.readFileSync(file, 'utf8'))).length > 0
	} catch {
		return process.env.SCHOLIQ_E2E_SEEDED === '1'
	}
}

const SEEDED = isSeeded()

type DetailPage = { id: string; route: string; resolved: string }

function resolveRoute(route: string): string {
	// /courses/:id → /courses/e2e-nonexistent ; /courses/:courseId/lessons/:id → /courses/e2e-nonexistent/lessons/e2e-nonexistent
	return route.replace(/:([A-Za-z0-9_]+)/g, 'e2e-nonexistent')
}

const detailPages: DetailPage[] = (manifest as any).pages
	.filter((p: any) => p.type === 'detail' && typeof p.route === 'string')
	.map((p: any) => ({ id: p.id, route: p.route, resolved: resolveRoute(p.route) }))

test.describe(`Scholiq detail pages (${detailPages.length})`, () => {
	for (const p of detailPages) {
		test(`${p.id} — ${APP_BASE}${p.resolved}`, async ({ loggedInPage: page }) => {
			const errors: string[] = []
			page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`))

			await page.goto(`${APP_BASE}${p.resolved}`, { waitUntil: 'domcontentloaded', timeout: 20_000 })
			await page.waitForLoadState('networkidle', { timeout: 12_000 }).catch(() => {})

			// (hard) The Scholiq SPA was served for this detail route — not blank, not
			// an NC error page. (The detail renderer 404s on the missing object and —
			// until openregister#1487 imports the scholiq schemas — throws a JS error and
			// renders an empty content section; the deeper "no JS error" check below is
			// gated on the register being imported.)
			expect(await page.title(), `${p.id}: should be the Scholiq app page`).toContain('Scholiq')
			const bodyText = (await page.innerText('body')).trim()
			expect(bodyText.length, `${p.id}: body should not be blank`).toBeGreaterThan(0)
			expect(bodyText, `${p.id}: should not be an NC error page`).not.toMatch(/^(404 Not Found|Internal Server Error)$/i)

			if (SEEDED) {
				expect.soft(
					await page.locator('.app-navigation, nav#app-navigation, [data-app="scholiq"]').first().isVisible().catch(() => false),
					`${p.id}: app shell should be present`,
				).toBe(true)
				expect.soft(errors, `${p.id}: no uncaught JS error — ${errors.join(' | ')}`).toHaveLength(0)
			}
		})
	}
})
