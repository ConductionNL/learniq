import { expect, test } from './fixtures.ts'

/**
 * Page-level smoke tests — navigate to every manifest route and assert that:
 *   - The page loads (HTTP 200, not blank).
 *   - No fatal JS console error blanks the page.
 *
 * Empty-state "No items" / loading spinners are acceptable — we only check that
 * the SPA shell itself didn't crash.
 *
 * Routes taken from src/manifest.json pages[].route.
 * Dynamic segments are replaced with placeholder values that produce a valid
 * URL (the app should show an empty-state or a not-found message, not crash).
 *
 * ⚠️ NO `#` in these paths. The router is history mode (`createWebHistory` in
 * src/main.js), so `#/courses` is a fragment the router never reads, not a
 * route. Every entry here used to carry one, which meant every test in this
 * file loaded the dashboard and asserted the dashboard was fine. The same
 * warning is on course-evaluation.spec.ts; this file was missed because its
 * assertions are weak enough to pass on any page that renders at all.
 *
 * `/settings` was in this table and is not a route — the app has no such page.
 * It is dropped rather than kept as a known failure, because a table of routes
 * is only useful if every entry is one.
 */

const APP_BASE = '/index.php/apps/learniq'

const ROUTES: { name: string; path: string }[] = [
	{ name: 'Dashboard', path: '/' },
	{ name: 'Learning', path: '/learning' },
	{ name: 'People', path: '/people' },
	{ name: 'Courses', path: '/courses' },
	{ name: 'Enrolments', path: '/enrolments' },
	{ name: 'Credentials', path: '/credentials' },
	{ name: 'Compliance', path: '/compliance' },
	{ name: 'CourseDetail', path: '/courses/test-id' },
	{ name: 'LessonIndex', path: '/courses/test-id/lessons' },
	{ name: 'LessonDetail', path: '/courses/test-id/lessons/test-lesson-id' },
	{ name: 'LessonPlayer', path: '/courses/test-id/lessons/test-lesson-id/play' },
	{ name: 'EnrolmentDetail', path: '/enrolments/test-id' },
	{ name: 'BulkEnrol', path: '/enrolments/bulk' },
	{ name: 'Regulations', path: '/compliance/regulations' },
	{ name: 'RegulationDetail', path: '/compliance/regulations/test-slug' },
	{ name: 'Attestations', path: '/compliance/attestations' },
	{ name: 'AttestationDetail', path: '/compliance/attestations/test-id' },
	{ name: 'AuditPackExport', path: '/compliance/export' },
	{ name: 'CredentialDetail', path: '/credentials/test-id' },
	{ name: 'CredentialVerify', path: '/credentials/test-id/verify' },
	{ name: 'LearnerHome', path: '/learner' },
	// @e2e data-exchange::data-exchange-page-remains-routable-via-deep-link
	// Nav entry moved to Admin Settings (relocate-dataexchange-remove-assistant); pages stay routable.
	{ name: 'DataExchangeJobs', path: '/data-exchange/jobs' },
	{ name: 'DataMappingProfiles', path: '/data-exchange/mapping-profiles' },
]

test.describe('Learniq page routes', () => {
	for (const { name, path } of ROUTES) {
		test(`${name} (${path}) loads without fatal error`, async ({
			loggedInPage: page,
		}) => {
			const fatalErrors: string[] = []
			page.on('console', (msg) => {
				if (msg.type() === 'error') {
					const text = msg.text()
					// Exclude known non-fatal errors (network, fonts, missing icons)
					if (
						!text.includes('favicon')
						&& !text.includes('font')
						&& !text.includes('Failed to load resource')
						&& !text.includes('net::ERR_ABORTED')
						&& !text.includes('ERR_CONNECTION_REFUSED')
						&& !text.includes('Failed to fetch')
						&& !text.includes('[FATAL] photos')
						&& !text.includes('Pipelinq')
					) {
						fatalErrors.push(text)
					}
				}
			})

			await page.goto(`${APP_BASE}${path}`)

			// Wait for the page to stabilise
			await page.waitForLoadState('domcontentloaded', { timeout: 15_000 })

			// The route must still be the one we asked for.
			//
			// This is the assertion that makes the rest of the test mean
			// something. vue-router's catch-all rewrites a location it cannot
			// resolve to `/`, so a request that fails to route does not error —
			// it renders the dashboard. Both checks below (non-blank body, no
			// console errors) are then satisfied by the dashboard, for every
			// route in the table, whether or not the page under test exists.
			//
			// Matched WITHOUT the `/index.php` prefix on purpose: Nextcloud
			// serves the app under both forms and redirects to whichever the
			// instance is configured for, so pinning the requested form fails
			// on the redirect rather than on the route.
			await expect(page).toHaveURL(
				new RegExp(
					`${`/apps/learniq${path}`.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}/?$`,
				),
				{ timeout: 10_000 },
			)

			// The page body must not be blank
			const bodyText = await page.innerText('body')
			expect(
				bodyText.trim().length,
				`Page "${name}" body should not be blank`,
			).toBeGreaterThan(0)

			// No fatal JS errors
			expect(
				fatalErrors,
				`Page "${name}" should have no fatal JS errors: ${fatalErrors.join('; ')}`,
			).toHaveLength(0)
		})
	}
})
