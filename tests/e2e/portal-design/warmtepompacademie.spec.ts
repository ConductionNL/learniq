// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Warmtepompacademie (training), against school-design/warmtepompacademie/preview.
 * The website boards, and the employer's boards (Linda Jansen of Jansen
 * Installatietechniek BV, employer-portal-audience). Linda signs in here with
 * the `nextcloud` mode as a stand-in for eHerkenning, on a portal account
 * with the employer audience and the claims the invitation writes. When
 * `PORTAL_DESIGN_EHERKENNING_ISSUER` is set she also signs in the real way:
 * the institute invites her company (`POST /api/portal/employers/{ref}/invite`)
 * and she signs in with eHerkenning through the stub broker, which answers
 * with the company's eHerkenning reference (employer-signs-in-with-eherkenning).
 * The organisation's eHerkenning issuer and client must point at that stub:
 * `org_presentation_<organisation uuid>.oidc.eherkenning = {issuer, clientId}`
 * in portaliq's app config, and `oidc_secret_<organisation uuid>_eherkenning`.
 *
 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md
 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md
 * @spec openspec/changes/portal-certificates/specs/portal-contribution/spec.md
 * @spec openspec/changes/participant-portal/specs/portal-contribution/spec.md
 * @spec openspec/changes/employer-signs-in-with-eherkenning/specs/portal-identity/spec.md
 * @spec openspec/changes/portal-board-checks-run-on-a-real-instance/specs/example-sets/spec.md#requirement-the-board-checks-run-against-any-instance-that-loaded-the-sets
 * @spec openspec/changes/school-portals-match-their-boards/specs/example-sets/spec.md#requirement-the-portal-declarations-follow-their-boards
 * @spec openspec/changes/employer-substitutes-a-colleague/specs/portal-contribution/spec.md#requirement-an-employer-sends-a-colleague-in-place-of-a-participant-before-the-deadline
 */

import { expect, request, test } from '@playwright/test'
import path from 'node:path'
import { baseUrl } from '../base-url.ts'
import { SITE_TOKEN_KEY, siteUrl } from '../helpers/portal-fixture.ts'
import { ACR_SUBSTANTIAL } from '../helpers/stub-digid.ts'
import {
	ADMIN_CREDENTIALS,
	boardShot,
	dated,
	ensurePortalAccount,
	expectNoHorizontalScroll,
	expectNoSeriousAxeFinding,
	expectTexts,
	expectTheme,
	expectWidgetOrder,
	openSitePage,
	SHOTS,
	signInAs,
	startBroker,
} from './boards.ts'

const PORTAL = 'warmtepompacademie'
const DESIGN = {
	theme: 'warmtepompacademie',
	headingFont: 'Barlow Semi Condensed',
	bodyFont: 'Barlow',
	primary: '#0B6E7A',
	buttonRadius: 3,
}
const LINDA = {
	user: 'training-contactpersoon-007',
	name: 'Linda Jansen',
	claims: {
		organisationRef: 'ee06001f-0000-4000-8000-000000000001',
		organisationName: 'Jansen Installatietechniek BV',
		editionLocationRef: 'ee060002-0000-4000-8000-000000000001',
	},
}
const TOM = {
	user: 'training-deelnemer-151',
	ref: 'ee06000c-0000-4000-8000-000000000158',
	name: 'Tom Verbeek',
}
const JANSEN = {
	ref: 'ee06001f-0000-4000-8000-000000000001',
	eherkenning: 'eherkenning-jansen-installatietechniek',
	email: 'linda.jansen@jansen-installatietechniek.example',
}
/** Linda's F-gassen booking: the story week's Thursday, the exam day of the boards. */
const FGASSEN_BOOKING = 'ee060020-0000-4000-8000-000000000002'

/**
 * Whether the story week's F-gassen day has passed. The boards are drawn for
 * the week of 5 October with the exam on Thursday; the load moves the set to
 * the week it runs in, so from Friday that day is behind us and drops off
 * every "coming" list (past-course-days-drop-off). Read from the instance,
 * so the check holds on any day of the week.
 *
 * @return {Promise<boolean>} True from the day after the F-gassen day.
 */
