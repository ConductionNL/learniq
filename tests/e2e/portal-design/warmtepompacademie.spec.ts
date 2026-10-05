// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Warmtepompacademie (training), against school-design/warmtepompacademie/preview.
 * The website boards, and the employer's boards (Linda Jansen of Jansen
 * Installatietechniek BV, employer-portal-audience). Linda signs in here with
 * the `nextcloud` mode as a stand-in for eHerkenning, on a portal account
 * with the employer audience and the claims the invitation writes.
 *
 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md
 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md
 */

import { test } from '@playwright/test'
import { siteUrl } from '../helpers/portal-fixture.ts'
import {
	boardShot,
	ensurePortalAccount,
	expectNoHorizontalScroll,
	expectNoSeriousAxeFinding,
	expectTexts,
	expectTheme,
	expectWidgetOrder,
	openSitePage,
	signInAs,
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
		await expectWidgetOrder(page, [
			'hero',
			'nlEventList',
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
			'Bevestiging uiterlijk dinsdag 6 oktober',
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
			`${siteUrl(PORTAL)}&route=${encodeURIComponent('/mijn/employerBookings')}`,
		)
		await expectTexts(page, [
			'Lucht-water warmtepomp: ontwerp en inbedrijfstelling',
			'Waterzijdig inregelen',
			'Geboortedatum ontbreekt',
			'Certificaat geldig tot 30 november 2026',
			'Geboortedatum invullen',
		])
		await expectNoSeriousAxeFinding(page)
		await boardShot(page, PORTAL, 'Detail', info)
	})
})
