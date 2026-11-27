// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * The published report card goes home as a PDF (report-card-pdf-to-guardians).
 *
 * Runs on the instance po-parent-flows.spec.ts runs on (primary school example
 * set, portaliq with the school's portal, the stub DigiD broker; see that
 * file's header), with filinq installed and rendering. Skipped unless
 * `PO_FLOW_E2E=1`, and skipped with the reason when filinq is not enabled:
 *
 *   PO_FLOW_E2E=1 PLAYWRIGHT_BASE_URL=<instance> \
 *   PO_FLOW_OIDC_ISSUER=<issuer the organisation is configured with> \
 *   npx playwright test --config tests/e2e/po-flow.config.ts report-card-pdf
 *
 * WHY THE GUARDIAN SIGNS IN THROUGH THE STUB BROKER. The task names the
 * portal's `nextcloud` mode, but a password sign-in reaches trust `low` and
 * `parentReportCards` declares `minTrust: substantial`, so a guardian signed
 * in that way is refused the collection before any file is looked at. The
 * stub DigiD broker gives her `substantial`, as it does in po-parent-flows.
 *
 * Flow:
 *   1. a teacher takes a new report card of Vera to finalised and publishes
 *      it; filinq renders it and the card carries one PDF, which the staff
 *      page lists;
 *   2. the guardian downloads that PDF on the portal;
 *   3. the same request for another child's report card answers 404;
 *   4. "Render the PDF again" leaves the card with one PDF, the new one.
 *
 * @spec openspec/specs/report-card/spec.md
 */

import type { APIRequestContext, Browser, Page } from '@playwright/test'
import type { StubDigid } from './helpers/stub-digid.ts'

import { expect, request, test } from '@playwright/test'
import * as path from 'node:path'
import { baseUrl } from './base-url.ts'
import { ACR_SUBSTANTIAL, startStubDigid } from './helpers/stub-digid.ts'

const ENABLED = process.env.PO_FLOW_E2E === '1'
const SHOTS = path.resolve(__dirname, '..', '..', 'test-results', 'report-card-pdf')

const PORTAL = process.env.PO_FLOW_PORTAL ?? 'wilgenboom'
const SITE = `/apps/portaliq/site?portal=${encodeURIComponent(PORTAL)}`
const SITE_TOKEN_KEY = 'portaliq.session.token'
const ORGANISATION = process.env.PO_FLOW_ORGANISATION ?? 'default-organisation'
const ISSUER = process.env.PO_FLOW_OIDC_ISSUER ?? ''
const CLIENT_ID = process.env.PO_FLOW_OIDC_CLIENT ?? 'wilgenboom-portal'
const ADMIN = {
	user: process.env.NC_ADMIN_USER ?? 'admin',
	pass: process.env.NC_ADMIN_PASS ?? 'admin',
}
const TEACHER = {
	user: process.env.PO_FLOW_TEACHER ?? 'po-leerkracht-09',
	pass: process.env.PO_FLOW_TEACHER_PASS ?? 'Wilgenboom-2026!',
}

// The po example set's fixed uuids (lib/Settings/profiles/po.json).
const GUARDIAN = {
	ref: 'ee010008-0000-4000-8000-000000000009',
	sub: 'digid-fatima-hulstkamp',
	email: 'fatima.hulstkamp@example.org',
}
const CHILD = { ref: 'ee010008-0000-4000-8000-000000000415', userId: 'po-leerling-147' }
// A published report card of a child who is not hers.
const OTHER_CHILDS_CARD = 'ee010013-0000-4000-8000-000000000143'
const GROUP_7 = 'ee010006-0000-4000-8000-000000000006'
const REPORT_PERIOD_1 = 'ee01000b-0000-4000-8000-000000000001'
const TENANT = '00000000-0000-4000-8000-000000000000'

const RUN = Date.now().toString(36)
const RENDER_TIMEOUT = 120_000

test.describe.configure({ mode: 'serial' })

test.describe('report card PDF to guardians', () => {
	test.skip(!ENABLED, 'set PO_FLOW_E2E=1 on an instance prepared as po-parent-flows.spec.ts describes')
	test.skip(ENABLED && ISSUER === '', 'set PO_FLOW_OIDC_ISSUER to the issuer the organisation is configured with')

	let stub: StubDigid
	let admin: APIRequestContext
	let teacher: APIRequestContext
	let parent: Page
	let token = ''
	let cardId = ''
	let firstFile: Record<string, any> = {}

	test.beforeAll(async ({ browser }) => {
		test.setTimeout(300_000)
		admin = await basic(ADMIN)
		teacher = await basic(TEACHER)

		const filinq = await admin.get('/ocs/v2.php/cloud/apps?filter=enabled&format=json')
		const enabled: string[] = (await filinq.json().catch(() => ({})))?.ocs?.data?.apps ?? []
		test.skip(!enabled.includes('filinq'), 'filinq is not enabled on this instance, so no PDF can be rendered')

		stub = await startStubDigid(ISSUER, CLIENT_ID, path.join(SHOTS, 'stub-key.pem'))
		const invite = await admin.post(`/apps/learniq/api/portal/guardians/${GUARDIAN.ref}/invite`, {
			data: { email: GUARDIAN.email, organisation: ORGANISATION },
		})
		expect(invite.status(), await invite.text()).toBe(200)
		parent = await signInAsGuardian(browser)
	})

	test.afterAll(async () => {
		if (cardId !== '') {
			const res = await admin
				.delete(`/apps/openregister/api/objects/learniq/report-card/${cardId}`)
				.catch(() => null)
			if (!res || res.status() >= 300) {
				console.warn(`report-card-pdf cleanup could not remove report-card/${cardId}`)
			}
		}
		await stub?.close()
	})

	test('1. publishing a finalised report card renders its PDF onto the card @e2e', async ({ browser }) => {
		test.setTimeout(RENDER_TIMEOUT + 60_000)
		const created = await teacher.post('/apps/openregister/api/objects/learniq/report-card', {
			data: {
				learnerId: CHILD.userId,
				reportPeriodId: REPORT_PERIOD_1,
				cohortId: GROUP_7,
				subjectGrades: [],
				mentorComment: `Rapport met PDF (${RUN})`,
				tenant_id: TENANT,
			},
		})
		expect(created.status(), await created.text()).toBeLessThan(300)
		const card = await created.json()
		cardId = String(card.id ?? card.uuid ?? '')
		expect(cardId).not.toBe('')

		expect(await transition(cardId, 'pullIntoReview')).toBe('rapportvergadering-review')
		expect(await transition(cardId, 'finalise')).toBe('finalised')
		expect(await transition(cardId, 'publishToParents')).toBe('published-to-parents')

		await expect
			.poll(async () => (await readCard(cardId)).docudeskRenderStatus, { timeout: RENDER_TIMEOUT })
			.toBe('rendered')

		const files = await cardFiles(cardId)
		expect(files).toHaveLength(1)
		expect(String(files[0].name ?? files[0].title ?? '')).toMatch(/\.pdf$/i)
		firstFile = files[0]

		// The staff page lists it in the "Report card PDF" widget.
		const staff = await signInToNextcloud(browser, TEACHER)
		await staff.goto(`/index.php/apps/learniq/report-cards/${cardId}`)
		await expect(
			staff.getByText(String(firstFile.name ?? firstFile.title), { exact: false }).first(),
		).toBeVisible({ timeout: 30_000 })
		await staff.screenshot({ path: path.join(SHOTS, '1-staff-card.png'), fullPage: true })
		await staff.context().close()
	})

	test("2. the guardian downloads her child's report card as a PDF @e2e", async () => {
		expect(cardId, 'flow 1 published no card').not.toBe('')
		const res = await parent.request.get(
			`/apps/portaliq/portal/api/collections/learniq/report-card/${cardId}`,
			{ headers: bearer() },
		)
		expect(res.status(), await res.text()).toBe(200)
		const listed: Array<Record<string, any>> = (await res.json()).object?._files ?? []
		expect(listed).toHaveLength(1)

		const download = await parent.request.get(
			`/apps/portaliq/portal/api/collections/learniq/report-card/${cardId}/files/${listed[0].id}`,
			{ headers: bearer() },
		)
		expect(download.status()).toBe(200)
		expect(download.headers()['content-type'] ?? '').toContain('application/pdf')
		expect((await download.body()).subarray(0, 5).toString('latin1')).toBe('%PDF-')
	})

	test("3. another child's report card file answers 404 @e2e", async () => {
		expect(cardId, 'flow 1 published no card').not.toBe('')
		const own = await parent.request.get(
			`/apps/portaliq/portal/api/collections/learniq/report-card/${cardId}`,
			{ headers: bearer() },
		)
		const fileId = String((await own.json()).object?._files?.[0]?.id ?? '')
		expect(fileId).not.toBe('')

		// Her own file asked for under another child's card, and that card's own
		// first file: both the same 404, so neither says the card exists.
		const otherFiles = await cardFiles(OTHER_CHILDS_CARD)
		const otherFileId = String(otherFiles[0]?.id ?? fileId)
		for (const id of [fileId, otherFileId]) {
			const res = await parent.request.get(
				`/apps/portaliq/portal/api/collections/learniq/report-card/${OTHER_CHILDS_CARD}/files/${id}`,
				{ headers: bearer() },
			)
			expect(res.status()).toBe(404)
		}
	})

	test('4. "Render the PDF again" leaves one PDF, the new one @e2e', async () => {
		test.setTimeout(RENDER_TIMEOUT + 30_000)
		expect(cardId, 'flow 1 published no card').not.toBe('')
		const before = String((await readCard(cardId)).docudeskRequestedAt ?? '')

		expect(await transition(cardId, 'rerenderToPdf')).toBe('published-to-parents')
		await expect
			.poll(
				async () => {
					const card = await readCard(cardId)
					return card.docudeskRenderStatus === 'rendered' && String(card.docudeskRequestedAt ?? '') !== before
				},
				{ timeout: RENDER_TIMEOUT },
			)
			.toBe(true)

		const files = await cardFiles(cardId)
		expect(files).toHaveLength(1)
		expect(String(files[0].id)).not.toBe(String(firstFile.id))
	})

	/**
	 * Sign the guardian in to the site through the stub DigiD broker.
	 *
	 * @param {Browser} browser The browser.
	 * @return {Promise<Page>} The signed-in site page.
	 */
	async function signInAsGuardian(browser: Browser): Promise<Page> {
		const page = await (await browser.newContext({ storageState: undefined, locale: 'nl-NL' })).newPage()
		// The forwarded stub port can be briefly unreachable; see po-parent-flows.
		for (let attempt = 1; ; attempt++) {
			stub.nextLogin({ sub: GUARDIAN.sub, email: GUARDIAN.email, acr: ACR_SUBSTANTIAL })
			await page.goto(SITE)
			await page.getByTestId('site-account-signin').waitFor({ timeout: 15_000 })
			await page.getByTestId('site-account-signin-route').first().click()
			const landed = await page
				.waitForURL(/\/apps\/portaliq\/site[^#]*route=%2Fmijn/, { timeout: 15_000 })
				.then(() => true)
				.catch(() => false)
			if (landed) {
				break
			}
			expect(attempt, `sign-in did not return to the site: ${page.url()}`).toBeLessThan(8)
			await page.waitForTimeout(5_000)
		}
		const readToken = async () => await page.evaluate((key) => sessionStorage.getItem(key), SITE_TOKEN_KEY)
		await expect.poll(readToken, { timeout: 20_000 }).toBeTruthy()
		token = (await readToken()) ?? ''
		const session = await page.request.get('/apps/portaliq/portal/api/session', { headers: bearer() })
		expect((await session.json()).trust).toBe('substantial')
		return page
	}

	/**
	 * The guardian's bearer header.
	 *
	 * @return {Record<string, string>} The header.
	 */
	function bearer(): Record<string, string> {
		return { Authorization: `Bearer ${token}` }
	}

	/**
	 * Run a lifecycle transition as the teacher; answers the new state.
	 *
	 * @param {string} id The report card.
	 * @param {string} action The transition.
	 * @return {Promise<string>} The lifecycle after the move.
	 */
	async function transition(id: string, action: string): Promise<string> {
		const res = await teacher.post(`/apps/openregister/api/objects/${id}/transition`, { data: { action } })
		const body = await res.json().catch(() => ({}))
		return body.lifecycle ?? body.object?.lifecycle ?? `${res.status()} ${JSON.stringify(body).slice(0, 200)}`
	}

	/**
	 * The report card as the admin reads it.
	 *
	 * @param {string} id The report card.
	 * @return {Promise<Record<string, any>>} The card.
	 */
	async function readCard(id: string): Promise<Record<string, any>> {
		const res = await admin.get(`/apps/openregister/api/objects/learniq/report-card/${id}`)
		expect(res.status(), await res.text()).toBe(200)
		return await res.json()
	}

	/**
	 * The files OpenRegister holds on a report card.
	 *
	 * @param {string} id The report card.
	 * @return {Promise<Array<Record<string, any>>>} The files.
	 */
	async function cardFiles(id: string): Promise<Array<Record<string, any>>> {
		const res = await admin.get(`/apps/openregister/api/objects/learniq/report-card/${id}/files`)
		expect(res.status(), await res.text()).toBe(200)
		const body = await res.json()
		return Array.isArray(body) ? body : (body.results ?? [])
	}
})

/**
 * An API context signed in with basic auth.
 *
 * @param {{user: string, pass: string}} who The account.
 * @return {Promise<APIRequestContext>} The context.
 */
async function basic(who: { user: string; pass: string }): Promise<APIRequestContext> {
	return await request.newContext({
		baseURL: baseUrl(),
		extraHTTPHeaders: {
			'OCS-APIRequest': 'true',
			Authorization: `Basic ${Buffer.from(`${who.user}:${who.pass}`).toString('base64')}`,
		},
	})
}

/**
 * A page signed in to Nextcloud as the given account, in a fresh context.
 *
 * @param {Browser} browser The browser.
 * @param {{user: string, pass: string}} who The account.
 * @return {Promise<Page>} The page.
 */
async function signInToNextcloud(browser: Browser, who: { user: string; pass: string }): Promise<Page> {
	const page = await (
		await browser.newContext({ storageState: undefined, viewport: { width: 1400, height: 1000 } })
	).newPage()
	await page.goto('/index.php/login')
	await page.locator('input[name="user"]').fill(who.user)
	await page.locator('input[name="password"]').fill(who.pass)
	await page.locator('button[type="submit"]').click()
	await page.waitForURL((url) => !url.pathname.includes('/login'))
	return page
}
