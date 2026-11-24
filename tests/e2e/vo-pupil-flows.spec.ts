// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * A vo pupil reads today's lessons and hands in from her overview
 * (site-pupil-portal-design T8).
 *
 * pupil-flows.spec.ts proves her grades, a draft handed in through the API,
 * her absence report and her BPV hours. This suite proves what the change
 * added to the overview: today's timetable over `studentSessions` (a
 * cancelled lesson stays on it, marked "Vervalt"), the work to hand in under
 * "Huiswerk en toetsen" with today's first, the hand-in form reached from the
 * overview, and handed-in work dropping from the list.
 *
 * SHE SIGNS IN WITH HER SCHOOL ACCOUNT: portaliq's `nextcloud` mode, trust
 * `low`, on the vo example portal (vaartveld, Zuiddrecht College). The labels
 * are the Dutch ones the site shows, through PortalLabelTranslator.
 *
 * WHAT THIS SUITE CREATES AND REMOVES. Its own Nextcloud account for a pupil
 * of a group the school really published work for, her portal account with
 * the `learnerRef` claim, two published assignments (one due today, one in a
 * week), three lessons today in her group (one cancelled), and the
 * submission she hands in. All of it is removed in `afterAll`. It reads the
 * example set and writes nothing into it.
 *
 *   AUDIENCE_FLOW_E2E=1 PLAYWRIGHT_BASE_URL=http://localhost:8090 \
 *   npx playwright test --config tests/e2e/audience-flow.config.ts vo-pupil-flows
 *
 * Flows:
 *   a. her overview shows her homework, today's first, today's lessons and
 *      her latest grades;
 *   b. a cancelled lesson stays on today's timetable, marked "Vervalt";
 *   c. she hands in from the overview, through "Werk inleveren";
 *   d. the work she handed in drops from "Huiswerk en toetsen";
 *   e. tapping open work opens the hand-in form.
 *
 * Screenshots land in `test-results/audience-flow/vo-pupil/`.
 *
 * @e2e openspec/specs/portal-contribution/spec.md#scenario-noa-opens-the-portal-between-classes
 * @e2e openspec/specs/portal-contribution/spec.md#scenario-today-s-lessons-with-a-cancellation
 * @e2e openspec/specs/portal-contribution/spec.md#scenario-handed-in-work-drops-from-the-task-list
 * @e2e openspec/specs/portal-contribution/spec.md#scenario-an-open-assignment-links-to-the-hand-in
 */

import type { APIRequestContext } from '@playwright/test'
import type { PortalLogin, SeededRow } from './helpers/portal-fixture.ts'

import { expect, test } from '@playwright/test'
import * as path from 'node:path'
import {
	asUser,
	borrowLearnerProfile,
	borrowPublishedHomework,
	createNextcloudAccount,
	createRow,
	grantPortalAccount,
	nextcloudAccountExists,
	openHome,
	portalRows,
	removeNextcloudAccount,
	removeRows,
	requireSignInMode,
	shot,
	signInWithNextcloudAccount,
} from './helpers/portal-fixture.ts'

const ENABLED = process.env.AUDIENCE_FLOW_E2E === '1'
const SHOTS = path.resolve(
	__dirname,
	'..',
	'..',
	'test-results',
	'audience-flow',
	'vo-pupil',
)
const PORTAL = process.env.AUDIENCE_FLOW_PORTAL ?? 'vaartveld'
const TENANT =
	process.env.AUDIENCE_FLOW_TENANT ?? '00000000-0000-4000-8000-000000000000'
const ADMIN = {
	user: process.env.NC_ADMIN_USER ?? 'admin',
	pass: process.env.NC_ADMIN_PASS ?? 'admin',
}
// The guardian suite's two children, left alone (po-parent-flows).
const OTHER_SUITES = [
	'ee010008-0000-4000-8000-000000000415',
	'ee010008-0000-4000-8000-000000000467',
]

const RUN = Date.now().toString(36)
const TODAY = new Date().toISOString().slice(0, 10)
const PUPIL = { user: '', pass: `Lq-e2e-${RUN}-leerling!`, name: '' }
const DUE_TODAY = `Leesverslag inleveren (${RUN})`
const DUE_NEXT_WEEK = `Verslag biologie: de cel (${RUN})`
// Her lessons today, as the board draws them; the last one is cancelled.
const LESSONS = [
	{ title: `Wiskunde (${RUN})`, starts: '07:30', ends: '08:20' },
	{ title: `Engels (${RUN})`, starts: '09:15', ends: '10:05' },
	{ title: `Geschiedenis (${RUN})`, starts: '11:30', ends: '12:20' },
]

/**
 * A moment as the register writes it.
 *
 * @param {Date} date The moment.
 * @return {string} The date and time.
 */
function stamp(date: Date): string {
	return date.toISOString().replace(/\.\d+Z$/, '+00:00')
}

test.describe.configure({ mode: 'serial' })

