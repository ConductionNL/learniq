// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Esdoornveen (mbo), against school-design/esdoornveen/preview. The website
 * boards run signed out; Milan de Groot's boards sign him in with his school
 * account (`PORTAL_DESIGN_PASSWORD`). The company boards (Petra Bakker,
 * eHerkenning) are wave 2 (W2-7) and not checked here.
 *
 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md
 * @spec openspec/specs/bpv/spec.md#requirement-the-hours-bar-shows-approved-waiting-and-returned-hours
 * @spec openspec/changes/site-workplace-trainer-portal-design/specs/portal-contribution/spec.md
 * @spec openspec/changes/portal-board-checks-run-on-a-real-instance/specs/example-sets/spec.md#requirement-the-board-checks-run-against-any-instance-that-loaded-the-sets
 * @spec openspec/changes/school-portals-match-their-boards/specs/example-sets/spec.md#requirement-the-portal-declarations-follow-their-boards
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-each-school-audience-offers-its-own-documents-grouped-per-record
 * @spec openspec/changes/bpv-hours-per-day/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-holds-its-days
 * @spec openspec/changes/bpv-hours-per-day/specs/bpv/spec.md#requirement-a-returned-week-names-the-day-and-the-question
 * @spec openspec/changes/bpv-hours-per-day/specs/portal-contribution/spec.md#requirement-a-student-writes-hours-per-day-and-keeps-a-draft
 */

import type { APIRequestContext } from '@playwright/test'
import type { PortalLogin, SeededRow } from '../helpers/portal-fixture.ts'

import { expect, request, test } from '@playwright/test'
import { baseUrl } from '../base-url.ts'
import {
	createNextcloudAccount,
	grantPortalAccount,
	portalRows,
	removeNextcloudAccount,
	removeRows,
	requireSignInMode,
	signInWithNextcloudAccount,
	SITE_TOKEN_KEY,
	siteUrl,
} from '../helpers/portal-fixture.ts'
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
			'Open dag',
			dated('Zaterdag 7 november 2026, van 10.00 tot 14.00 uur'),
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
	test('Berichten: the question about his Tuesday', async ({ browser }, info) => {
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
			`${siteUrl(PORTAL)}&route=${encodeURIComponent('/mijn/learniq/studentReturnedHours')}`,
		)
		await expectTexts(page, [
			dated('Vraag over je uren van dinsdag 29 september'),
			'tandarts',
		])
		await expectNoSeriousAxeFinding(page)
	})

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
			dated('13 oktober 2026'),
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

	test('Documenten: his agreement waits on the company', async ({ browser }, info) => {
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
			`${siteUrl(PORTAL)}&route=${encodeURIComponent('/mijn/learniq/studentDocuments')}`,
		)
		await expectTexts(page, [
			'Overeenkomsten',
			'Bakker Techniek BV',
			'Wacht op leerbedrijf',
		])
		if (info.project.name === 'phone') {
			await expectNoHorizontalScroll(page)
		}
		await expectNoSeriousAxeFinding(page)
		await boardShot(page, PORTAL, 'Documenten', info)
	})
})

/**
 * bpv-hours-per-day: Milan writes a day, keeps the week, sends it; Petra
 * returns it naming the day with her question; Milan changes the day.
 *
 * The story weeks 40 and 41 are read only (the board), never written: the
 * flow writes a spare week of his placement that holds no week yet, and
 * removes it afterwards. Petra signs in through a Nextcloud account the run
 * creates and gives her praktijkopleider claim, the way the trainer suite
 * does (her eHerkenning boards are wave 2). Desktop only: the two projects
 * would otherwise write the same week twice.
 */
