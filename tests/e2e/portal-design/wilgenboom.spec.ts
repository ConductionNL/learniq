// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Basisschool De Wilgenboom (po), against the boards in
 * school-design/wilgenboom/preview. The website boards run signed out; the
 * guardian boards sign Fatima Hulstkamp in with DigiD through the stub broker
 * when `PORTAL_DESIGN_DIGID_ISSUER` is set (the organisation's DigiD issuer
 * on the instance), and skip with that reason otherwise.
 *
 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md
 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-the-overview-follows-the-designed-board
 * @spec openspec/changes/portal-board-checks-run-on-a-real-instance/specs/example-sets/spec.md#requirement-the-board-checks-run-against-any-instance-that-loaded-the-sets
 */

import type { Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import * as path from 'node:path'
import { baseUrl } from '../base-url.ts'
import { siteUrl, waitForAccountPage } from '../helpers/portal-fixture.ts'
import { ACR_SUBSTANTIAL } from '../helpers/stub-digid.ts'
import {
	ADMIN_CREDENTIALS,
	boardShot,
	dated,
	expectNoHorizontalScroll,
	expectNoSeriousAxeFinding,
	expectTexts,
	expectTheme,
	expectWidgetOrder,
	openSitePage,
	SHOTS,
	startBroker,
} from './boards.ts'

const PORTAL = 'wilgenboom'
const DESIGN = {
	theme: 'wilgenboom',
	headingFont: 'Lexend',
	bodyFont: 'Lexend',
	primary: '#2F6B4A',
	buttonRadius: 10,
}
const FOOTER = [
	'Heeft u een vraag? Loop binnen, bel of mail ons.',
	'Basisschool De Wilgenboom is een voorbeeldschool.',
]
const FATIMA = {
	ref: 'ee010008-0000-4000-8000-000000000009',
	sub: 'digid-fatima-hulstkamp',
	email: 'fatima.hulstkamp@example.org',
}

test.describe('wilgenboom: the website', () => {
	test('Home', async ({ page }, info) => {
		await openSitePage(page, PORTAL, '/')
		await expectTexts(page, [
			'Let op',
			dated('Vrijdag 9 oktober is een studiedag'),
			'Een school waar elk kind kan groeien',
			'Kom kennismaken',
			'Direct regelen',
			'Oudergesprek plannen',
			'Nieuws van school',
			'De Kinderboekenweek is begonnen',
			'Mijn Wilgenboom',
			'Deze maand op school',
			'Boekenmarkt op het plein',
			'Over onze school',
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
		await openSitePage(page, PORTAL, '/praktisch/afwezig-melden')
		await expectTexts(page, [
			'Uw kind afwezig melden',
			'Meld het voor half negen',
			'Online melden',
			'Liever bellen?',
			'Wat meldt u hoe?',
			'Dokter of tandarts',
			'Verlof buiten de vakanties',
			'Hanneke Postma',
			...FOOTER,
		])
		await expectNoSeriousAxeFinding(page)
		await boardShot(page, PORTAL, 'Contentpagina', info)
	})

	test('Zoeken and Artikel', async ({ page }, info) => {
		await openSitePage(page, PORTAL, '/zoeken')
		await expectTexts(page, [
			'Nieuws en documenten',
			dated('Ouderavond op donderdag 29 oktober'),
			'Zo werkt de ouderavond dit jaar',
		])
		await boardShot(page, PORTAL, 'Zoeken', info)

		await page
			.getByRole('link', { name: 'De Kinderboekenweek is begonnen' })
			.first()
			.click()
		await expectTexts(page, [
			'De Kinderboekenweek is begonnen',
			'Boekenmarkt op het plein',
			'We zoeken nog vier ouders',
			'Meer nieuws',
		])
		await expectNoSeriousAxeFinding(page)
		await boardShot(page, PORTAL, 'Artikel', info)
	})

	test('Inloggen', async ({ page }, info) => {
		await page.goto(`${siteUrl(PORTAL)}&route=${encodeURIComponent('/mijn')}`)
		await page.getByTestId('site-account-signin').waitFor({ timeout: 20_000 })
		await expectTexts(page, ['DigiD'])
		await boardShot(page, PORTAL, 'Inloggen', info)
	})
})

test.describe('wilgenboom: Mijn Wilgenboom (Fatima Hulstkamp)', () => {
	const issuer = process.env.PORTAL_DESIGN_DIGID_ISSUER ?? ''
	let parent: Page
	let closeStub: () => Promise<void> = async () => undefined

	test.beforeAll(async ({ browser }, info) => {
		test.skip(
			issuer === '',
			'PORTAL_DESIGN_DIGID_ISSUER is not set: the guardian signs in with DigiD through the stub broker',
		)
		test.setTimeout(300_000)
		const stub = await startBroker(
			issuer,
			process.env.PORTAL_DESIGN_DIGID_CLIENT ?? 'wilgenboom-portal',
			path.join(SHOTS, 'stub-key.pem'),
		)
		closeStub = stub.close

		// The school links Fatima to the portal; a second invite of the same guardian is answered, not duplicated.
		const admin = await request.newContext({
			baseURL: baseUrl(),
			httpCredentials: ADMIN_CREDENTIALS,
			extraHTTPHeaders: { 'OCS-APIRequest': 'true' },
		})
		const invite = await admin.post(
			`/apps/learniq/api/portal/guardians/${FATIMA.ref}/invite`,
			{
				data: {
					email: FATIMA.email,
					organisation:
						process.env.PORTAL_DESIGN_ORGANISATION
						?? 'default-organisation',
				},
			},
		)
		expect(invite.status(), await invite.text()).toBeLessThan(300)
		await admin.dispose()

		const viewport = info.project.use.viewport ?? { width: 1440, height: 1000 }
		parent = await (
			await browser.newContext({ locale: 'nl-NL', viewport })
		).newPage()
		for (let attempt = 1; ; attempt++) {
			stub.nextLogin({
				sub: FATIMA.sub,
				email: FATIMA.email,
				acr: ACR_SUBSTANTIAL,
			})
			await parent.goto(
				`${siteUrl(PORTAL)}&route=${encodeURIComponent('/mijn')}`,
			)
			await parent
				.getByTestId('site-account-signin')
				.waitFor({ timeout: 15_000 })
			await parent.getByTestId('site-account-signin-route').first().click()
			const landed = await parent
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
				`sign-in did not return to the site: ${parent.url()}`,
			).toBeLessThan(6)
		}
		await waitForAccountPage(parent)
	})

	test.afterAll(async () => {
		await closeStub()
	})

	// Playwright needs the fixtures argument destructured; these tests read the shared signed-in page.
	// eslint-disable-next-line no-empty-pattern
	test('MijnOverzicht', async ({}, info) => {
		await expect(
			parent.getByText(/Goede(morgen|middag|navond), Fatima/).first(),
		).toBeVisible()
		await expectTexts(parent, [
			'Afwezig melden',
			'Wat u nog moet doen',
			// One task per child per open round, named by the child (guardian-tasks-per-child-and-self-assessment).
			'Kies een tijd voor het oudergesprek van Sami',
			'Mijn kinderen',
			'Vera',
			'Sami',
			'Nieuw van school',
			'Woensdag naar de kinderboerderij: dit moet mee',
			'Deze maand',
		])
		// Vera already has a time, so her round asks nothing.
		await expect(
			parent.getByText('Kies een tijd voor het oudergesprek van Vera'),
		).toHaveCount(0)
		if (info.project.name === 'phone') {
			await expectNoHorizontalScroll(parent)
		}
		await expectNoSeriousAxeFinding(parent)
		await boardShot(
			parent,
			PORTAL,
			info.project.name === 'phone' ? 'MobielHome' : 'MijnOverzicht',
			info,
		)
	})

	// Playwright needs the fixtures argument destructured; these tests read the shared signed-in page.
	// eslint-disable-next-line no-empty-pattern
	test('MijnLijst: the absence reports', async ({}, info) => {
		// The board's MijnLijst is the Afwezigheid page, not the overview.
		await parent.goto(
			`${siteUrl(PORTAL)}&route=${encodeURIComponent('/mijn/learniq/parentAbsence')}`,
		)
		await waitForAccountPage(parent)
		await expectTexts(parent, ['Sami heeft buikgriep', 'Vera heeft koorts'])
		await boardShot(parent, PORTAL, 'MijnLijst', info)
	})
})
