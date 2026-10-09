// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Vaartveld College (vo), against school-design/vaartveld/preview. The
 * website boards run signed out; Noor Bakker's boards sign her in with her
 * school account (`PORTAL_DESIGN_PASSWORD`). Today's timetable is not
 * checked: it waits for the pupil's sessions (site-pupil-portal-design T1).
 *
 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md
 * @spec openspec/changes/site-pupil-portal-design/specs/portal-contribution/spec.md#requirement-the-pupil-overview-follows-the-designed-board
 */

import { expect, test } from '@playwright/test'
import { siteUrl } from '../helpers/portal-fixture.ts'
import {
	boardShot,
	dated,
	ensurePortalAccount,
	expectNoHorizontalScroll,
	expectNoSeriousAxeFinding,
	expectTexts,
	expectTheme,
	expectWidgetOrder,
	openSitePage,
	signInAs,
} from './boards.ts'

const PORTAL = 'vaartveld'
const DESIGN = {
	theme: 'vaartveld',
	headingFont: 'Red Hat Display',
	bodyFont: 'Red Hat Text',
	primary: '#1F4FD8',
	buttonRadius: 6,
}
const FOOTER = [
	'Een vraag? Bel of mail de school.',
	'Vaartveld College is een voorbeeldschool.',
]
const NOOR = {
	user: 'vo-leerling-121',
	ref: 'ee02000a-0000-4000-8000-000000000534',
	name: 'Noor Bakker',
}

test.describe('vaartveld: the website', () => {
	test('Home', async ({ page }, info) => {
		await openSitePage(page, PORTAL, '/')
		await expectTexts(page, [
			// The notice strip: a bold lead and the text (school-portals-match-their-boards).
			'Herfstvakantie',
			dated(
				'Van zaterdag 17 tot en met zondag 25 oktober is de school dicht.',
			),
			'Kijk ver vooruit.',
			'Direct regelen',
			'Rooster en wijzigingen',
			'Nieuws',
			dated('Informatieavond profielkeuze op dinsdag 3 november'),
			'Mijn Vaartveld',
			'Agenda',
			'Mentorgesprekken',
			'Toetsweek 1, bovenbouw',
			...FOOTER,
		])
		await expectWidgetOrder(page, [
			'nlBanner',
			'hero',
			'nlQuickTasks',
			'nlNewsList',
			'nlSignIn',
			'nlEventList',
			'nlLinkColumns',
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
		await openSitePage(page, PORTAL, '/praktisch/ziek-melden')
		await expectTexts(page, [
			'Ziek melden en verlof',
			'Meld het voor 08.15 uur',
			'Wat meldt u hoe?',
			'Ziek op de dag van een toets',
			'Anouk Visser',
			...FOOTER,
		])
		await expectNoSeriousAxeFinding(page)
		await boardShot(page, PORTAL, 'Contentpagina', info)
	})

	test('Zoeken and Artikel', async ({ page }, info) => {
		await openSitePage(page, PORTAL, '/zoeken')
		await expectTexts(page, [
			'Nieuws en documenten',
			'Toetsweek 1: het rooster staat online',
		])
		await boardShot(page, PORTAL, 'Zoeken', info)
		await page
			.getByRole('link', {
				name: dated('Informatieavond profielkeuze op dinsdag 3 november'),
			})
			.first()
			.click()
		await expectTexts(page, [
			dated('Dinsdag 3 november 2026, 19.30 tot 21.00 uur'),
			'Marloes Peters',
			'Kom je ook?',
		])
		await boardShot(page, PORTAL, 'Artikel', info)
	})

	test('Inloggen', async ({ page }, info) => {
		await page.goto(`${siteUrl(PORTAL)}&route=${encodeURIComponent('/mijn')}`)
		await page.getByTestId('site-account-signin').waitFor({ timeout: 20_000 })
		await expectTexts(page, ['DigiD'])
		await boardShot(page, PORTAL, 'Inloggen', info)
	})
})

test.describe('vaartveld: Mijn Vaartveld (Noor Bakker)', () => {
	test('MijnOverzicht', async ({ browser }, info) => {
		await ensurePortalAccount(
			NOOR.user,
			'student',
			{ learnerRef: NOOR.ref },
			NOOR.name,
		)
		const page = await signInAs(
			browser,
			PORTAL,
			NOOR.user,
			info.project.use.viewport ?? { width: 1440, height: 1000 },
		)
		await expect(
			page.getByText(/Goede(morgen|middag|navond), Noor/).first(),
		).toBeVisible()
		await expectTexts(page, [
			'Huiswerk en toetsen',
			'Leesverslag inleveren',
			'Toets tijdvak 3 en 4',
			'Laatste cijfers',
			'Afwezigheid dit schooljaar',
			// vaartveld-pupil-pages-follow-the-boards: the links of the board.
			'Hele week',
			'Alle cijfers',
		])
		if (info.project.name === 'phone') {
			await expectNoHorizontalScroll(page)
		}
		await expectNoSeriousAxeFinding(page)
		await boardShot(
			page,
			PORTAL,
			info.project.name === 'phone' ? 'MobielHome' : 'MijnOverzicht',
			info,
		)
	})
})
