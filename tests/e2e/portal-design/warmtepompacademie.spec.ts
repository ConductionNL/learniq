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
 */

import { expect, request, test } from '@playwright/test'
import path from 'node:path'
import { baseUrl } from '../base-url.ts'
import { siteUrl } from '../helpers/portal-fixture.ts'
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
const FOOTER = [
	'Twijfelt u welke cursus past? Bel de planning.',
	'De Warmtepompacademie is een voorbeeldorganisatie.',
]

test.describe('warmtepompacademie: the website', () => {
	test('Home', async ({ page }, info) => {
		await openSitePage(page, PORTAL, '/')
		await expectTexts(page, [
			'Cursussen voor wie warmtepompen installeert',
			'Eerstvolgende cursusdagen',
			'Praktijkhal Zuiddrecht',
			'F-gassen: herhaling en examen',
			'Nog 1 plek',
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
		await expectWidgetOrder(page, [
			'hero',
			'nlList',
			'nlSignIn',
			'nlButtonLink',
			'markdown',
		])
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
		await expectTexts(page, [
			'Linda',
			'Jansen Installatietechniek BV',
			'Vul de geboortedatum van Youssef El Amrani in',
			'Youssef doet donderdag examen.',
			'F-gassen: herhaling en examen',
			'Tom Verbeek, Youssef El Amrani, Sanne Kok',
			'Geboortedatum van 1 deelnemer ontbreekt',
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
		// The board's Detail is one booking opened: its participants and the birth date form.
		await page
			.getByRole('link', { name: /F-gassen: herhaling en examen/ })
			.first()
			.click()
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
		await expectTexts(page, [
			'Tom',
			'F-gassen: herhaling en examen',
			dated('donderdag 8 oktober'),
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
