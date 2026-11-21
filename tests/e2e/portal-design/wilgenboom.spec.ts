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
 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-overview-follows-the-designed-board
 * @spec openspec/changes/portal-board-checks-run-on-a-real-instance/specs/example-sets/spec.md#requirement-the-board-checks-run-against-any-instance-that-loaded-the-sets
 * @spec openspec/changes/absence-reports-seen-and-ended/specs/portal-contribution/spec.md#requirement-the-guardian-reads-that-the-school-saw-her-report
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-each-school-audience-offers-its-own-documents-grouped-per-record
 * @spec openspec/changes/leave-requests-decided-by-the-school/specs/portal-contribution/spec.md
 *
 * Covers:
 *   @e2e openspec/changes/leave-requests-decided-by-the-school/specs/portal-contribution/spec.md#scenario-fatima-asks-leave-for-vera
 */

import type { Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import * as path from 'node:path'
import { baseUrl } from '../base-url.ts'
import { SITE_TOKEN_KEY, siteUrl, waitForAccountPage } from '../helpers/portal-fixture.ts'
import { ACR_SUBSTANTIAL } from '../helpers/stub-digid.ts'
import { apiAs, LiveFixtures } from '../live-fixtures.ts'
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
		await expectTexts(parent, [
			'Sami heeft buikgriep',
			'Vera heeft koorts',
			// Juf Esra saw this morning's report when she opened the register
			// (absence-reports-seen-and-ended): seen, not yet decided.
			/Gezien door .*Esra.* om \d{1,2}[.:]\d{2} uur/i,
		])
		await boardShot(parent, PORTAL, 'MijnLijst', info)
	})

	// Playwright needs the fixtures argument destructured; these tests read the shared signed-in page.
	// eslint-disable-next-line no-empty-pattern
	test('Documenten: the reports per child', async ({}, info) => {
		// school-documents-on-the-portal: her children's published reports, a group per child.
		await parent.goto(
			`${siteUrl(PORTAL)}&route=${encodeURIComponent('/mijn/learniq/parentDocuments')}`,
		)
		await waitForAccountPage(parent)
		await expectTexts(parent, ['Groep 7', 'Groep 4', 'PDF'])
		if (info.project.name === 'phone') {
			await expectNoHorizontalScroll(parent)
		}
		await expectNoSeriousAxeFinding(parent)
		await boardShot(parent, PORTAL, 'Documenten', info)
	})

	// Playwright needs the fixtures argument destructured; these tests read the shared signed-in page.
	// eslint-disable-next-line no-empty-pattern
	test('Verlof: Fatima asks, the directeur allows, she reads the decision', async ({}, info) => {
		// leave-requests-decided-by-the-school T7. The request is the run's own:
		// two school days a few weeks ahead, removed afterwards. The directeur is a
		// temporary member of the deciders' group (LeaveDecisionGuard::DECIDERS).
		test.skip(
			info.project.name === 'phone',
			'the flow writes a request once; the desktop project runs it',
		)
		const fx = new LiveFixtures()
		const admin = await fx.api()
		try {
			const profiles = await admin.get(
				'/index.php/apps/openregister/api/objects/learniq/learner-profile?givenName=Vera&familyName=Hulstkamp&_limit=5',
			)
			expect(profiles.status(), await profiles.text()).toBe(200)
			const vera = ((await profiles.json()).results ?? [])[0]
			expect(vera, "Vera Hulstkamp's profile").toBeTruthy()
			const veraRef = String(vera.id ?? vera.uuid)

			// A Monday and Tuesday at least three weeks ahead.
			const monday = new Date()
			monday.setUTCDate(monday.getUTCDate() + 21)
			monday.setUTCDate(monday.getUTCDate() + ((8 - monday.getUTCDay()) % 7))
			const tuesday = new Date(monday)
			tuesday.setUTCDate(monday.getUTCDate() + 1)
			const ymd = (d: Date): string => d.toISOString().slice(0, 10)
			const reason = `De bruiloft van haar tante in Marokko (${fx.run})`

			// Fatima asks for leave for Vera, with her own bearer.
			const token =
				(await parent.evaluate((key) => sessionStorage.getItem(key), SITE_TOKEN_KEY)) ?? ''
			expect(token, "Fatima's bearer").not.toBe('')
			const asked = await parent.request.post(
				'/apps/portaliq/portal/api/actions/learniq/requestLeave',
				{
					headers: { Authorization: `Bearer ${token}` },
					data: {
						learnerRef: veraRef,
						dateFrom: ymd(monday),
						dateTo: ymd(tuesday),
						kind: 'wedding',
						reason,
					},
				},
			)
			expect(asked.status(), await asked.text()).toBeLessThan(300)

			const stored = await admin.get(
				`/index.php/apps/openregister/api/objects/learniq/leave-request?learnerRef=${veraRef}&_limit=200`,
			)
			expect(stored.status(), await stored.text()).toBe(200)
			const row = ((await stored.json()).results ?? []).find(
				(r: Record<string, any>) => r.reason === reason,
			)
			expect(row, 'her request, as stored').toBeTruthy()
			const requestId = String(row.id ?? row.uuid)
			fx.adopt('leave-request', requestId)
			expect(row.lifecycle).toBe('submitted')
			expect(Number(row.schoolDays)).toBe(2)

			// It waits for the school on her overview's leave page.
			await parent.goto(
				`${siteUrl(PORTAL)}&route=${encodeURIComponent('/mijn/learniq/parentLeave')}`,
			)
			await waitForAccountPage(parent)
			await expectTexts(parent, ['Uw verlofaanvragen', 'Bruiloft', 'Ingediend'])

			// The directeur allows it, with a short explanation.
			const directeur = await fx.user('directeur', ['administration-managers'])
			const api = await apiAs(directeur.id, directeur.password)
			const note = `Veel plezier op de bruiloft (${fx.run})`
			const allowed = await api.post(
				`/index.php/apps/openregister/api/objects/${requestId}/transition`,
				{ data: { action: 'allow', decisionNote: note } },
			)
			expect(allowed.status(), await allowed.text()).toBe(200)
			await api.dispose()

			// Fatima reads the decision and the school's explanation.
			await parent.reload()
			await waitForAccountPage(parent)
			await expectTexts(parent, ['Toegestaan', note])
			await boardShot(parent, PORTAL, 'Verlof', info)
		} finally {
			await fx.teardown()
		}
	})
})
