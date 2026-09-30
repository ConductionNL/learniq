// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * The four teacher-parent flows of a primary school, end to end.
 *
 * Runs against an instance that has the primary school example set loaded
 * (setup wizard, `po`), portaliq enabled with a portal for the school, and a
 * DigiD broker configured for the portal's organisation that points at this
 * spec's stub broker (helpers/stub-digid.ts). It is skipped unless
 * `PO_FLOW_E2E=1`, so the regular suites never touch a portal they did not
 * set up. Run it with its own config, which has no global setup and loads no
 * other example data:
 *
 *   PO_FLOW_E2E=1 PLAYWRIGHT_BASE_URL=http://localhost:8090 \
 *   PO_FLOW_OIDC_ISSUER=http://host.docker.internal:4180 \
 *   npx playwright test --config tests/e2e/po-flow.config.ts
 *
 * The organisation's broker config (on the Nextcloud host, once):
 *
 *   occ config:app:set portaliq org_presentation_<orgUuid> --value='{"oidc":{"digid":{
 *     "issuer":"<PO_FLOW_OIDC_ISSUER>","clientId":"wilgenboom-portal",
 *     "claimMap":{"audience":"parent"},
 *     "loaMap":{"urn:etoegang:core:assurance-class:loa3":"substantial"}}}}'
 *   occ config:app:set portaliq oidc_secret_<orgUuid>_digid --value=<any> --sensitive
 *
 * Flows:
 *   a. a guardian sees her own child, attendance and report cards, and nothing of another child;
 *   b. she reports her child absent, the group teacher approves it in learniq, she sees the outcome;
 *   c. the school posts news and she reads it (hermiq absent: no translation, no error);
 *   d. the teacher opens a conference round, she books, the schedule is generated,
 *      she sees her time, the teacher records the conversation report.
 *
 * Screenshots of every step land in `test-results/po-flow/`.
 *
 * @spec openspec/changes/portal-guardian-invitation/specs/portal-identity/spec.md
 * @spec openspec/changes/portal-parent-conference-booking/specs/parent-conferences/spec.md
 * @spec openspec/changes/excuse-decision-records-who-and-when/specs/attendance/spec.md
 */

import type { APIRequestContext, Browser, Page } from '@playwright/test'
import type { StubDigid } from './helpers/stub-digid.ts'

import { expect, request, test } from '@playwright/test'
import * as path from 'node:path'
import { baseUrl } from './base-url.ts'
import { ACR_SUBSTANTIAL, startStubDigid } from './helpers/stub-digid.ts'

const ENABLED = process.env.PO_FLOW_E2E === '1'
const SHOTS = path.resolve(__dirname, '..', '..', 'test-results', 'po-flow')

const PORTAL = process.env.PO_FLOW_PORTAL ?? 'wilgenboom'
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
const CHILD = {
	ref: 'ee010008-0000-4000-8000-000000000415',
	name: 'Vera',
	userId: 'po-leerling-147',
}
const OTHER_CHILD = 'ee010008-0000-4000-8000-000000000411'
const GROUP_7 = 'ee010006-0000-4000-8000-000000000006'
const SCHOOL = 'ee010001-0000-4000-8000-000000000001'
const TENANT = '00000000-0000-4000-8000-000000000000'

const RUN = Date.now().toString(36)

test.describe.configure({ mode: 'serial' })

