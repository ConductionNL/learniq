// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Warmtepompacademie (training), against school-design/warmtepompacademie/preview.
 * Only the website boards are checked: every signed-in board is drawn for
 * the employer (Linda Jansen), and the employer audience is wave 2 (W2-2).
 *
 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md
 */

import { test } from '@playwright/test'
import {
	boardShot,
	expectNoHorizontalScroll,
	expectNoSeriousAxeFinding,
	expectTexts,
	expectTheme,
	expectWidgetOrder,
	openSitePage,
} from './boards.ts'

const PORTAL = 'warmtepompacademie'
const DESIGN = {
	theme: 'warmtepompacademie',
	headingFont: 'Barlow Semi Condensed',
	bodyFont: 'Barlow',
	primary: '#0B6E7A',
	buttonRadius: 3,
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
