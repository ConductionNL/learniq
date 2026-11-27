// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Zuiddrecht College (vo), against school-design/vaartveld/preview. The
 * website boards run signed out; the pupil boards sign Fenna de Jong in with her
 * school account (`PORTAL_DESIGN_PASSWORD`). Today's timetable is not
 * checked: it waits for the pupil's sessions (site-pupil-portal-design T1).
 *
 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md
 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-pupil-overview-follows-the-designed-board
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-each-school-audience-offers-its-own-documents-grouped-per-record
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-a-pupil-asks-for-a-proof-of-enrolment
 * @spec openspec/specs/portal-contribution/spec.md
 *
 * Covers:
 *   @e2e openspec/specs/portal-contribution/spec.md#scenario-noor-signs-up-for-tuesday
 *   @e2e openspec/specs/portal-contribution/spec.md#scenario-noor-picks-tuesday-1630
 */

import type { PortalLogin } from '../helpers/portal-fixture.ts'

import { expect, test } from '@playwright/test'
import { portalRows, SITE_TOKEN_KEY, siteUrl } from '../helpers/portal-fixture.ts'
import { LiveFixtures } from '../live-fixtures.ts'
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
	'Zuiddrecht College is een voorbeeldschool.',
]
const NOOR = {
	user: 'vo-leerling-121',
	ref: 'ee02000a-0000-4000-8000-000000000534',
	name: 'Fenna de Jong',
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
			'Mijn Zuiddrecht',
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

test.describe('vaartveld: Mijn Zuiddrecht (Fenna de Jong)', () => {
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
			page.getByText(/Goede(morgen|middag|navond), Fenna/).first(),
		).toBeVisible()
		await expectTexts(page, [
			'Huiswerk en toetsen',
			'Leesverslag inleveren',
			'Toets tijdvak 3 en 4',
			'Laatste cijfers',
			'Afwezigheid dit schooljaar',
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

	test('Documenten: her reports and a proof of enrolment', async ({ browser }, info) => {
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
		await page.goto(
			`${siteUrl(PORTAL)}&route=${encodeURIComponent('/mijn/learniq/studentDocuments')}`,
		)
		await expectTexts(page, ['Rapporten', 'Keuzes en verklaringen'])
		// She asks for a proof of enrolment; it joins her statements.
		await page
			.getByRole('button', { name: 'Vraag een bewijs van inschrijving aan' })
			.first()
			.click()
		await expect(
			page.getByText(/Bewijs van inschrijving, schooljaar \d{4}-\d{4}/).first(),
		).toBeVisible({ timeout: 15_000 })
		if (info.project.name === 'phone') {
			await expectNoHorizontalScroll(page)
		}
		await expectNoSeriousAxeFinding(page)
		await boardShot(page, PORTAL, 'Documenten', info)
	})
})

/**
 * pupil-books-support-lessons-and-mentor-talks T6: Fenna (the change's Noor,
 * renamed with the redrawn vo boards) signs up for the steunles wiskunde and
 * books her own mentor talk on Tuesday 13 October at 16.30.
 *
 * The set seeds the steunles with 6 of 12 places taken and the mentor-talk
 * round of 3HV-B with `pupilMayBook`. The run's sign-up and booking are
 * removed afterwards and the time is set free again. Her father's portal
 * read of the talk needs a DigiD sign-in at substantial
 * (`parentConferenceSignups`); this suite checks the stored booking that
 * collection reads instead: about her, booked by herself, with no guardian
 * on it. Desktop only: the two projects would otherwise book twice.
 */
test.describe.serial('vaartveld: Fenna books a steunles and her mentor talk', () => {
	const fx = new LiveFixtures()
	let fenna: PortalLogin | undefined
	let offerId = ''
	let placesBefore = 0
	let slotId = ''

	/** One portal action, with her own bearer. */
	const act = async (action: string, data: Record<string, unknown>) =>
		await fenna!.page.request.post(
			`/apps/portaliq/portal/api/actions/learniq/${action}`,
			{ headers: { Authorization: `Bearer ${fenna!.token}` }, data },
		)

	test.beforeAll(async ({ browser }, info) => {
		test.skip(
			info.project.name === 'phone',
			'the flow books once; the desktop project runs it',
		)
		await ensurePortalAccount(
			NOOR.user,
			'student',
			{ learnerRef: NOOR.ref },
			NOOR.name,
		)
		const page = await signInAs(browser, PORTAL, NOOR.user, {
			width: 1440,
			height: 1000,
		})
		const token =
			(await page.evaluate((key) => sessionStorage.getItem(key), SITE_TOKEN_KEY)) ?? ''
		expect(token, "Fenna's bearer").not.toBe('')
		fenna = { page, token }
	})

	test.afterAll(async () => {
		const admin = await fx.api()
		if (slotId !== '') {
			// Deleting her booking does not free the time, and no transition
			// leads from booked back to free: put a free copy in its place, as
			// the set loaded it (the spec finds the time by its start, not its id).
			const url = '/index.php/apps/openregister/api/objects/learniq/conference-slot'
			const read = await admin.get(`${url}/${slotId}`).catch(() => null)
			const slot = read?.ok() ? await read.json() : null
			if (slot !== null && slot.lifecycle !== 'free') {
				const { id: _id, uuid: _uuid, '@self': _self, ...fields } = slot
				await admin.delete(`${url}/${slotId}`).catch(() => null)
				await admin
					.post(url, {
						data: { ...fields, lifecycle: 'free', learnerId: null, learnerRef: null, guardianRef: null, bookedAt: null, acknowledgedAt: null },
					})
					.catch(() => null)
			}
		}
		await fx.teardown()
		await fenna?.page.close()
	})

	test('she reads the steunles with its places left and signs up', async () => {
		const offers = await portalRows(fenna!, 'elective-offer', 'studentElectiveOffers')
		const offer = offers.find((o) => o.name === 'Steunles wiskunde')
		expect(offer, 'the steunles wiskunde among her support lessons').toBeTruthy()
		offerId = String(offer!.id ?? offer!.uuid)
		placesBefore = Number(offer!.placesLeft)
		expect(placesBefore, 'a place left').toBeGreaterThan(0)

		await fenna!.page.goto(
			`${siteUrl(PORTAL)}&route=${encodeURIComponent('/mijn/learniq/studentElectiveOffers')}`,
		)
		await expectTexts(fenna!.page, [
			'Steunles wiskunde',
			'Meneer Bos',
			`Nog ${placesBefore} plekken`,
		])

		const signed = await act('signUpForElective', { offerId })
		expect(signed.status(), await signed.text()).toBeLessThan(300)

		const mine = await portalRows(fenna!, 'elective-sign-up', 'studentElectiveSignUps')
		const signUp = mine.find((r) => String(r.offerId) === offerId)
		expect(signUp, 'her sign-up').toBeTruthy()
		fx.adopt('elective-sign-up', String(signUp!.id ?? signUp!.uuid))
		expect(signUp!.status).toBe('signed-up')

		// The line on the offer counts her place.
		await expect
			.poll(
				async () =>
					(await portalRows(fenna!, 'elective-offer', 'studentElectiveOffers')).find(
						(o) => String(o.id ?? o.uuid) === offerId,
					)?.placesLeftLine,
				{ timeout: 20_000 },
			)
			.toBe(`Nog ${placesBefore - 1} plekken`)

		// A second sign-up for the same offer is refused.
		const again = await act('signUpForElective', { offerId })
		expect(again.status()).toBeGreaterThanOrEqual(400)
	})

	test('she books Tuesday 13 October 16.30 with her mentor', async () => {
		const times = await portalRows(fenna!, 'conference-slot', 'studentConferenceTimes')
		// The board's Tuesday 13 October moves with the load week: the free time
		// on a Tuesday at 16.30 in the school's clock.
		const tuesday = times.find((t) => {
			const local = new Intl.DateTimeFormat('en-GB', {
				timeZone: 'Europe/Amsterdam', weekday: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
			}).format(new Date(String(t.startsAt)))
			return local === 'Tue 16:30'
		})
		expect(tuesday, 'the free time on Tuesday (13 October on the board) at 16.30').toBeTruthy()
		slotId = String(tuesday!.id ?? tuesday!.uuid)

		// A `create` action is written through its collection; the action
		// route forwards `endpoint` actions only and answers 403 for this one.
		const booked = await fenna!.page.request.post(
			'/apps/portaliq/portal/api/collections/learniq/conference-signup',
			{ headers: { Authorization: `Bearer ${fenna!.token}` }, data: { actionId: 'bookConferenceSlotAsPupil', slotId } },
		)
		expect(booked.status(), await booked.text()).toBeLessThan(300)

		const talks = await portalRows(fenna!, 'conference-signup', 'studentConferenceSignups')
		// Her talks carry the time, not the slot id.
		const talk = talks.find((r) => new Date(String(r.startsAt)).getTime() === new Date(String(tuesday!.startsAt)).getTime())
		expect(talk, 'her talk').toBeTruthy()
		fx.adopt('conference-signup', String(talk!.id ?? talk!.uuid))

		// The time is hers, booked by herself: no guardian on it, so her
		// father reads it through his children's bookings.
		const admin = await fx.api()
		const slot = await admin.get(
			`/index.php/apps/openregister/api/objects/learniq/conference-slot/${slotId}`,
		)
		expect(slot.status(), await slot.text()).toBe(200)
		const stored = await slot.json()
		expect(stored.lifecycle).toBe('booked')
		expect(String(stored.learnerRef)).toBe(NOOR.ref)
		expect(stored.guardianRef ?? null).toBeNull()

		// The time has left her free times, and her talks list it.
		const left = await portalRows(fenna!, 'conference-slot', 'studentConferenceTimes')
		expect(left.find((t) => String(t.id ?? t.uuid) === slotId)).toBeFalsy()
		await fenna!.page.goto(
			`${siteUrl(PORTAL)}&route=${encodeURIComponent('/mijn/learniq/studentConferenceSignups')}`,
		)
		// "13-10-2026 16:30" on the board, on the Tuesday of the load week here.
		const day = new Intl.DateTimeFormat('nl-NL', { timeZone: 'Europe/Amsterdam', day: '2-digit', month: '2-digit', year: 'numeric' })
			.format(new Date(String(tuesday!.startsAt)))
		await expectTexts(fenna!.page, ['Mijn mentorgesprekken', new RegExp(`${day} 16:30`)])
	})
})