test.describe.serial('esdoornveen: hours per day (Milan and Petra)', () => {
	const RUN = Date.now().toString(36)
	const PETRA_ACCOUNT = {
		user: `e2e-petra-${RUN}`,
		pass: `Pw-${RUN}-${Math.random().toString(36).slice(2)}!`,
	}
	const created: SeededRow[] = []
	let admin: APIRequestContext
	let milan: PortalLogin | undefined
	let petra: PortalLogin | undefined
	let placementId = ''
	let monday = ''
	let weekId = ''
	let accountCreated = false

	/** The yyyy-mm-dd of a date. */
	const ymd = (d: Date): string => d.toISOString().slice(0, 10)

	/** The week as stored, read by the admin. */
	const storedWeek = async (): Promise<Record<string, any>> => {
		const res = await admin.get(
			`/apps/openregister/api/objects/learniq/bpv-hour-week/${weekId}`,
		)
		expect(res.status(), await res.text()).toBe(200)
		return await res.json()
	}

	/** One portal action, with the signed-in account's own bearer. */
	const act = async (
		login: PortalLogin,
		action: string,
		data: Record<string, unknown>,
	) =>
		await login.page.request.post(
			`/apps/portaliq/portal/api/actions/learniq/${action}`,
			{ headers: { Authorization: `Bearer ${login.token}` }, data },
		)

	/** Milan's day, as his phone form sends it. */
	const day = (startTime: string, send: 'keep' | 'send') => ({
		bpvPlacementId: placementId,
		date: monday,
		startTime,
		endTime: '16:30',
		breakMinutes: '30',
		description: `Storing aan de sorteerband gezocht (${RUN})`,
		send,
	})

	test.beforeAll(async ({ browser }, info) => {
		test.skip(
			info.project.name === 'phone',
			'the flow writes a week once; the desktop project runs it',
		)
		admin = await request.newContext({
			baseURL: baseUrl(),
			httpCredentials: ADMIN_CREDENTIALS,
			extraHTTPHeaders: { 'OCS-APIRequest': 'true' },
		})

		// His placement and its trainer, as the mbo set loaded them.
		const placements = await admin.get(
			`/apps/openregister/api/objects/learniq/bpv-placement?learnerRef=${MILAN.ref}&_limit=10`,
		)
		expect(placements.status(), await placements.text()).toBe(200)
		const placement = ((await placements.json()).results ?? []).find(
			(row: Record<string, any>) => row.trainingCompanyName === 'Bakker Techniek BV',
		)
		expect(placement, "Milan's placement at Bakker Techniek BV").toBeTruthy()
		placementId = String(placement.id ?? placement.uuid)

		// A spare week: the latest Monday of the placement with no week yet.
		const weeks = await admin.get(
			`/apps/openregister/api/objects/learniq/bpv-hour-week?bpvPlacementId=${placementId}&_limit=200`,
		)
		expect(weeks.status(), await weeks.text()).toBe(200)
		const taken = new Set(
			((await weeks.json()).results ?? []).map((row: Record<string, any>) =>
				String(row.isoWeek),
			),
		)
		const isoWeekOf = (d: Date): string => {
			const t = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate()))
			t.setUTCDate(t.getUTCDate() + 4 - (t.getUTCDay() || 7))
			const first = new Date(Date.UTC(t.getUTCFullYear(), 0, 1))
			const n = Math.ceil(((t.getTime() - first.getTime()) / 86_400_000 + 1) / 7)
			return `${t.getUTCFullYear()}-W${String(n).padStart(2, '0')}`
		}
		const from = new Date(`${placement.periodFrom}T00:00:00Z`)
		const cursor = new Date(`${placement.periodTo}T00:00:00Z`)
		cursor.setUTCDate(cursor.getUTCDate() - ((cursor.getUTCDay() + 6) % 7))
		while (cursor >= from && taken.has(isoWeekOf(cursor))) {
			cursor.setUTCDate(cursor.getUTCDate() - 7)
		}
		expect(cursor >= from, 'a week of his placement without hours').toBeTruthy()
		monday = ymd(cursor)

		const trainers = await admin.get(
			'/apps/openregister/api/objects/learniq/praktijkopleider?familyName=Bakker&givenName=Petra&_limit=5',
		)
		expect(trainers.status(), await trainers.text()).toBe(200)
		const trainer = ((await trainers.json()).results ?? [])[0]
		expect(trainer, 'Petra Bakker, the praktijkopleider').toBeTruthy()

		await ensurePortalAccount(
			MILAN.user,
			'student',
			{ learnerRef: MILAN.ref },
			MILAN.name,
		)
		const page = await signInAs(browser, PORTAL, MILAN.user, {
			width: 1440,
			height: 1000,
		})
		const token =
			(await page.evaluate((key) => sessionStorage.getItem(key), SITE_TOKEN_KEY)) ?? ''
		expect(token, "Milan's bearer").not.toBe('')
		milan = { page, token }

		const { organisation } = await requireSignInMode(admin, PORTAL, 'nextcloud')
		await createNextcloudAccount(admin, PETRA_ACCOUNT, 'Petra Bakker')
		accountCreated = true
		await grantPortalAccount(admin, created, {
			subjectRef: PETRA_ACCOUNT.user,
			audience: 'praktijkopleider',
			organisation,
			email: `${PETRA_ACCOUNT.user}@example.org`,
			displayName: 'Petra Bakker',
			claims: { learniq: { practicalTrainerId: String(trainer.id ?? trainer.uuid) } },
		})
		petra = await signInWithNextcloudAccount(browser, PETRA_ACCOUNT, PORTAL, SHOTS)
	})

	test.afterAll(async () => {
		if (admin === undefined) {
			return
		}
		if (weekId !== '') {
			created.push({ register: 'learniq', schema: 'bpv-hour-week', id: weekId })
		}
		await removeRows(admin, created, 'esdoornveen hours')
		if (accountCreated === true) {
			await removeNextcloudAccount(admin, PETRA_ACCOUNT.user)
		}
		await milan?.page.close()
		await petra?.page.close()
		await admin.dispose()
	})

	test('Monday 08.00 to 16.30 with 30 minutes break, kept for later', async () => {
		const kept = await act(milan!, 'writeHourDay', day('08:00', 'keep'))
		expect(kept.status(), await kept.text()).toBeLessThan(300)

		const weeks = await admin.get(
			`/apps/openregister/api/objects/learniq/bpv-hour-week?bpvPlacementId=${placementId}&_limit=200`,
		)
		const week = ((await weeks.json()).results ?? []).find(
			(row: Record<string, any>) =>
				(row.days ?? []).some((line: Record<string, any>) => line.date === monday),
		)
		expect(week, 'the week that holds his Monday').toBeTruthy()
		weekId = String(week.id ?? week.uuid)

		const row = await storedWeek()
		expect(row.lifecycle).toBe('draft')
		expect(row.days).toHaveLength(1)
		expect(Number(row.days[0].hours)).toBe(8)
		expect(Number(row.hoursSubmitted)).toBe(8)

		// A draft is not among the weeks waiting for Petra.
		const waiting = await portalRows(petra!, 'bpv-hour-week', 'poHourWeeks')
		expect(waiting.find((w) => String(w.id) === weekId)).toBeFalsy()

		// And his own week reads as kept for later.
		await milan!.page.goto(
			`${siteUrl(PORTAL)}&route=${encodeURIComponent('/mijn/learniq/studentHourWeeks')}`,
		)
		await expectTexts(milan!.page, [row.isoWeek, 'Bewaard voor later'])
	})

	test('he sends the week, and it waits for Petra', async () => {
		const sent = await act(milan!, 'writeHourDay', day('08:00', 'send'))
		expect(sent.status(), await sent.text()).toBeLessThan(300)
		expect((await storedWeek()).lifecycle).toBe('submitted')

		await expect
			.poll(
				async () =>
					(await portalRows(petra!, 'bpv-hour-week', 'poHourWeeks')).some(
						(w) => String(w.id) === weekId,
					),
				{ timeout: 20_000 },
			)
			.toBe(true)
	})

	test('Petra returns the week naming the Monday, with her question', async () => {
		// A return without a question is refused in words.
		const bare = await act(petra!, 'approveHourWeek', {
			hourWeekId: weekId,
			outcome: 'returned',
			returnedDate: monday,
		})
		expect(bare.status()).toBeGreaterThanOrEqual(400)

		const question = `Volgens mij ging je om 14.00 uur naar de tandarts (${RUN})`
		const returned = await act(petra!, 'approveHourWeek', {
			hourWeekId: weekId,
			outcome: 'returned',
			returnedDate: monday,
			question,
		})
		expect(returned.status(), await returned.text()).toBeLessThan(300)

		const row = await storedWeek()
		expect(row.lifecycle).toBe('rejected')
		expect(row.days[0].returnedQuestion).toBe(question)
		expect(String(row.returnSubject ?? '')).toMatch(/^Vraag over je uren van maandag \d{1,2} \S+$/)

		// His messages name the day, with her question.
		const messages = await portalRows(milan!, 'bpv-hour-week', 'studentReturnedHours')
		expect(messages.find((m) => String(m.id) === weekId)).toBeTruthy()
		await milan!.page.goto(
			`${siteUrl(PORTAL)}&route=${encodeURIComponent('/mijn/learniq/studentReturnedHours')}`,
		)
		await expectTexts(milan!.page, [String(row.returnSubject)])
	})

	test('Milan changes the day and sends the week again', async () => {
		// He went to the dentist at 14.00: 08.00 to 14.00 less the break.
		const changed = await act(milan!, 'writeHourDay', {
			...day('08:00', 'send'),
			endTime: '14:00',
		})
		expect(changed.status(), await changed.text()).toBeLessThan(300)

		const row = await storedWeek()
		expect(row.lifecycle).toBe('submitted')
		expect(row.days).toHaveLength(1)
		expect(row.days[0].endTime).toBe('14:00')
		expect(Number(row.hoursSubmitted)).toBe(5.5)

		// The week waits for Petra again, so it is no longer his to change,
		// and it has left his messages.
		const locked = await act(milan!, 'writeHourDay', day('08:00', 'keep'))
		expect(locked.status()).toBe(409)
		const messages = await portalRows(milan!, 'bpv-hour-week', 'studentReturnedHours')
		expect(messages.find((m) => String(m.id) === weekId)).toBeFalsy()
	})
})