test.describe('po: teacher and parent flows', () => {
	test.skip(
		!ENABLED,
		'set PO_FLOW_E2E=1 on an instance prepared as the file header describes',
	)
	test.skip(
		ENABLED && ISSUER === '',
		'set PO_FLOW_OIDC_ISSUER to the issuer the organisation is configured with',
	)

	let stub: StubDigid
	let parent: Page
	let token = ''
	let admin: APIRequestContext
	let teacher: APIRequestContext

	test.beforeAll(async ({ browser }) => {
		stub = await startStubDigid(
			ISSUER,
			CLIENT_ID,
			path.join(SHOTS, 'stub-key.pem'),
		)
		admin = await ocs(ADMIN)
		teacher = await ocs(TEACHER)

		// The school links the guardian to the portal (portal-guardian-invitation).
		const invite = await admin.post(
			`/apps/learniq/api/portal/guardians/${GUARDIAN.ref}/invite`,
			{
				data: { email: GUARDIAN.email, organisation: ORGANISATION },
			},
		)
		expect(invite.status(), await invite.text()).toBe(200)

		parent = await signInAsGuardian(browser)
	})

	test.afterAll(async () => {
		await stub?.close()
	})

	test('a. the guardian sees her own child and nothing of another child', async () => {
		await shot(parent, 'a1-portal-home')
		await openTab(parent, 'My children')
		await expect(
			parent.getByText(CHILD.name, { exact: true }).first(),
		).toBeVisible()
		await shot(parent, 'a2-my-children')
		await openTab(parent, "My child's attendance")
		await shot(parent, 'a3-attendance')
		await openTab(parent, "My child's report cards")
		await shot(parent, 'a4-report-cards')

		const children = await portalRows('learner-profile', 'parentChildren')
		expect(children.map((row) => row.id)).toEqual([CHILD.ref])

		const attendance = await portalRows('attendance-record', 'parentAttendance')
		expect(attendance.length).toBeGreaterThan(0)
		expect(new Set(attendance.map((row) => row.learnerRef))).toEqual(
			new Set([CHILD.ref]),
		)

		const reportCards = await portalRows('report-card', 'parentReportCards')
		expect(reportCards.length).toBeGreaterThan(0)
		expect(new Set(reportCards.map((row) => row.learnerRef))).toEqual(
			new Set([CHILD.ref]),
		)

		const foreign = await parent.request.get(
			`/apps/portaliq/portal/api/collections/learniq/learner-profile/${OTHER_CHILD}?collection=parentChildren`,
			{ headers: bearer() },
		)
		expect(foreign.status()).toBe(404)
	})

	test('b. an absence report goes from the guardian to the teacher and back', async ({
		browser,
	}) => {
		const reason = `Koorts (${RUN})`
		await openTab(parent, "My child's absence excuses")
		await parent
			.locator('select#f-createExcuseRequest-learnerRef')
			.selectOption(CHILD.ref)
		await parent.locator('#f-createExcuseRequest-dateFrom').fill('2026-10-01')
		await parent.locator('#f-createExcuseRequest-dateTo').fill('2026-10-01')
		await parent.locator('#f-createExcuseRequest-reason').fill(reason)
		await parent.locator('#f-createExcuseRequest-reasonKind').fill('illness')
		await shot(parent, 'b1-absence-form')
		await parent.getByRole('button', { name: 'Report the absence' }).click()
		await expect
			.poll(async () => (await excuseFor(reason))?.lifecycle, {
				timeout: 15_000,
			})
			.toBe('submitted')
		await shot(parent, 'b2-absence-sent')

		// A child that is not hers is refused by portaliq before it is stored.
		const refused = await parent.request.post(
			'/apps/portaliq/portal/api/collections/learniq/excuse-request',
			{
				headers: bearer(),
				data: {
					learnerRef: OTHER_CHILD,
					dateFrom: '2026-10-01',
					dateTo: '2026-10-01',
					reason,
					reasonKind: 'illness',
				},
			},
		)
		expect(refused.status()).toBe(403)

		const excuse = await excuseFor(reason)
		const staff = await signInToNextcloud(browser, TEACHER)
		await staff.goto(`/index.php/apps/learniq/attendance/excuses/${excuse!.id}`)
		await dismissTour(staff)
		await shot(staff, 'b3-teacher-sees-report')
		await staff.getByRole('button', { name: 'Approve' }).click()
		await expect(staff.getByText('approved').first()).toBeVisible({
			timeout: 15_000,
		})
		await shot(staff, 'b4-teacher-approved')
		await staff.close()

		const decided = await excuseFor(reason)
		expect(decided?.lifecycle).toBe('approved')
		expect(decided?.decidedAt).toBeTruthy()
		await parent.reload()
		await openTab(parent, "My child's absence excuses")
		await shot(parent, 'b5-guardian-sees-outcome')
	})

	test('c. school news reaches the guardian, untranslated without hermiq', async () => {
		const title = `Studiedag vrijdag 9 oktober (${RUN})`
		const created = await teacher.post('/apps/portaliq/api/news', {
			data: {
				title,
				body: 'Op vrijdag 9 oktober zijn alle groepen vrij vanwege een studiedag.',
				target: { schoolRef: SCHOOL },
				authorRef: TEACHER.user,
			},
		})
		expect(created.status(), await created.text()).toBe(200)
		const published = await teacher.put(
			`/apps/portaliq/api/news/${(await created.json()).id}/publish`,
		)
		expect(published.status()).toBe(200)

		await parent.reload()
		await openTab(parent, 'News')
		await expect(parent.getByText(title)).toBeVisible()
		await shot(parent, 'c1-news')

		const feed = await parent.request.get(
			'/apps/portaliq/api/news/feed?language=en',
			{ headers: bearer() },
		)
		expect(feed.status()).toBe(200)
		const item = (await feed.json()).find(
			(row: { title: string }) => row.title === title,
		)
		expect(item).toBeTruthy()
		expect(item.translation).toBeUndefined()
	})

	test('d. a parent-teacher conference is booked, planned and recorded', async ({
		browser,
	}) => {
		const round = await teacherCreate('conference-round', {
			name: `Oudergesprekken groep 7 (${RUN})`,
			cohortIds: [GROUP_7],
			teacherIds: [TEACHER.user],
			slotDurationMinutes: 10,
			bufferMinutes: 2,
			bookingOpensAt: '2026-09-30T08:00:00+02:00',
			bookingClosesAt: '2026-10-06T17:00:00+02:00',
			tenant_id: TENANT,
		})
		expect(await transition(round.id, 'send-invitations')).toBe(
			'invitations-sent',
		)
		expect(await transition(round.id, 'open-booking')).toBe('booking-open')
		const availability = await teacherCreate('teacher-availability', {
			conferenceRoundId: round.id,
			teacherId: TEACHER.user,
			blocks: [
				{
					startsAt: '2026-10-08T18:00:00+02:00',
					endsAt: '2026-10-08T20:00:00+02:00',
				},
			],
			tenant_id: TENANT,
		})
		expect(await transition(availability.id, 'submit')).toBe('submitted')

		const staff = await signInToNextcloud(browser, TEACHER)
		await staff.goto('/index.php/apps/learniq/conferences/rounds')
		await dismissTour(staff)
		await shot(staff, 'd1-teacher-rounds')

		await parent.reload()
		await openTab(parent, 'Your conference bookings')
		await parent
			.locator('select#f-createConferenceSignup-conferenceRoundId')
			.selectOption(round.id)
		await parent
			.locator('select#f-createConferenceSignup-learnerRef')
			.selectOption(CHILD.ref)
		await parent
			.locator('#f-createConferenceSignup-notes')
			.fill('Graag over rekenen praten')
		await shot(parent, 'd2-booking-form')
		await parent.getByRole('button', { name: 'Book', exact: true }).click()
		await expect
			.poll(
				async () =>
					(
						await portalRows(
							'conference-signup',
							'parentConferenceSignups',
						)
					)
						.filter((row) => row.conferenceRoundId === round.id)
						.map((row) => row.lifecycle),
				{ timeout: 15_000 },
			)
			.toEqual(['submitted'])
		await shot(parent, 'd3-booked')

		expect(await transition(round.id, 'close-booking')).toBe('booking-closed')
		expect(await transition(round.id, 'generate')).toBe('scheduled')

		// This round's slot, read as the teacher and filtered by the round: the
		// parent's rows carry no round id, so taking her first proposed slot
		// picked up a slot an earlier run had left behind and still passed.
		let slot: Record<string, string> | undefined
		await expect
			.poll(
				async () => {
					const res = await teacher.get(
						`/apps/openregister/api/objects/learniq/conference-slot?conferenceRoundId=${round.id}`,
					)
					slot = ((await res.json()).results ?? []).find(
						(row: Record<string, string>) =>
							row.teacherId === TEACHER.user
							&& row.lifecycle === 'proposed',
					)
					if (!slot) {
						return undefined
					}
					// The parent sees that same slot through her own scoped read.
					const hers = (
						await portalRows('conference-slot', 'parentConferenceSlots')
					).some(
						(row) =>
							(row.id ?? row['@self']?.id) === slot!.id
							|| (row.startsAt === slot!.startsAt
								&& row.lifecycle === 'proposed'),
					)
					return hers ? slot.startsAt?.slice(0, 10) : 'not in her rows'
				},
				{ timeout: 15_000 },
			)
			.toBe('2026-10-08')
		await parent.reload()
		await openTab(parent, 'Your conference times')
		await shot(parent, 'd4-guardian-sees-time')

		await staff.goto('/index.php/apps/learniq/conferences/slots')
		await dismissTour(staff)
		await shot(staff, 'd5-teacher-sees-slots')
		expect(await transition(slot!.id, 'confirm')).toBe('confirmed')
		expect(await transition(slot!.id, 'complete')).toBe('completed')

		const report = await teacherCreate('conference-report', {
			conferenceSlotId: slot!.id,
			learnerId: CHILD.userId,
			learnerRef: CHILD.ref,
			teacherId: TEACHER.user,
			recordedBy: TEACHER.user,
			recordedAt: '2026-10-08T18:12:00+02:00',
			narrative:
				'Vera gaat goed vooruit met rekenen. Afgesproken: thuis de tafels oefenen.',
			attendeeIds: [TEACHER.user],
			tenant_id: TENANT,
		})
		expect(await transition(report.id, 'record')).toBe('recorded')
		await staff.goto(`/index.php/apps/learniq/conferences/reports/${report.id}`)
		await dismissTour(staff)
		await shot(staff, 'd6-teacher-report')
		await staff.close()
	})

	/**
	 * Sign the guardian in to the portal with the stub DigiD broker.
	 *
	 * @param {Browser} browser The browser.
	 * @return {Promise<Page>} The guardian's signed-in portal page.
	 */
	async function signInAsGuardian(browser: Browser): Promise<Page> {
		stub.nextLogin({
			sub: GUARDIAN.sub,
			email: GUARDIAN.email,
			acr: ACR_SUBSTANTIAL,
		})
		const page = await (
			await browser.newContext({ storageState: undefined })
		).newPage()
		await page.goto(`/apps/portaliq/portal?portal=${PORTAL}`)
		await shot(page, 'a0-portal-login')
		await page.getByRole('button', { name: /DigiD/ }).click()
		await expect
			.poll(
				async () =>
					await page.evaluate(() =>
						localStorage.getItem('portaliq_token'),
					),
				{ timeout: 20_000 },
			)
			.toBeTruthy()
		token =
			(await page.evaluate(() => localStorage.getItem('portaliq_token'))) ?? ''
		const session = await page.request.get('/apps/portaliq/portal/api/session', {
			headers: bearer(),
		})
		expect((await session.json()).trust).toBe('substantial')
		return page
	}

	/**
	 * The guardian's rows of one portal collection.
	 *
	 * @param {string} schema The learniq schema.
	 * @param {string} collection The collection id.
	 * @return {Promise<Array<Record<string, any>>>} The rows.
	 */
	async function portalRows(
		schema: string,
		collection: string,
	): Promise<Array<Record<string, any>>> {
		const res = await parent.request.get(
			`/apps/portaliq/portal/api/collections/learniq/${schema}?collection=${collection}`,
			{ headers: bearer() },
		)
		expect(res.status()).toBe(200)
		return (await res.json()).objects ?? []
	}

	/**
	 * The guardian's absence report with this reason, if any.
	 *
	 * @param {string} reason The unique reason text.
	 * @return {Promise<Record<string, any>|undefined>} The report.
	 */
	async function excuseFor(
		reason: string,
	): Promise<Record<string, any> | undefined> {
		return (await portalRows('excuse-request', 'parentExcuseRequests')).find(
			(row) => row.reason === reason,
		)
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
	 * Create a learniq object as the teacher.
	 *
	 * @param {string} schema The schema.
	 * @param {object} data The object.
	 * @return {Promise<Record<string, any>>} The created object.
	 */
	async function teacherCreate(
		schema: string,
		data: Record<string, unknown>,
	): Promise<Record<string, any>> {
		const res = await teacher.post(
			`/apps/openregister/api/objects/learniq/${schema}`,
			{ data },
		)
		expect(res.status(), await res.text()).toBeLessThan(300)
		return await res.json()
	}

	/**
	 * Run a lifecycle transition as the teacher; answers the new state.
	 *
	 * @param {string} id The object id.
	 * @param {string} action The transition.
	 * @return {Promise<string>} The lifecycle after the move.
	 */
	async function transition(id: string, action: string): Promise<string> {
		const res = await teacher.post(
			`/apps/openregister/api/objects/${id}/transition`,
			{ data: { action } },
		)
		const body = await res.json()
		return (
			body.lifecycle
			?? body.object?.lifecycle
			?? JSON.stringify(body).slice(0, 200)
		)
	}
})

/**
 * An OCS API context signed in with basic auth.
 *
 * @param {{user: string, pass: string}} who The account.
 * @return {Promise<APIRequestContext>} The context.
 */
async function ocs(who: { user: string; pass: string }): Promise<APIRequestContext> {
	return await request.newContext({
		baseURL: baseUrl(),
		extraHTTPHeaders: {
			'OCS-APIRequest': 'true',
			Authorization: `Basic ${Buffer.from(`${who.user}:${who.pass}`).toString('base64')}`,
		},
	})
}

/**
 * A page signed in to Nextcloud as the given account.
 *
 * @param {Browser} browser The browser.
 * @param {{user: string, pass: string}} who The account.
 * @return {Promise<Page>} The page.
 */
async function signInToNextcloud(
	browser: Browser,
	who: { user: string; pass: string },
): Promise<Page> {
	const page = await (
		await browser.newContext({
			storageState: undefined,
			viewport: { width: 1400, height: 1000 },
		})
	).newPage()
	await page.goto('/index.php/login')
	await page.locator('input[name="user"]').fill(who.user)
	await page.locator('input[name="password"]').fill(who.pass)
	await page.locator('button[type="submit"]').click()
	await page.waitForURL((url) => !url.pathname.includes('/login'))
	return page
}

/**
 * Close the first-visit walkthrough if it is open.
 *
 * @param {Page} page The page.
 * @return {Promise<void>}
 */
async function dismissTour(page: Page): Promise<void> {
	await page
		.waitForLoadState('networkidle', { timeout: 10_000 })
		.catch(() => undefined)
	await page.waitForTimeout(3_000)
	const close = page.locator('.cn-walkthrough button[aria-label]').first()
	if (await close.isVisible({ timeout: 5_000 }).catch(() => false)) {
		await close.click()
	}
}

/**
 * Open a portal navigation entry by its label.
 *
 * @param {Page} page The portal page.
 * @param {string} label The entry label.
 * @return {Promise<void>}
 */
async function openTab(page: Page, label: string): Promise<void> {
	await page.getByRole('button', { name: label, exact: true }).first().click()
	await page
		.waitForLoadState('networkidle', { timeout: 10_000 })
		.catch(() => undefined)
}

/**
 * Save a full-page screenshot under test-results/po-flow/.
 *
 * @param {Page} page The page.
 * @param {string} name The file name without extension.
 * @return {Promise<void>}
 */
async function shot(page: Page, name: string): Promise<void> {
	await page.screenshot({
		path: path.join(SHOTS, `${name}.png`),
		fullPage: page.url().includes('/apps/portaliq/'),
		timeout: 20_000,
	})
}
