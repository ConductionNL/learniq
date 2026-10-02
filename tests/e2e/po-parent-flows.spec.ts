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
 *      she sees her time, the teacher records the conversation report (a round
 *      the school plans: bookingMode preference);
 *   d2. direct booking: the teacher creates free times, she picks one on the site,
 *      a second booking of that time is refused, the teacher acknowledges it and
 *      she sees "acknowledged";
 *   e. she reads the grades on her child's published report cards, and a draft
 *      report card the teacher starts never reaches her.
 *
 * Every page the guardian sees is the Vue site (`/apps/portaliq/site`), on its
 * signed-in routes (`&route=/mijn/learniq/<collection>` and the shell's own
 * sections such as `/mijn/news`). The portal API under
 * `/apps/portaliq/portal/api/*` stays the data source the assertions read.
 * The guardian fills the absence and booking forms on the site (b0, d0) and
 * reads the news on the site's news page (c1). Flows b and d also write
 * through the portal API, so the teacher's half of each flow runs on its own
 * row.
 *
 * Screenshots of every step land in `test-results/po-flow/`.
 *
 * @spec openspec/changes/portal-guardian-invitation/specs/portal-identity/spec.md
 * @spec openspec/changes/portal-parent-conference-booking/specs/parent-conferences/spec.md
 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
 * @spec openspec/changes/direct-conference-booking/specs/portal-contribution/spec.md
 * @spec openspec/changes/excuse-decision-records-who-and-when/specs/attendance/spec.md
 * @spec openspec/changes/portal-parent-report-card-grades/specs/portal-contribution/spec.md
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
const SITE_TOKEN_KEY = 'portaliq.session.token'
const SITE = `/apps/portaliq/site?portal=${encodeURIComponent(PORTAL)}`
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
const REPORT_PERIOD_1 = 'ee01000b-0000-4000-8000-000000000001'
const SCHOOL = 'ee010001-0000-4000-8000-000000000001'
const TENANT = '00000000-0000-4000-8000-000000000000'