async function fgassenPassed(): Promise<boolean> {
	const admin = await request.newContext({
		baseURL: baseUrl(),
		httpCredentials: ADMIN_CREDENTIALS,
		extraHTTPHeaders: { 'OCS-APIRequest': 'true' },
	})
	const res = await admin.get(
		`/apps/openregister/api/objects/learniq/course-booking/${FGASSEN_BOOKING}`,
	)
	expect(res.ok(), await res.text()).toBeTruthy()
	const day = String((await res.json()).firstDay ?? '')
	await admin.dispose()
	const today = new Intl.DateTimeFormat('en-CA', {
		timeZone: 'Europe/Amsterdam',
	}).format(new Date())
	return day !== '' && day < today
}

const FOOTER = [
	'Twijfelt u welke cursus past? Bel de planning.',
	'De Warmtepompacademie is een voorbeeldorganisatie.',
]

test.describe('warmtepompacademie: the website', () => {
	test('Home', async ({ page }, info) => {
		await openSitePage(page, PORTAL, '/')
		// The course days come from the live index: once the F-gassen day has
		// passed it drops off and Waterzijdig inregelen leads with its places.
		const passed = await fgassenPassed()
		await expectTexts(page, [
			'Cursussen voor wie warmtepompen installeert',
			'Eerstvolgende cursusdagen',
			'Praktijkhal Zuiddrecht',
			passed ? 'Waterzijdig inregelen' : 'F-gassen: herhaling en examen',
			passed ? '6 plekken vrij' : 'Nog 1 plek',
			'Kies de cursus en een datum',
			'Mijn academie',
			'Medewerkers inschrijven',
			'U oefent op echte opstellingen',
			...FOOTER,
		])
		// The course days stand beside the hero text (portaliq hero-aside).
		await expect(
			page
				.getByTestId('hero-aside')
				.filter({ hasText: 'Eerstvolgende cursusdagen' }),
		).toBeVisible()
		// The board's reading order: the heading, the paragraph, the three steps
		// and the button on the left, the sign-in card beside them. The grid
		// writes its rows top to bottom, so the card, which starts on the
		// heading's row, sits between the heading and the paragraph.
		await expectWidgetOrder(page, [
			'hero',
			'nlHeading',
			'nlParagraph',
			'nlList',
			'nlButtonLink',
			'markdown',
		])
		await expectWidgetOrder(page, ['nlHeading', 'nlSignIn', 'nlList'])
		await expectTheme(page, PORTAL, DESIGN, info)
		if (info.project.name === 'phone') {
			await expectNoHorizontalScroll(page)
		}
		await expectNoSeriousAxeFinding(page)
		await boardShot(
			page,
			PORTAL,
			info.project.name === 'phone' ? 'MobielHome-signed-out' : 'Home',
			info,
		)
	})

	test('Contentpagina', async ({ page }, info) => {
		await openSitePage(page, PORTAL, '/voor-werkgevers/medewerkers-inschrijven')
		await expectTexts(page, [
			'Medewerkers inschrijven',
			'Geboortedatum',
			'Weet u nog niet wie er gaat?',
			'Verplaatsen of annuleren',
			'Sophie van Dam',
			...FOOTER,
		])
		await expectNoSeriousAxeFinding(page)
		await boardShot(page, PORTAL, 'Contentpagina', info)
	})

	test('Zoeken: the course dates', async ({ page }, info) => {
		await openSitePage(page, PORTAL, '/cursusaanbod')
		await expectTexts(page, [
			'Cursusaanbod',
			'Warmtepompen installeren: basis',
			'Lucht-water warmtepomp: ontwerp en inbedrijfstelling',
		])
		await boardShot(page, PORTAL, 'Zoeken', info)
	})
})

