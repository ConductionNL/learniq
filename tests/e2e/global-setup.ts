import { chromium } from '@playwright/test'
import { execFileSync } from 'child_process'
import * as fs from 'fs'
import * as path from 'path'
import { baseUrl } from './base-url.ts'

/** Repo root — `tests/e2e/` is two levels down. */
const APP_ROOT = path.resolve(__dirname, '..', '..')

/**
 * Where the authenticated browser state is written.
 *
 * Anchored to the repo root rather than the process CWD. Both playwright
 * configs point `use.storageState` at this same location, and a CWD-relative
 * path here silently writes the file somewhere Playwright will not read it —
 * which does not fail, it just runs the whole suite logged OUT.
 */
const AUTH_FILE = path.join(APP_ROOT, 'test-results', '.auth', 'admin.json')

/**
 * Marker written by tests/e2e/ci-seed.sh once it has provisioned the register
 * and seeded the example dataset. Its presence means this globalSetup does not
 * have to repeat a multi-minute seed that already ran in the workflow's
 * dedicated "Seed test data" step.
 *
 * ⚠️ Deliberately NOT under test-results/. Playwright removes every project
 * `outputDir` at the start of the run (createRemoveOutputDirsTask, which runs
 * before globalSetup), so a marker left there by an earlier workflow step no
 * longer exists by the time this function reads it.
 */
const SEED_MARKER = path.join(APP_ROOT, '.e2e-state', 'ci-seeded')

/**
 * Run the example-data seed (imports the learniq register into OpenRegister + creates
 * a coherent example dataset). Best-effort: a failing seed (NC unreachable in CI, or
 * the OR register-import gap openregister#1487) does NOT abort the run — it just means
 * the index-page specs skip their row-count soft-assertions. Sets
 * process.env.LEARNIQ_E2E_SEEDED='1' on success.
 */
function runSeed(): void {
	// ci-seed.sh already ran the same seed, in the step whose job it is, and
	// recorded whether it fully succeeded. Re-running it here would cost several
	// minutes of redundant existence checks on every CI run.
	if (fs.existsSync(SEED_MARKER)) {
		const status = fs.readFileSync(SEED_MARKER, 'utf8').trim()
		if (status === 'full') {
			process.env.LEARNIQ_E2E_SEEDED = '1'
			console.log(
				'[global-setup] seed already done by ci-seed.sh (full) — skipping',
			)
		} else {
			console.warn(
				`[global-setup] seed already done by ci-seed.sh (${status}) — skipping; index specs will not assert row counts`,
			)
		}
		return
	}

	const seedScript = path.join(__dirname, 'seed-example-data.mjs')
	if (!fs.existsSync(seedScript)) return
	try {
		const out = execFileSync('node', [seedScript], {
			env: {
				...process.env,
				OR_BASE_URL: baseUrl(),
				OR_USER: process.env.NC_ADMIN_USER ?? 'admin',
				OR_PASS: process.env.NC_ADMIN_PASS ?? 'admin',
			},
			stdio: ['ignore', 'pipe', 'pipe'],
			timeout: 120_000,
		})
		process.stdout.write(out)
		process.env.LEARNIQ_E2E_SEEDED = '1'
		console.log('[global-setup] example data seeded')
	} catch (err: any) {
		console.warn(
			'[global-setup] seed skipped/failed (continuing — index specs will not assert row counts):',
			err?.message ?? err,
		)
		if (err?.stdout) process.stdout.write(err.stdout)
	}
}

/**
 * Global Playwright setup: log in as admin once and save the browser storage
 * state (cookies + localStorage) to test-results/.auth/admin.json, then seed
 * example data.
 *
 * All tests share this session — no per-test login overhead.
 */
async function globalSetup(): Promise<void> {
	const baseURL = baseUrl()
	const username = process.env.NC_ADMIN_USER ?? 'admin'
	const password = process.env.NC_ADMIN_PASS ?? 'admin'
	const authFile = AUTH_FILE

	// Ensure output directory exists
	fs.mkdirSync(path.dirname(authFile), { recursive: true })

	const browser = await chromium.launch({ headless: true })
	const page = await browser.newPage()

	try {
		await page.goto(`${baseURL}/index.php/login`, {
			waitUntil: 'domcontentloaded',
			timeout: 30_000,
		})

		const passwordInput = page.locator('input[name="password"]')
		if (await passwordInput.isVisible({ timeout: 10_000 }).catch(() => false)) {
			await page.locator('input[name="user"]').fill(username)
			await page.locator('input[name="password"]').fill(password)
			await page
				.locator('#submit, button[type="submit"], input[type="submit"]')
				.first()
				.click()

			// Wait for the redirect away from login
			await page
				.waitForURL((url) => !url.pathname.includes('/login'), {
					timeout: 30_000,
				})
				.catch(() => {
					// May already be redirected
				})
		}

		/*
		 * Suppress the product walkthrough (ADR-043) for automated runs, the way
		 * dossiq's global-setup already does.
		 *
		 * This became load-bearing with @conduction/nextcloud-vue 2.22.x. A
		 * `placement: "center"` welcome step used to be parked in
		 * `_pendingAutoTour` and never opened; the library now correctly starts
		 * it on any route, so the tour actually appears — and its
		 * `cn-walkthrough__dim--full` layer is a `role="dialog" aria-modal="true"`
		 * overlay that intercepts every click behind it. Specs that had never had
		 * to account for a tour started timing out, and `getByRole('dialog')`
		 * began resolving to the dim layer instead of the modal under test.
		 *
		 * The marker is per USER, not per test, so without it the suite is also
		 * order-dependent: whichever spec runs first wears the tour.
		 *
		 * The sentinel is higher than any real app version, so every step's
		 * `sinceVersion` sorts below it and the tour composes to an empty step
		 * set rather than merely starting dismissed.
		 */
		try {
			await page.evaluate(() => {
				try {
					window.localStorage.setItem(
						'cn-walkthrough-seen:learniq',
						'999.0.0',
					)
				} catch {
					// localStorage unavailable — specs fall back to dismissing by hand.
				}
			})
		} catch {
			// Never fail setup over an optional convenience.
		}

		// Save authenticated state
		await page.context().storageState({ path: authFile })
		console.log('[global-setup] Saved auth state to', authFile)
	} catch (err) {
		console.error('[global-setup] Login failed:', err)
		// Write an empty auth file so tests can at least run (unauthenticated)
		fs.writeFileSync(authFile, JSON.stringify({ cookies: [], origins: [] }))
	} finally {
		await browser.close()
	}

	// Seed example data after auth is established (best-effort).
	runSeed()
}

export default globalSetup