const RUN = Date.now().toString(36)
const NEWS_TITLE = `Studiedag vrijdag 9 oktober (${RUN})`

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
		// Room for the sign-in's retries (see signInAsGuardian).
		test.setTimeout(300_000)
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
		await openPage(parent, 'learniq/parentChildren')
		await expect(
			parent.getByText(CHILD.name, { exact: true }).first(),
		).toBeVisible()
		await shot(parent, 'a2-my-children')
		await openPage(parent, 'learniq/parentAttendance')
		await shot(parent, 'a3-attendance')
		await openPage(parent, 'learniq/parentReportCards')
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

	test('b0. the guardian reports the absence through the form on the site', async () => {
		const reason = `Koorts, formulier (${RUN})`
		await openPage(parent, 'learniq/parentExcuseRequests')
		await expect(
			parent.getByRole('heading', {
				level: 1,
				name: 'Afwezigheidsmeldingen van mijn kind',
			}),
		).toBeVisible()
		const form = parent.getByRole('form', {
			name: 'Afwezigheid van uw kind melden',
		})
		await form
			.getByRole('combobox', { name: 'Kind', exact: true })
			.selectOption({ label: CHILD.name })
		await form.getByLabel('Eerste dag afwezig').fill('2026-10-01')
		await form.getByLabel('Laatste dag afwezig').fill('2026-10-01')
		await form.getByRole('textbox', { name: 'Reden', exact: true }).fill(reason)
		await form
			.getByRole('combobox', { name: 'Soort afwezigheid' })
			.selectOption('illness')
		await shot(parent, 'b0-absence-form')
		await form.getByRole('button', { name: 'Afwezigheid melden' }).click()
		await expect
			.poll(async () => (await excuseFor(reason))?.lifecycle, {
				timeout: 15_000,
			})
			.toBe('submitted')
		const sent = await excuseFor(reason)
		expect(sent?.learnerRef).toBe(CHILD.ref)
		expect(sent?.reasonKind).toBe('illness')
		await expect(
			parent.getByRole('cell', { name: reason, exact: true }),
		).toBeVisible({ timeout: 15_000 })
		await shot(parent, 'b0-absence-form-sent')
	})

	test('b. an absence report goes from the guardian to the teacher and back', async ({
		browser,
	}) => {
		const reason = `Koorts (${RUN})`
		// The form on the site is b0's step; the same report goes through the
		// portal API here, with the guardian's own token.
		const sent = await parent.request.post(
			'/apps/portaliq/portal/api/collections/learniq/excuse-request',
			{
				headers: bearer(),
				data: {
					learnerRef: CHILD.ref,
					dateFrom: '2026-10-01',
					dateTo: '2026-10-01',
					reason,
					reasonKind: 'illness',
				},
			},
		)
		expect(sent.status(), await sent.text()).toBeLessThan(300)
		await expect
			.poll(async () => (await excuseFor(reason))?.lifecycle, {
				timeout: 15_000,
			})
			.toBe('submitted')
		await openPage(parent, 'learniq/parentExcuseRequests')
		await expect(parent.getByText(reason).first()).toBeVisible()
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
		// The lifecycle actions sit in the page's Actions menu (learniq#1595).
		await staff.getByRole('button', { name: 'Actions' }).first().click()
		await staff.getByRole('menuitem', { name: 'Approve' }).click()
		// The detail page no longer prints the lifecycle; the record says it.
		await expect
			.poll(async () => (await excuseFor(reason))?.lifecycle, {
				timeout: 15_000,
			})
			.toBe('approved')
		await shot(staff, 'b4-teacher-approved')
		await staff.close()

		const decided = await excuseFor(reason)
		expect(decided?.lifecycle).toBe('approved')
		expect(decided?.decidedAt).toBeTruthy()
		await openPage(parent, 'learniq/parentExcuseRequests')
		await expect(parent.getByText(reason).first()).toBeVisible()
		await shot(parent, 'b5-guardian-sees-outcome')
	})

	test('c. school news reaches the guardian, untranslated without hermiq', async () => {
		const title = NEWS_TITLE
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

	test('c1. the guardian reads the news on the site', async () => {
		await openPage(parent, 'news')
		await expect(
			parent.getByRole('heading', { level: 1, name: 'Nieuws' }),
		).toBeVisible()
		await expect(
			parent.getByRole('heading', { name: NEWS_TITLE, exact: true }),
		).toBeVisible({ timeout: 15_000 })
		await shot(parent, 'c1-news')
	})

	test('d0. the guardian books a conference through the form on the site', async () => {
		const name = `Oudergesprekken, formulier (${RUN})`
		const note = `Graag over lezen praten (${RUN})`
		const round = await openBookingRound(name)
		await openPage(parent, 'learniq/parentConferenceSignups')
		const form = parent.getByRole('form', { name: 'Oudergesprek aanvragen' })
		await form
			.getByRole('combobox', { name: 'Oudergespreksronde' })
			.selectOption({ label: name })
		await form
			.getByRole('combobox', { name: 'Kind', exact: true })
			.selectOption({ label: CHILD.name })
		await form.getByLabel('Wat de leerkracht vooraf moet weten').fill(note)
		await shot(parent, 'd0-booking-form')
		await form.getByRole('button', { name: 'Boeken', exact: true }).click()
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
		await expect(
			parent.getByRole('cell', { name: note, exact: true }),
		).toBeVisible({ timeout: 15_000 })
		await shot(parent, 'd0-booking-form-sent')
		// Close this round, so it does not stay open for the next run.
		expect(await transition(round.id, 'close-booking')).toBe('booking-closed')
	})

	test('d. a parent-teacher conference is booked, planned and recorded', async ({
		browser,
	}) => {
		// Two teacher sessions and five staff pages: on a loaded instance this
		// flow alone measured 3.1 minutes (2026-10-02).
		test.setTimeout(300_000)
		const round = await openBookingRound(`Oudergesprekken groep 7 (${RUN})`)
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

		// The booking form on the site is d0's step; the same booking goes
		// through the portal API here, with the guardian's own token.
		const booked = await parent.request.post(
			'/apps/portaliq/portal/api/collections/learniq/conference-signup',
			{
				headers: bearer(),
				data: {
					conferenceRoundId: round.id,
					learnerRef: CHILD.ref,
					notes: 'Graag over rekenen praten',
				},
			},
		)
		expect(booked.status(), await booked.text()).toBeLessThan(300)
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
		await openPage(parent, 'learniq/parentConferenceSignups')
		await expect(
			parent.getByText('Graag over rekenen praten').first(),
		).toBeVisible()
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
		await openPage(parent, 'learniq/parentConferenceSlots')
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

	test('d2. the guardian picks a free time, and the teacher acknowledges it', async ({
		browser,
	}) => {
		test.setTimeout(300_000)
		// A run of its own evening, so the time picker's labels never match a
		// time an earlier run left open.
		const evening = new Date(
			Date.now() + (20 + (Date.now() % 150)) * 86_400_000,
		)
			.toISOString()
			.slice(0, 10)
		const note = `Graag over lezen praten (${RUN})`
		// Created without a mode: on a primary school this round books directly.
		// It allows two times per child, so the second booking below can only
		// be refused because the time is taken.
		const round = await openBookingRound(
			`Oudergesprekken groep 7, direct (${RUN})`,
			{ maxBookingsPerChild: 2 },
			async (opened) => {
				const availability = await teacherCreate('teacher-availability', {
					conferenceRoundId: opened.id,
					teacherId: TEACHER.user,
					blocks: [
						{
							startsAt: `${evening}T18:00:00+02:00`,
							endsAt: `${evening}T18:36:00+02:00`,
						},
					],
					tenant_id: TENANT,
				})
				expect(await transition(availability.id, 'submit')).toBe('submitted')
			},
		)
		const stored = await teacher.get(
			`/apps/openregister/api/objects/learniq/conference-round/${round.id}`,
		)
		expect((await stored.json()).bookingMode).toBe('direct')

		// The teacher's free times: three of 10 minutes, 2 minutes apart.
		const freeTimes = async (): Promise<Array<Record<string, any>>> => {
			const res = await teacher.get(
				`/apps/openregister/api/objects/learniq/conference-slot?conferenceRoundId=${round.id}`,
			)
			return ((await res.json()).results ?? []).filter(
				(row: Record<string, any>) => row.lifecycle === 'free',
			)
		}
		await expect
			.poll(async () => (await freeTimes()).length, { timeout: 15_000 })
			.toBe(3)
		const first = (await freeTimes()).sort((a, b) =>
			String(a.startsAt).localeCompare(String(b.startsAt)),
		)[0]

		// The guardian sees the free times, and picks the first on the site.
		await expect
			.poll(
				async () =>
					(
						await portalRows(
							'conference-slot',
							'parentConferenceFreeSlots',
						)
					).some((row) => (row.id ?? row['@self']?.id) === first.id),
				{ timeout: 15_000 },
			)
			.toBe(true)
		// The free times page carries the booking form.
		await openPage(parent, 'learniq/parentConferenceFreeSlots')
		await shot(parent, 'd2-free-times')
		const form = parent.getByRole('form', { name: 'Tijd boeken' })
		await form
			.getByRole('combobox', { name: 'Kind', exact: true })
			.selectOption({ label: CHILD.name })
		await form
			.getByRole('combobox', { name: 'Tijd', exact: true })
			.selectOption({ label: first.slotLabel })
		await form.getByLabel('Wat de leerkracht vooraf moet weten').fill(note)
		await shot(parent, 'd2-booking-form')
		await form
			.getByRole('button', { name: 'Deze tijd boeken', exact: true })
			.click()

		const mine = async (): Promise<string | undefined> =>
			(await portalRows('conference-slot', 'parentConferenceSlots')).find(
				(row) => (row.id ?? row['@self']?.id) === first.id,
			)?.lifecycle
		await expect.poll(mine, { timeout: 15_000 }).toBe('booked')
		await openPage(parent, 'learniq/parentConferenceSlots')
		await shot(parent, 'd2-booked')

		// A second booking of the same time is refused, and the time stays hers.
		const again = await parent.request.post(
			'/apps/portaliq/portal/api/collections/learniq/conference-signup?actionId=bookConferenceSlot',
			{
				headers: bearer(),
				data: {
					learnerRef: CHILD.ref,
					slotId: first.id,
					notes: 'Nog een keer',
				},
			},
		)
		expect(again.status(), await again.text()).toBeGreaterThanOrEqual(400)
		expect(await mine()).toBe('booked')

		// The teacher sees the booking on the round page and acknowledges it.
		const staff = await signInToNextcloud(browser, TEACHER)
		await staff.goto(`/index.php/apps/learniq/conferences/rounds/${round.id}`)
		await dismissTour(staff)
		await shot(staff, 'd2-teacher-bookings')
		expect(await transition(first.id, 'acknowledge')).toBe('acknowledged')
		await staff.close()

		await expect.poll(mine, { timeout: 15_000 }).toBe('acknowledged')
		await openPage(parent, 'learniq/parentConferenceSlots')
		await shot(parent, 'd2-acknowledged')

		// Close this round, so its free times do not stay open for the next run.
		expect(await transition(round.id, 'close-booking')).toBe('booking-closed')
	})

	test("e. the guardian reads the grades on her child's published report cards, never a draft", async () => {
		// A primary school records no grade entries: the grades are on the
		// report cards (portal-parent-report-card-grades). The teacher starts
		// a new report card for Vera; it stays a draft.
		const draft = await teacherCreate('report-card', {
			learnerId: CHILD.userId,
			reportPeriodId: REPORT_PERIOD_1,
			cohortId: GROUP_7,
			subjectGrades: [],
			mentorComment: `Concept (${RUN})`,
			tenant_id: TENANT,
		})
		expect(draft.lifecycle ?? 'draft').toBe('draft')

		const rows = await portalRows('report-card', 'parentReportCardGrades')
		// Vera's published report cards, and nothing of another child.
		expect(new Set(rows.map((row) => row.learnerRef))).toEqual(
			new Set([CHILD.ref]),
		)
		expect(rows.map((row) => row.periodName)).toEqual(
			expect.arrayContaining(['Rapport 1', 'Rapport 2']),
		)
		const rapport1 = rows.find((row) => row.periodName === 'Rapport 1')
		expect(rapport1?.gradeLines).toContain('Rekenen: 7,9')
		expect(rapport1?.gradeLines).toHaveLength(6)
		// Only the readable copies leave the server: no nested grades with
		// their uuids, and the draft is not there.
		expect(rows.every((row) => row.subjectGrades === undefined)).toBe(true)
		expect(rows.map((row) => row.id)).not.toContain(draft.id)
	})

	/**
	 * Sign the guardian in to the portal with the stub DigiD broker.
	 *
	 * @param {Browser} browser The browser.
	 * @return {Promise<Page>} The guardian's signed-in portal page.
	 */
	async function signInAsGuardian(browser: Browser): Promise<Page> {
		const page =
			await // A Dutch browser: learniq's labels follow the request's language.
			(
				await browser.newContext({
					storageState: undefined,
					locale: 'nl-NL',
				})
			).newPage()
		// Docker Desktop forwards a port that listens on the WSL side with
		// gaps: the instance cannot always reach the stub, and its discovery
		// or token request then fails with `oidc_failed`. Measured on :8090
		// on 2026-10-01/02: refused for the first 8 to 12 seconds after
		// listen(), and later reachable, unreachable for about 30 seconds,
		// and reachable again. A few more round trips cover such a gap.
		for (let attempt = 1; ; attempt++) {
			stub.nextLogin({
				sub: GUARDIAN.sub,
				email: GUARDIAN.email,
				acr: ACR_SUBSTANTIAL,
			})
			await page.goto(SITE)
			await page
				.getByTestId('site-account-signin')
				.waitFor({ timeout: 15_000 })
			await shot(page, 'a0-portal-login')
			await page.getByTestId('site-account-signin-route').first().click()
			// The sign-in returns to the site by itself (portaliq#1028).
			const landed = await page
				.waitForURL(/\/apps\/portaliq\/site[^#]*route=%2Fmijn/, {
					timeout: 15_000,
				})
				.then(() => true)
				.catch(() => false)
			if (landed) {
				break
			}
			expect(
				attempt,
				`sign-in did not return to the site: ${page.url()}`,
			).toBeLessThan(8)
			await page.waitForTimeout(5_000)
		}
		await waitForAccountPage(page)
		// The site keeps the bearer per tab, in sessionStorage.
		const readToken = async () =>
			await page.evaluate((key) => sessionStorage.getItem(key), SITE_TOKEN_KEY)
		await expect.poll(readToken, { timeout: 20_000 }).toBeTruthy()
		token = (await readToken()) ?? ''
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
	 * A conference round for group 7 that the teacher opened for booking.
	 *
	 * Flows d0 and d plan the times after booking closes (`preference`);
	 * flow d2 lets the guardian pick a free time (created without a mode,
	 * so the primary school default is what it tests).
	 *
	 * @param {string} name The round's name.
	 * @param {object} extra More round fields (`bookingMode`, `maxBookingsPerChild`).
	 * @param {Function} beforeOpening Runs once invitations are out, before booking opens.
	 * @return {Promise<Record<string, any>>} The round.
	 */
	async function openBookingRound(
		name: string,
		extra: Record<string, unknown> = { bookingMode: 'preference' },
		beforeOpening: (round: Record<string, any>) => Promise<void> = async () =>
			undefined,
	): Promise<Record<string, any>> {
		const round = await teacherCreate('conference-round', {
			name,
			cohortIds: [GROUP_7],
			teacherIds: [TEACHER.user],
			slotDurationMinutes: 10,
			bufferMinutes: 2,
			bookingOpensAt: new Date(Date.now() - 86_400_000).toISOString(),
			bookingClosesAt: new Date(Date.now() + 7 * 86_400_000).toISOString(),
			tenant_id: TENANT,
			...extra,
		})
		expect(await transition(round.id, 'send-invitations')).toBe(
			'invitations-sent',
		)
		await beforeOpening(round)
		expect(await transition(round.id, 'open-booking')).toBe('booking-open')
		return round
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
 * Open one of the guardian's pages on the site by its signed-in route.
 *
 * `learniq/parentChildren` is learniq's page for that collection; `news`,
 * `inbox`, `account` and the other shell sections stand on their own.
 *
 * @param {Page} page The site page.
 * @param {string} route The route below `/mijn/`.
 * @return {Promise<void>}
 */
async function openPage(page: Page, route: string): Promise<void> {
	await page.goto(`${SITE}&route=${encodeURIComponent(`/mijn/${route}`)}`)
	await waitForAccountPage(page)
}

/**
 * Wait until the signed-in area of the site shows its page title.
 *
 * @param {Page} page The site page.
 * @return {Promise<void>}
 */
async function waitForAccountPage(page: Page): Promise<void> {
	await page.getByTestId('site-account-title').first().waitFor({ timeout: 20_000 })
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