test.describe('warmtepompacademie: Mijn academie (Linda Jansen, employer)', () => {
	test('MijnOverzicht: what waits for her and the coming course days', async ({
		browser,
	}, info) => {
		await ensurePortalAccount(LINDA.user, 'employer', LINDA.claims, LINDA.name)
		const page = await signInAs(
			browser,
			PORTAL,
			LINDA.user,
			info.project.use.viewport ?? { width: 1440, height: 1000 },
		)
		const passed = await fgassenPassed()
		await expectTexts(page, [
			'Linda',
			'Jansen Installatietechniek BV',
			'Vul de geboortedatum van Youssef El Amrani in',
			'Youssef doet donderdag examen.',
			// The F-gassen booking is coming until its day has passed.
			...(passed
				? []
				: [
						'F-gassen: herhaling en examen',
						'Tom Verbeek, Youssef El Amrani, Sanne Kok',
						'Geboortedatum van 1 deelnemer ontbreekt',
					]),
			'Warmtepompen installeren: basis',
			'De plek staat vast',
			dated('Bevestiging uiterlijk dinsdag 6 oktober'),
			'F-gassen categorie 1',
			/Verloopt over \d+ weken/,
			dated('Herhaling op 8 oktober'),
			'BRL 6000-21, bovengronds deel',
		])
		if (info.project.name === 'phone') {
			await expectNoHorizontalScroll(page)
		}
		await expectNoSeriousAxeFinding(page)
		await boardShot(page, PORTAL, 'MijnOverzicht', info)
	})

	test('MijnLijst and Detail: the bookings, the steps and the participants', async ({
		browser,
	}, info) => {
		await ensurePortalAccount(LINDA.user, 'employer', LINDA.claims, LINDA.name)
		const page = await signInAs(
			browser,
			PORTAL,
			LINDA.user,
			info.project.use.viewport ?? { width: 1440, height: 1000 },
		)
		await page.goto(
			`${siteUrl(PORTAL)}&route=${encodeURIComponent('/mijn/learniq/employerBookings')}`,
		)
		await expectTexts(page, [
			'Lucht-water warmtepomp: ontwerp en inbedrijfstelling',
			'Waterzijdig inregelen',
		])
		// The board's Detail is one booking opened: its participants and the
		// birth date form. A row of the dated list cannot be opened yet (portaliq
		// `display: rows` draws no link), so the check opens the booking by its
		// record route, as a link from the overview does.
		await page.goto(
			`${siteUrl(PORTAL)}&route=${encodeURIComponent(`/mijn/learniq/employerBookings/${FGASSEN_BOOKING}`)}`,
		)
		await expectTexts(page, [
			'Geboortedatum ontbreekt',
			dated('Certificaat geldig tot 30 november 2026'),
			'Geboortedatum invullen',
		])
		await expectNoSeriousAxeFinding(page)
		await boardShot(page, PORTAL, 'Detail', info)
	})
})

test.describe('warmtepompacademie: Mijn academie (Tom Verbeek, participant)', () => {
	test('MobielHome: his next course day and his certificate', async ({
		browser,
	}, info) => {
		await ensurePortalAccount(
			TOM.user,
			'participant',
			{ learnerRef: TOM.ref },
			TOM.name,
		)
		const page = await signInAs(
			browser,
			PORTAL,
			TOM.user,
			info.project.use.viewport ?? { width: 1440, height: 1000 },
		)
		// His next course day is the next one from today: F-gassen until its
		// day has passed, then the lucht-water course (past-course-days-drop-off).
		const passed = await fgassenPassed()
		await expectTexts(page, [
			'Tom',
			passed
				? 'Lucht-water warmtepomp: ontwerp en inbedrijfstelling'
				: 'F-gassen: herhaling en examen',
			passed ? dated('3, 4 en 10 november') : dated('donderdag 8 oktober'),
			'08.30 tot 16.30 uur',
			'Praktijkhal Zuiddrecht, Energieweg 8',
			'F-gassen categorie 1',
			/Verloopt over \d+ weken/,
			'Lucht-water warmtepomp: ontwerp en inbedrijfstelling',
		])
		if (info.project.name === 'phone') {
			await expectNoHorizontalScroll(page)
		}
		await expectNoSeriousAxeFinding(page)
		await boardShot(
			page,
			PORTAL,
			info.project.name === 'phone' ? 'MobielHome' : 'MobielHome-desktop',
			info,
		)
	})
})

