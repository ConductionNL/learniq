// Boot smoke check: load one index page and one detail page in a real browser
// and report EVERY uncaught pageerror / fatal console error.
//
// This exists because a Vue 3 bundle can build clean, emit fine, deploy fine and
// still die at boot — @nextcloud/l10n v2 vs v3 killed this app with
// `getGettextBuilder(...).detectLanguage is not a function`, and the only place
// that was visible was a browser console. 37 minutes of e2e ran against it
// before anyone looked.
import { chromium } from '@playwright/test'

const BASE = process.env.PLAYWRIGHT_BASE_URL
if (!BASE) { console.error('PLAYWRIGHT_BASE_URL must be set'); process.exit(2) }

const browser = await chromium.launch({ headless: true })
const ctx = await browser.newContext()
const page = await ctx.newPage()

const errors = []
page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`))
page.on('console', (m) => {
	if (m.type() !== 'error') return
	const t = m.text()
	if (/is not a function|is not defined|Cannot read (properties|property)|Failed to (resolve|fetch dynamically imported)/i.test(t)) {
		errors.push(`console.error: ${t}`)
	}
})

// Log in.
await page.goto(`${BASE}/index.php/login`, { waitUntil: 'domcontentloaded' })
await page.locator('input[name="user"]').fill(process.env.NC_ADMIN_USER ?? 'admin')
await page.locator('input[name="password"]').fill(process.env.NC_ADMIN_PASS ?? 'admin')
await page.locator('#submit, button[type="submit"]').first().click()
await page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 30_000 }).catch(() => {})

errors.length = 0 // only judge the app, not the login page

const routes = ['/index.php/apps/scholiq/', '/index.php/apps/scholiq/courses']
for (const r of routes) {
	await page.goto(`${BASE}${r}`, { waitUntil: 'domcontentloaded', timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => {})
	const bodyLen = (await page.innerText('body').catch(() => '')).trim().length
	// Did the SPA actually mount into its own host element?
	const mounted = await page.evaluate(() => {
		const host = document.getElementById('scholiq-app')
		return { hostPresent: !!host, hostChildren: host ? host.children.length : -1 }
	})
	console.log(`[smoke] ${r} bodyTextLen=${bodyLen} host=${JSON.stringify(mounted)}`)
}

await browser.close()

if (errors.length) {
	console.error(`[smoke] FAIL — ${errors.length} fatal error(s):`)
	for (const e of [...new Set(errors)]) console.error('   ' + e)
	process.exit(1)
}
console.log('[smoke] PASS — no uncaught/fatal errors on either route')
