// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Esdoornveen (mbo), against school-design/esdoornveen/preview. The website
 * boards run signed out; Milan de Groot's boards sign him in with his school
 * account (`PORTAL_DESIGN_PASSWORD`). The company boards (Petra Bakker,
 * eHerkenning) are wave 2 (W2-7) and not checked here.
 *
 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md
 * @spec openspec/changes/internship-hours/specs/bpv/spec.md#requirement-the-hours-bar-shows-approved-waiting-and-returned-hours
 * @spec openspec/changes/site-workplace-trainer-portal-design/specs/portal-contribution/spec.md
 * @spec openspec/changes/portal-board-checks-run-on-a-real-instance/specs/example-sets/spec.md#requirement-the-board-checks-run-against-any-instance-that-loaded-the-sets
 * @spec openspec/changes/school-portals-match-their-boards/specs/example-sets/spec.md#requirement-the-portal-declarations-follow-their-boards
 */

import { expect, test } from '@playwright/test'
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

const PORTAL = 'esdoornveen'
const DESIGN = {
	theme: 'esdoornveen',
	headingFont: 'IBM Plex Sans',
	bodyFont: 'IBM Plex Sans',
	primary: '#5B2E91',
	buttonRadius: 4,
}
const FOOTER = [
	'De Servicedesk helpt je verder.',
	'Esdoornveen is een voorbeeldschool in Zuiddrecht.',
]
const MILAN = {
	user: 'mbo-student-251',
	ref: 'ee03000c-0000-4000-8000-000000000251',
	name: 'Milan de Groot',
}

test.describe('esdoornveen: the website', () => {
	test('Home', async ({ page }, info) => {
		await openSitePage(page, PORTAL, '/')
		await expectTexts(page, [
			'Open dag: zaterdag 7 november 2026',
			'Een vak leer je door het te doen',
			'Direct regelen',
			'Leerbedrijf worden',
			'Techniek en ICT',
			'Mechatronica',
			'Studenten mechatronica bouwen sorteermachine voor de kringloop',
			'Mijn Esdoornveen',
			'Voor leerbedrijven',
			...FOOTER,
		])
		// The place of the photo beside the hero text (portaliq hero-aside).
		await expect(page.getByTestId('hero-aside')).toBeVisible()
		await expectWidgetOrder(page, [
			'nlBanner',
			'hero',
			'nlQuickTasks',
			'nlLinkList',
			'nlNewsList',
			'nlSignIn',
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
		await openSitePage(page, PORTAL, '/voor-studenten/ziek-melden')
		await expectTexts(page, [
			'Ziek melden en beter melden',
			'Meld het voor 08.30 uur',
			'Wat meld je waar?',
			'Ben je jonger dan 18?',
			'Lukt inloggen niet?',
			...FOOTER,
		])
		await expectNoSeriousAxeFinding(page)
		await boardShot(page, PORTAL, 'Contentpagina', info)
	})

	test('Artikel: the Mechatronica page', async ({ page }, info) => {
		await openSitePage(page, PORTAL, '/opleidingen/mechatronica')
		await expectTexts(page, [
			'Mechatronica',
			'Mbo niveau 4',
			'25743',
			'Zo ziet je week eruit',
			'Ruud Hermans',
		])
		await expectNoSeriousAxeFinding(page)
		await boardShot(page, PORTAL, 'Artikel', info)
	})

	test('Inloggen', async ({ page }, info) => {
		await page.goto(`${siteUrl(PORTAL)}&route=${encodeURIComponent('/mijn')}`)
		await page.getByTestId('site-account-signin').waitFor({ timeout: 20_000 })
		await boardShot(page, PORTAL, 'Inloggen', info)
	})
})

test.describe('esdoornveen: Mijn Esdoornveen (Milan de Groot)', () => {
	test('Detail: where his placement stands', async ({ browser }, info) => {
		await ensurePortalAccount(
			MILAN.user,
			'student',
			{ learnerRef: MILAN.ref },
			MILAN.name,
		)
		const page = await signInAs(
			browser,
			PORTAL,
			MILAN.user,
			info.project.use.viewport ?? { width: 1440, height: 1000 },
		)
		await page.goto(
			`${siteUrl(PORTAL)}&route=${encodeURIComponent('/mijn/learniq/studentBpvPlacements')}`,
		)
		await expectTexts(page, [
			'Bakker Techniek BV',
			'Waar sta je?',
			'Overeenkomst getekend',
			'Werkplan gemaakt',
			'Tussenbeoordeling',
			'13 oktober 2026',
			'januari 2027',
		])
		await expectNoSeriousAxeFinding(page)
		await boardShot(page, PORTAL, 'Detail', info)
	})

	test('MijnLijst: the hours bar', async ({ browser }, info) => {
		await ensurePortalAccount(
			MILAN.user,
			'student',
			{ learnerRef: MILAN.ref },
			MILAN.name,
		)
		const page = await signInAs(
			browser,
			PORTAL,
			MILAN.user,
			info.project.use.viewport ?? { width: 1440, height: 1000 },
		)
		await page.goto(
			`${siteUrl(PORTAL)}&route=${encodeURIComponent('/mijn/learniq/studentHourWeeks')}`,
		)
		await expectTexts(page, [
			'Mijn BPV-uren',
			'Goedgekeurd',
			'96',
			'Wacht op goedkeuring',
			'Teruggestuurd',
			'2026-W40',
		])
		if (info.project.name === 'phone') {
			await expectNoHorizontalScroll(page)
		}
		await expectNoSeriousAxeFinding(page)
		await boardShot(
			page,
			PORTAL,
			info.project.name === 'phone' ? 'MobielDetail' : 'MijnLijst',
			info,
		)
	})
})