test.describe('warmtepompacademie: Linda signs in with eHerkenning', () => {
	const issuer = process.env.PORTAL_DESIGN_EHERKENNING_ISSUER ?? ''

	test('Inloggen met eHerkenning lands on Mijn academie for Jansen', async ({
		browser,
	}, info) => {
		test.skip(
			issuer === '',
			'PORTAL_DESIGN_EHERKENNING_ISSUER is not set: the employer signs in with eHerkenning through the stub broker',
		)
		test.setTimeout(300_000)
		const stub = await startBroker(
			issuer,
			process.env.PORTAL_DESIGN_EHERKENNING_CLIENT
				?? 'warmtepompacademie-portal',
			path.join(SHOTS, 'stub-key-eherkenning.pem'),
		)
		try {
			const admin = await request.newContext({
				baseURL: baseUrl(),
				httpCredentials: ADMIN_CREDENTIALS,
				extraHTTPHeaders: { 'OCS-APIRequest': 'true' },
			})
			const invite = await admin.post(
				`/apps/learniq/api/portal/employers/${JANSEN.ref}/invite`,
				{
					data: {
						organisation:
							process.env.PORTAL_DESIGN_ORGANISATION
							?? 'default-organisation',
					},
				},
			)
			expect(invite.status(), await invite.text()).toBeLessThan(300)
			await admin.dispose()

			const viewport = info.project.use.viewport ?? {
				width: 1440,
				height: 1000,
			}
			const page = await (
				await browser.newContext({ locale: 'nl-NL', viewport })
			).newPage()
			stub.nextLogin({
				sub: JANSEN.eherkenning,
				email: JANSEN.email,
				acr: ACR_SUBSTANTIAL,
			})
			await page.goto(
				`${siteUrl(PORTAL)}&route=${encodeURIComponent('/mijn')}`,
			)
			await page
				.getByTestId('site-account-signin')
				.waitFor({ timeout: 20_000 })
			await page
				.getByRole('link', { name: /eHerkenning/ })
				.or(page.getByRole('button', { name: /eHerkenning/ }))
				.first()
				.click()
			await page.waitForURL(/\/apps\/portaliq\/site[^#]*route=%2Fmijn/, {
				timeout: 30_000,
			})
			await expectTexts(page, [
				'Jansen Installatietechniek BV',
				'Vul de geboortedatum van Youssef El Amrani in',
			])
			await boardShot(page, PORTAL, 'Inloggen-eherkenning', info)
		} finally {
			await stub.close()
		}
	})
})

/**
 * employer-substitutes-a-colleague: Linda sends a colleague in place of a
 * participant. The story booking I-2026-0412 is read only (its course day
 * moves with the load week and may be past its deadline), so the run books
 * one place of its own on a coming course date, names one of her people,
 * sees "Collega in zijn plaats" on that participant, sends another colleague
 * in his place, and removes the booking and its enrolments afterwards.
 * Desktop only: the two projects would book twice.
 */