test.describe('vo pupil: today and hand-in from the overview', () => {
	test.skip(
		!ENABLED,
		'set AUDIENCE_FLOW_E2E=1 on an instance with portaliq and the vo example portal',
	)

	const created: SeededRow[] = []
	let admin: APIRequestContext
	let pupil: PortalLogin
	let profileRef = ''
	let dueTodayId = ''
	let dueNextWeekId = ''
	let cancelledId = ''
	let createdAccount = false

	test.beforeAll(async ({ browser }) => {
		test.setTimeout(300_000)
		admin = await asUser(ADMIN)
		const { organisation } = await requireSignInMode(admin, PORTAL, 'nextcloud')

		// Her group comes from work the school really published, because
		// `Assignment.learnerRefs` is stamped from the group's enrolments.
		const homework = await borrowPublishedHomework(admin, OTHER_SUITES)
		test.skip(
			homework === undefined,
			'this instance has no published assignment with pupils on it to borrow',
		)
		const cohortId = String(homework!.assignment.cohortId ?? '')
		test.skip(cohortId === '', 'the borrowed assignment names no group')

		// One due later today, one in a week, so the order can be read.
		const assign = async (title: string, dueAt: Date): Promise<string> => {
			const row = await createRow(admin, created, 'learniq', 'assignment', {
				title,
				instructions: 'Lever je werk in als pdf.',
				maxPoints: 10,
				cohortId,
				dueAt: stamp(dueAt),
				allowLateSubmission: true,
				lifecycle: 'published',
				tenant_id: TENANT,
			})
			return String(row.id)
		}
		// An hour from now: still today for a run during the school day.
		dueTodayId = await assign(DUE_TODAY, new Date(Date.now() + 60 * 60 * 1000))
		dueNextWeekId = await assign(
			DUE_NEXT_WEEK,
			new Date(Date.now() + 7 * 24 * 60 * 60 * 1000),
		)

		// Her profile and the school account it names, when that account does
		// not exist yet: an existing account's password is not the suite's.
		let profile: Record<string, any> | undefined
		for (const ref of homework!.learnerRefs) {
			const candidate = await borrowLearnerProfile(admin, ref)
			const uid = String(candidate?.ncUserId ?? '')
			if (candidate === undefined || uid === '') {
				continue
			}
			if ((await nextcloudAccountExists(admin, uid)) === true) {
				continue
			}
			profile = candidate
			profileRef = ref
			break
		}
		test.skip(
			profile === undefined,
			'every pupil of the borrowed group already has a Nextcloud account',
		)
		PUPIL.user = String(profile!.ncUserId)
		PUPIL.name = `${profile!.givenName ?? ''} ${profile!.familyName ?? ''}`.trim()
		await createNextcloudAccount(admin, PUPIL, PUPIL.name)
		createdAccount = true

		// Three lessons today in her group. The last is cancelled through the
		// lifecycle, with the reason a school records.
		for (const lesson of LESSONS) {
			const row = await createRow(admin, created, 'learniq', 'session', {
				cohortId,
				title: lesson.title,
				startsAt: `${TODAY}T${lesson.starts}:00+00:00`,
				endsAt: `${TODAY}T${lesson.ends}:00+00:00`,
				location: 'Lokaal 0.21',
				tenant_id: TENANT,
			})
			cancelledId = String(row.id)
		}
		const cancelled = await admin.post(
			`/apps/openregister/api/objects/${cancelledId}/transition`,
			{
				data: {
					action: 'cancel',
					data: { changeReasonKind: 'teacher-absence', changeReason: 'Docent ziek' },
				},
			},
		)
		expect(cancelled.status(), await cancelled.text()).toBeLessThan(300)

		await grantPortalAccount(admin, created, {
			subjectRef: PUPIL.user,
			audience: 'student',
			organisation,
			email: `${PUPIL.user}@example.org`,
			displayName: PUPIL.name,
			claims: { learniq: { learnerRef: profileRef } },
		})
		pupil = await signInWithNextcloudAccount(browser, PUPIL, PORTAL, SHOTS)
	})

	test.afterAll(async () => {
		await removeRows(admin, created, 'vo-pupil-flow')
		if (createdAccount === true) {
			await removeNextcloudAccount(admin, PUPIL.user)
		}
		await pupil?.page.close()
	})

	// @e2e openspec/specs/portal-contribution/spec.md#scenario-noa-opens-the-portal-between-classes
	test('a. her overview shows her homework, today first, and today\'s lessons', async () => {
		await openHome(pupil.page, PORTAL)
		await shot(pupil.page, SHOTS, 'a1-overview')
		const main = pupil.page.locator('main').first()

		await expect(main.getByText('Huiswerk en toetsen').first()).toBeVisible({
			timeout: 20_000,
		})
		await expect(main.getByText(DUE_TODAY).first()).toBeVisible()
		await expect(main.getByText(DUE_NEXT_WEEK).first()).toBeVisible()
		const text = await main.innerText()
		expect(
			text.indexOf(DUE_TODAY),
			'the work due today comes before the work due next week',
		).toBeLessThan(text.indexOf(DUE_NEXT_WEEK))

		await expect(main.getByText('Je rooster vandaag').first()).toBeVisible()
		for (const lesson of LESSONS) {
			await expect(main.getByText(lesson.title).first()).toBeVisible()
		}
		await expect(main.getByText('Laatste cijfers').first()).toBeVisible()

		// What she reads is hers: the homework collection holds both pieces.
		const ids = (await portalRows(pupil, 'assignment', 'studentHomework')).map(
			(row) => String(row.id),
		)
		expect(ids).toEqual(expect.arrayContaining([dueTodayId, dueNextWeekId]))
	})

	// The struck-through row is drawn by portaliq's `calendar-timetable-display`
	// (T5b). Without it portaliq drops `display` and draws the plain calendar
	// list, and the lesson still shows with its status.
	// @e2e openspec/specs/portal-contribution/spec.md#scenario-today-s-lessons-with-a-cancellation
	test('b. a cancelled lesson stays on today\'s timetable, marked "Vervalt"', async () => {
		const sessions = await portalRows(pupil, 'session', 'studentSessions')
		const cancelled = sessions.find((row) => String(row.id) === cancelledId)
		expect(cancelled, 'the cancelled lesson is still hers to read').toBeTruthy()
		expect(cancelled?.lifecycle).toBe('cancelled')

		await openHome(pupil.page, PORTAL)
		const main = pupil.page.locator('main').first()
		await expect(main.getByText(LESSONS[2].title).first()).toBeVisible({
			timeout: 20_000,
		})
		await expect(main.getByText('Vervalt').first()).toBeVisible()
		await shot(pupil.page, SHOTS, 'b1-cancelled-lesson')
	})

	// The hand-in endpoint refused a pupil's draft on a live instance on
	// 4 October 2026 (pupil-flows.spec.ts step c); this step is the proof of
	// the fix as much as of the overview.
	test('c. she hands in from the overview', async () => {
		await openHome(pupil.page, PORTAL)
		const main = pupil.page.locator('main').first()
		await main
			.getByRole('link', { name: 'Werk inleveren', exact: true })
			.or(main.getByRole('button', { name: 'Werk inleveren', exact: true }))
			.first()
			.click()

		const form = pupil.page.getByRole('form', { name: 'Werk inleveren' })
		await expect(form).toBeVisible({ timeout: 20_000 })
		// The action names the work by its id: the field has no list to pick
		// from yet (CREATE_SUBMISSION_FIELD_CONFIGS declares no options).
		await form
			.getByRole('textbox', { name: /Het werk dat (u|je) inlevert/ })
			.fill(dueTodayId)
		await shot(pupil.page, SHOTS, 'c1-hand-in-form')
		await form.getByRole('button', { name: 'Werk inleveren', exact: true }).click()

		// The form writes her draft; the row's hand-in sends it.
		let submissionId = ''
		await expect
			.poll(
				async () => {
					const row = (
						await portalRows(pupil, 'submission', 'studentSubmissions')
					).find((r) => String(r.assignmentId) === dueTodayId)
					submissionId = String(row?.id ?? '')
					return submissionId
				},
				{ timeout: 20_000 },
			)
			.not.toBe('')
		created.push({ register: 'learniq', schema: 'submission', id: submissionId })

		const handIn = await pupil.page.request.post(
			`/apps/portaliq/portal/api/collections/learniq/submission/${submissionId}/actions/handIn`,
			{ headers: { Authorization: `Bearer ${pupil.token}` }, data: {} },
		)
		expect(handIn.status(), await handIn.text()).toBeLessThan(300)
		await expect
			.poll(
				async () =>
					(await portalRows(pupil, 'submission', 'studentSubmissions')).find(
						(r) => String(r.id) === submissionId,
					)?.lifecycle,
				{ timeout: 20_000 },
			)
			.toMatch(/^(submitted|late)$/)
	})

	// @e2e openspec/specs/portal-contribution/spec.md#scenario-handed-in-work-drops-from-the-task-list
	test('d. the work she handed in drops from "Huiswerk en toetsen"', async () => {
		await openHome(pupil.page, PORTAL)
		const main = pupil.page.locator('main').first()
		await expect(main.getByText(DUE_NEXT_WEEK).first()).toBeVisible({
			timeout: 20_000,
		})
		await expect(main.getByText(DUE_TODAY)).toHaveCount(0)
		await shot(pupil.page, SHOTS, 'd1-handed-in-dropped')
	})

	// The tasks block declares no link of its own (STUDENT_HOMEWORK_TASKS_BLOCK);
	// the spec asks that open work opens the hand-in. Run last, so a failure
	// here leaves the steps above standing.
	// @e2e openspec/specs/portal-contribution/spec.md#scenario-an-open-assignment-links-to-the-hand-in
	test('e. tapping open work opens the hand-in form', async () => {
		await openHome(pupil.page, PORTAL)
		const main = pupil.page.locator('main').first()
		await main.getByText(DUE_NEXT_WEEK).first().click()
		await expect(pupil.page.getByRole('form', { name: 'Werk inleveren' })).toBeVisible({
			timeout: 20_000,
		})
		await shot(pupil.page, SHOTS, 'e1-open-work-form')
	})
})