test.describe.serial('warmtepompacademie: Linda sends a colleague instead', () => {
	let bookingId = ''

	const admin = async () =>
		await request.newContext({
			baseURL: baseUrl(),
			httpCredentials: ADMIN_CREDENTIALS,
			extraHTTPHeaders: { 'OCS-APIRequest': 'true' },
		})

	test.afterAll(async () => {
		if (bookingId === '') {
			return
		}
		const api = await admin()
		const list = await api.get(
			`/apps/openregister/api/objects/learniq/enrolment?bookingRef=${bookingId}&_limit=50`,
		)
		const rows = list.ok() ? ((await list.json()).results ?? []) : []
		for (const row of rows) {
			await api.delete(`/apps/openregister/api/objects/learniq/enrolment/${row.id}`).catch(() => null)
		}
		await api.delete(`/apps/openregister/api/objects/learniq/course-booking/${bookingId}`).catch(() => null)
		await api.dispose()
	})

	// @e2e openspec/changes/employer-substitutes-a-colleague/specs/portal-contribution/spec.md#requirement-an-employer-sends-a-colleague-in-place-of-a-participant-before-the-deadline
	test('Collega in zijn plaats: the place stays, the participant is replaced', async ({
		browser,
	}, info) => {
		test.skip(info.project.name === 'phone', 'the flow books once; the desktop project runs it')
		await ensurePortalAccount(LINDA.user, 'employer', LINDA.claims, LINDA.name)
		const page = await signInAs(browser, PORTAL, LINDA.user, { width: 1440, height: 1000 })
		const token =
			(await page.evaluate((key) => sessionStorage.getItem(key), SITE_TOKEN_KEY)) ?? ''
		expect(token, "Linda's bearer").not.toBe('')
		const auth = { Authorization: `Bearer ${token}` }
		const rows = async (schema: string, collection: string) => {
			const res = await page.request.get(
				`/apps/portaliq/portal/api/collections/learniq/${schema}?collection=${collection}`,
				{ headers: auth },
			)
			expect(res.status(), await res.text()).toBe(200)
			return ((await res.json()).objects ?? []) as Array<Record<string, any>>
		}
		const act = async (action: string, data: Record<string, unknown>) =>
			await page.request.post(`/apps/portaliq/portal/api/actions/learniq/${action}`, {
				headers: auth,
				data,
			})

		const employees = await rows('learner-profile', 'employerEmployees')
		test.skip(employees.length < 2, 'Jansen has fewer than two employees on this instance')
		const [first, colleague] = employees
		const editions = await rows('cohort', 'employerEditions')
		test.skip(editions.length === 0, 'no course date of the academy is open for booking')

		// The first coming course date whose substitution deadline has not passed.
		let enrolmentId = ''
		for (const edition of editions.slice(0, 4)) {
			const booked = await act('enrolEmployees', { cohortId: edition.id, participantCount: 1 })
			if (booked.status() >= 300) {
				continue
			}
			const body = await booked.json()
			const id = String(body.bookingRef ?? body.result?.bookingRef ?? body.data?.bookingRef ?? '')
			if (id === '') {
				continue
			}
			bookingId = id
			const named = await act('addBookingParticipant', { bookingRef: id, learnerRef: first.id })
			expect(named.status(), await named.text()).toBeLessThan(300)
			const mine = (await rows('enrolment', 'employerParticipants')).find(
				(row) => row.bookingRef === id && row.learnerRef === first.id,
			)
			expect(mine, 'her participant on the new booking').toBeTruthy()
			enrolmentId = String(mine!.id)
			break
		}
		expect(enrolmentId, 'a booking with her participant').not.toBe('')

		// The row link on the opened booking.
		await page.goto(
			`${siteUrl(PORTAL)}&route=${encodeURIComponent(`/mijn/learniq/employerBookings/${bookingId}`)}`,
		)
		await expectTexts(page, [String(first.fullName ?? ''), 'Collega in zijn plaats'])

		// What the row action posts: the row's enrolment and the colleague she picks.
		const sent = await act('substituteParticipant', { enrolmentId, substituteRef: colleague.id })
		test.skip(
			sent.status() === 409 && /Vervangen kan tot/.test(await sent.text()),
			'every bookable course date is past its substitution deadline',
		)
		expect(sent.status(), await sent.text()).toBeLessThan(300)

		const api = await admin()
		const enrolments = (
			await (
				await api.get(`/apps/openregister/api/objects/learniq/enrolment?bookingRef=${bookingId}&_limit=50`)
			).json()
		).results as Array<Record<string, any>>
		const replaced = enrolments.find((row) => row.learnerRef === first.id)
		const substitute = enrolments.find((row) => row.learnerRef === colleague.id)
		expect(replaced?.lifecycle).toBe('withdrawn')
		expect(replaced?.withdrawReason).toBe('vervangen door collega')
		expect(substitute, 'the colleague is enrolled on the same booking').toBeTruthy()
		expect(substitute?.lifecycle).not.toBe('withdrawn')
		const booking = await (
			await api.get(`/apps/openregister/api/objects/learniq/course-booking/${bookingId}`)
		).json()
		expect(Number(booking.participantCount)).toBe(1)
		await api.dispose()
		await page.context().close()
	})
})
