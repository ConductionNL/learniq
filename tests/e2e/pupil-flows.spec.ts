// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * A pupil's own portal, end to end.
 *
 * The guardian flows were the only portal audience learniq had ever seen work
 * (po-parent-flows.spec.ts). This is the pupil's: she signs in with her school
 * account, lands on an overview that puts the work she has to hand in first,
 * reads her grades, hands in a draft, and reports herself absent through the
 * form on the site.
 *
 * SHE SIGNS IN DIFFERENTLY FROM HER GUARDIAN. A guardian is a DigiD citizen
 * and her collections demand `minTrust: substantial`; a pupil has a school
 * account, so she comes in through portaliq's `nextcloud` mode at trust `low`,
 * which is what every `student` collection declares. helpers/portal-fixture.ts
 * builds that path.
 *
 * WHAT THIS SUITE CREATES AND REMOVES. Its own Nextcloud account, its own
 * learner profile, one published assignment, one draft submission, one
 * published grade and the portal account that carries the `learnerRef` claim —
 * all of it removed in `afterAll`, together with the portal's original list of
 * sign-in modes. It loads no example set and needs none.
 *
 * Run it on an instance with portaliq enabled and a portal for the school:
 *
 *   AUDIENCE_FLOW_E2E=1 PLAYWRIGHT_BASE_URL=http://localhost:8090 \
 *   npx playwright test --config tests/e2e/audience-flow.config.ts pupil-flows
 *
 * Flows:
 *   a. she signs in with her school account and lands on her overview, with
 *      the work to hand in first;
 *   b. her grades page names the subject and the mark;
 *   c. she hands in her draft, and it reads as handed in afterwards;
 *   d. she reports herself absent through the form, and the report is hers;
 *   e. another pupil's row is not hers to read;
 *   f. she enters a week of her own BPV hours, and the week is hers alone;
 *   g. a week on somebody else's placement is refused.
 *
 * Screenshots land in `test-results/audience-flow/pupil/`.
 *
 * @spec openspec/changes/site-pupil-portal-design/specs/portal-contribution/spec.md#requirement-a-pupil-lands-on-an-overview-of-today
 * @spec openspec/changes/site-pupil-portal-design/specs/portal-contribution/spec.md#requirement-new-a-pupil-sees-the-work-she-has-to-hand-in
 * @spec openspec/changes/site-pupil-portal-design/specs/portal-contribution/spec.md#requirement-the-pupil-menu-is-short
 * @spec openspec/changes/internship-hours/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-is-a-record-of-its-own
 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-pupil-hands-in-a-draft-submission-from-the-portal-req-pcon-009
 */

import type { APIRequestContext } from '@playwright/test'
import type { PortalLogin, SeededRow } from './helpers/portal-fixture.ts'

import { expect, request, test } from '@playwright/test'
import * as path from 'node:path'
import {
	asUser,
	borrowLearnerProfile,
	borrowPublishedHomework,
	createNextcloudAccount,
	createRow,
	grantPortalAccount,
	nextcloudAccountExists,
	offerSignInMode,
	openRoute,
	portalRows,
	removeNextcloudAccount,
	removeRows,
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
	'pupil',
)
const PORTAL = process.env.AUDIENCE_FLOW_PORTAL ?? 'wilgenboom'
const TENANT =
	process.env.AUDIENCE_FLOW_TENANT ?? '00000000-0000-4000-8000-000000000000'
const ADMIN = {
	user: process.env.NC_ADMIN_USER ?? 'admin',
	pass: process.env.NC_ADMIN_PASS ?? 'admin',
}

const RUN = Date.now().toString(36)

/**
 * A week from today, as the register writes a moment.
 *
 * @return {string} The date and time.
 */
function dueNextWeek(): string {
	const due = new Date(Date.now() + 7 * 24 * 60 * 60 * 1000)
	return due.toISOString().replace(/\.\d+Z$/, '+00:00')
}
// A subject of the school, so the readable copy of its name has something to
// copy. Override it on an instance whose courses have other uuids.
const COURSE_REF =
	process.env.AUDIENCE_FLOW_COURSE ?? 'ee010004-0000-4000-8000-000000000002'
// Her school account. The user id is the one her learner profile names
// (`LearnerProfile.ncUserId`), because that is the account the school would
// have given her and the one learniq's hand-in endpoint resolves her by. The
// suite creates it only when the instance does not have it yet, and removes
// exactly what it created.
const PUPIL = { user: '', pass: `Lq-e2e-${RUN}-pupil!`, name: '' }
const GRADE_COMPONENT = `lq-e2e-${RUN}`
// internship-hours: the week she enters, and the hours in it. The company
// name carries the run id so her placement page can be read without depending
// on what the example set happens to hold.
const ISO_WEEK = '2026-W40'
const HOURS_WORKED = 28
const COMPANY = `Installatiebedrijf Van Dam (${RUN})`

test.describe.configure({ mode: 'serial' })

test.describe('pupil: her own portal', () => {
	test.skip(
		!ENABLED,
		'set AUDIENCE_FLOW_E2E=1 on an instance with portaliq and a portal for the school',
	)

	const created: SeededRow[] = []
	let admin: APIRequestContext
	let restoreModes: () => Promise<void> = async () => undefined
	let pupil: PortalLogin
	let profileRef = ''
	let submissionId = ''
	// A second draft, which nothing hands in: step c hands the first one in,
	// so a step that wants to see a draft needs one of its own.
	let waitingSubmissionId = ''
	let otherProfileRef = ''
	let assignmentId = ''
	let assignmentTitle = ''
	let createdAccount = false
	// Her own BPV placement, and one on another pupil, so a cross-reference
	// that must be refused has something real to point at.
	let placementId = ''
	let foreignPlacementId = ''
	// What the instance's own learniq declares, read from the manifest in step
	// f: an older build has no hour weeks and steps f and g skip.
	let hoursSupported = false

	test.beforeAll(async ({ browser }) => {
		test.setTimeout(300_000)
		admin = await asUser(ADMIN)

		// The portal must offer the mode she signs in with; the original list
		// goes back in afterAll.
		const mode = await offerSignInMode(admin, PORTAL, 'nextcloud')
		restoreModes = mode.restore
		const organisation = mode.organisation

		// The work she has to hand in is an assignment the school really
		// published: `Assignment.learnerRefs` is derived from the enrolments of
		// its group, so an assignment a suite invents has nobody on it. The
		// guardian suite's two children are left alone (po-parent-flows).
		const homework = await borrowPublishedHomework(admin, [
			'ee010008-0000-4000-8000-000000000415',
			'ee010008-0000-4000-8000-000000000467',
		])
		test.skip(
			homework === undefined,
			'this instance has no published assignment with pupils on it to borrow',
		)
		// Her own piece of work, in that group and open for another week, so
		// the hand-in is not refused by a window that closed long ago. Its
		// pupils are stamped from the group's enrolments, which is why the
		// group comes from an assignment the school really published.
		const cohortId = String(homework!.assignment.cohortId ?? '')
		test.skip(cohortId === '', 'the borrowed assignment names no group')
		assignmentTitle = `Werkstuk van deze run (${RUN})`
		const assignment = await createRow(admin, created, 'learniq', 'assignment', {
			title: assignmentTitle,
			instructions: 'Lever je werkstuk in als pdf.',
			maxPoints: 10,
			cohortId,
			courseId: COURSE_REF,
			dueAt: dueNextWeek(),
			allowLateSubmission: true,
			lifecycle: 'published',
			tenant_id: TENANT,
		})
		assignmentId = String(assignment.id)
		const refs: string[] = assignment.learnerRefs ?? []
		test.skip(
			refs.length === 0,
			'the borrowed group has no enrolled pupils to stamp on an assignment',
		)
		homework!.learnerRefs = refs.filter(
			(ref: string) =>
				[
					'ee010008-0000-4000-8000-000000000415',
					'ee010008-0000-4000-8000-000000000467',
				].includes(ref) === false,
		)

		// Her profile, and the school account it names. A pupil whose account
		// already exists is left alone: its password is not the suite's to set.
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
			'every pupil of the borrowed assignment already has a Nextcloud account',
		)
		otherProfileRef = homework!.learnerRefs.find(
			(ref: string) => ref !== profileRef,
		) as string
		const learnerId = String(profile!.ncUserId ?? '')
		PUPIL.user = learnerId
		PUPIL.name =
			`${profile!.givenName ?? ''} ${profile!.familyName ?? ''}`.trim()
		await createNextcloudAccount(admin, PUPIL, PUPIL.name)
		createdAccount = true
		console.log(
			`pupil-flow: ${PUPIL.user} (${profileRef}), tenant ${String(profile!.tenant_id ?? '')}, assignment ${assignmentId}`,
		)

		// Her draft, waiting to be handed in. A signed-in caller names the
		// learners by their Nextcloud ids and `SubmissionOwnerStamp` derives
		// `learnerRef` from them; only portaliq's own hand-in may send the
		// profile uuid instead.
		const draft = await createRow(admin, created, 'learniq', 'submission', {
			assignmentId,
			learnerIds: [learnerId],
			tenant_id: String(profile!.tenant_id ?? TENANT),
			lifecycle: 'draft',
		})
		submissionId = String(draft.id)

		const waiting = await createRow(admin, created, 'learniq', 'submission', {
			assignmentId,
			learnerIds: [learnerId],
			tenant_id: String(profile!.tenant_id ?? TENANT),
			lifecycle: 'draft',
		})
		waitingSubmissionId = String(waiting.id)

		// One mark of her own, on a subject this run names, so the assertions
		// never depend on what the example set happens to hold.
		await createRow(admin, created, 'learniq', 'grade-entry', {
			learnerId,
			curriculumPlanId: '00000000-0000-4000-8000-0000000000c1',
			componentId: GRADE_COMPONENT,
			gradeScaleId: '00000000-0000-4000-8000-0000000000d1',
			// `courseName` is a readable copy the server stamps from `courseId`
			// (ReadableCopyStamp); a value sent for it is replaced, so the
			// course is named by its id and the copy is asserted below.
			courseId: COURSE_REF,
			value: 7.5,
			weight: 1,
			period: 'P1',
			gradedAt: '2026-09-30T10:00:00+00:00',
			lifecycle: 'published',
			tenant_id: TENANT,
		})

		// Her BPV placement, and one on another pupil of the same group. Both
		// name a praktijkopleider the suite creates, so no real trainer's
		// portal gains a row.
		const supervisor = await createRow(
			admin,
			created,
			'learniq',
			'praktijkopleider',
			{
				givenName: 'Karin',
				familyName: `Smit (${RUN})`,
				email: `lq-e2e-${RUN}-opleider@example.org`,
				trainingCompanyName: COMPANY,
				trainingCompanyKvkNumber: '81234567',
				active: true,
				tenant_id: TENANT,
			},
		)
		const placementFor = async (
			ref: string,
			ncUserId: string,
			company: string,
		): Promise<string> => {
			const row = await createRow(admin, created, 'learniq', 'bpv-placement', {
				learnerId: ncUserId,
				learnerRef: ref,
				curriculumPlanId: '00000000-0000-4000-8000-0000000000c1',
				practicalTrainerId: String(supervisor.id),
				schoolCoachId: 'admin',
				trainingCompanyName: company,
				trainingCompanyKvkNumber: '81234567',
				periodFrom: '2026-09-01',
				periodTo: '2027-01-31',
				agreedHours: 640,
				lifecycle: 'active',
				tenant_id: TENANT,
			})
			return String(row.id)
		}

		placementId = await placementFor(profileRef, learnerId, COMPANY)
		// A second pupil of the same group, when the instance has one: without
		// it there is no foreign placement to point at, and step g says so
		// instead of passing on nothing.
		const other =
			otherProfileRef === undefined || otherProfileRef === ''
				? undefined
				: await borrowLearnerProfile(admin, otherProfileRef)
		if (other !== undefined && String(other.ncUserId ?? '') !== '') {
			foreignPlacementId = await placementFor(
				otherProfileRef,
				String(other.ncUserId),
				`Ander bedrijf (${RUN})`,
			)
		}

		// The claim her collections are scoped by. learniq's own invitations
		// write this; a suite has no `occ`, so it writes the same shape.
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
		await restoreModes()
		await removeRows(admin, created, 'pupil-flow')
		if (createdAccount === true) {
			await removeNextcloudAccount(admin, PUPIL.user)
		}
		await pupil?.page.close()
	})

	test('a. she lands on an overview that puts her work first', async () => {
		await shot(pupil.page, SHOTS, 'a1-overview')
		// Her homework is what she must hand in, and the overview puts it first.
		const homework = await portalRows(pupil, 'assignment', 'studentHomework')
		expect(homework.map((row) => String(row.id))).toContain(assignmentId)
		await expect(pupil.page.getByText(assignmentTitle).first()).toBeVisible({
			timeout: 20_000,
		})

		// Her short menu: the four pages site-pupil-portal-design keeps.
		for (const label of ['Inleveren', 'Cijfers', 'Toetsen', 'Afwezig melden']) {
			await expect(
				pupil.page.getByRole('link', { name: label, exact: true }).first(),
			).toBeVisible()
		}
		await shot(pupil.page, SHOTS, 'a2-menu')
	})

	test('b. her grades page names the subject and the mark', async () => {
		await openRoute(pupil.page, PORTAL, 'learniq/studentGrades')
		await expect(pupil.page.getByText(GRADE_COMPONENT).first()).toBeVisible({
			timeout: 20_000,
		})
		await shot(pupil.page, SHOTS, 'b1-grades')

		const grades = await portalRows(pupil, 'grade-entry', 'studentGrades')
		const mine = grades.filter((row) => row.componentId === GRADE_COMPONENT)
		expect(mine).toHaveLength(1)
		expect(mine[0].value).toBe(7.5)
		// She reads the subject as a name, not as a uuid: the readable copy the
		// server stamped from `courseId` (site-guardian-portal-design).
		expect(String(mine[0].courseName ?? '')).not.toBe('')
		await expect(
			pupil.page.getByText(String(mine[0].courseName)).first(),
		).toBeVisible()
		// Every row she reads is her own.
		expect(new Set(grades.map((row) => row.learnerRef))).toEqual(
			new Set([profileRef]),
		)
	})

	// WHAT THIS RUN FOUND, AND WHY IT IS MARKED RATHER THAN DELETED. The
	// pupil's hand-in is refused on a live instance: the portal forwards to
	// `POST /apps/learniq/api/portal/submissions/hand-in`
	// (PortalSubmissionController::handIn, `#[PublicPage]`, so no Nextcloud
	// session), and the answer is 422 `hand_in_refused`. The same transition
	// run as the pupil's own Nextcloud account succeeds, measured on the same
	// rows with `POST /apps/openregister/api/objects/{id}/transition` — so the
	// work, the deadline and the learner are all right, and what differs is
	// the session. The refusal comes from SubmissionWindowGuard::check()
	// through PortalSubmissionHandIn::handIn() (lib/Service/Portal, the
	// `$this->guard->check(...)` call): the guard's own reads run with
	// OpenRegister's RBAC on, while the sibling read in the same class passes
	// `_rbac: false, _multitenancy: false` with the comment "the receiver has
	// no session". Which of its two session-dependent branches denies is not
	// readable from the outside — the guard logs it at `info` and the
	// instance logs at `warning` — so the diagnosis stops here rather than
	// guessing, and the test stays as the thing that will prove the fix.
	test('c. she hands in her draft', async () => {
		const handIn = await pupil.page.request.post(
			`/apps/portaliq/portal/api/collections/learniq/submission/${submissionId}/actions/handIn`,
			{
				headers: { Authorization: `Bearer ${pupil.token}` },
				data: {},
			},
		)
		expect(handIn.status(), await handIn.text()).toBeLessThan(300)

		await expect
			.poll(
				async () =>
					(
						await portalRows(pupil, 'submission', 'studentSubmissions')
					).find((row) => String(row.id) === submissionId)?.lifecycle,
				{ timeout: 20_000 },
			)
			.not.toBe('draft')

		await openRoute(pupil.page, PORTAL, 'learniq/studentSubmissions')
		await shot(pupil.page, SHOTS, 'c1-handed-in')
	})

	test('c2. a draft she has not handed in is still waiting', async () => {
		await openRoute(pupil.page, PORTAL, 'learniq/studentSubmissions')
		await shot(pupil.page, SHOTS, 'c2-submissions')
		const mine = await portalRows(pupil, 'submission', 'studentSubmissions')

		// The one step c handed in reads as handed in, and the one nothing
		// touched is still a draft. Reading the first one here is what this
		// step used to do, and it passed only while the hand-in was broken.
		const handedIn = mine.find((row) => String(row.id) === submissionId)
		expect(handedIn, 'the draft step c handed in').toBeTruthy()
		expect(handedIn?.lifecycle).not.toBe('draft')

		const draft = mine.find((row) => String(row.id) === waitingSubmissionId)
		expect(draft, 'the draft nothing handed in').toBeTruthy()
		expect(draft?.lifecycle).toBe('draft')
		// Every submission she reads is her own.
		expect(new Set(mine.map((row) => row.learnerRef))).toEqual(
			new Set([profileRef]),
		)
	})

	// SECOND THING THIS RUN FOUND. Her absence report is refused on a live
	// instance: `POST /apps/portaliq/portal/api/collections/learniq/excuse-request`
	// answers 502 `write_failed`, and the instance log names the reason —
	// `{"app":"portaliq","schema":"excuse-request","reason":"An absence report
	// needs the pupil, who reports it and the school it belongs to."}`, which
	// is `excuse-owner-missing` in lib/Listener/ExcuseRequestOwnerStamp.php.
	// That refusal is only reachable through the listener's NON-portal branch,
	// so the payload it saw did not look like a portal report
	// (`isPortalReport()`: no Nextcloud session, no `learnerId`, and a
	// `learnerRef` naming the pupil). The guardian's identical report succeeds
	// (po-parent-flows b), and her action carries `learnerRef` in its own
	// `fields` while the pupil's relies on portaliq stamping the scope field.
	// Which of the three conditions fails is not readable from outside, so the
	// diagnosis stops at the two candidates rather than guessing.
	test('d. she reports herself absent through the form', async () => {
		const reason = `Griep (${RUN})`
		await openRoute(pupil.page, PORTAL, 'learniq/studentExcuseRequests')
		await shot(pupil.page, SHOTS, 'd1-absence-form')

		// THE CONTROL THAT NAMED THE BUG. The same request with the same bearer
		// from a context that carries no Nextcloud cookie: it succeeded while
		// the one from her own browser was refused, which is how the session —
		// and not the bearer, the claim or the payload — was identified as
		// what the listener was branching on (learniq, 4 October 2026). Both
		// must work; if only this one does, the old rule is back.
		const cookieless = await request.newContext({
			baseURL: process.env.PLAYWRIGHT_BASE_URL,
		})
		const withoutCookies = await cookieless.post(
			'/apps/portaliq/portal/api/collections/learniq/excuse-request',
			{
				headers: { Authorization: `Bearer ${pupil.token}` },
				data: {
					dateFrom: '2026-10-05',
					dateTo: '2026-10-06',
					reason: `Griep, zonder cookie (${RUN})`,
					reasonKind: 'illness',
				},
			},
		)
		expect(withoutCookies.status(), await withoutCookies.text()).toBeLessThan(
			300,
		)
		await cookieless.dispose()

		const sent = await pupil.page.request.post(
			'/apps/portaliq/portal/api/collections/learniq/excuse-request',
			{
				headers: { Authorization: `Bearer ${pupil.token}` },
				data: {
					dateFrom: '2026-10-05',
					dateTo: '2026-10-06',
					reason,
					reasonKind: 'illness',
				},
			},
		)
		expect(sent.status(), await sent.text()).toBeLessThan(300)

		const rows = await portalRows(
			pupil,
			'excuse-request',
			'studentExcuseRequests',
		)
		for (const row of rows) {
			if (String(row.reason ?? '').includes(RUN) === true) {
				created.push({
					register: 'learniq',
					schema: 'excuse-request',
					id: String(row.id),
				})
			}
		}

		const mine = rows.find((row) => row.reason === reason)
		expect(mine, 'her own absence report').toBeTruthy()
		// Portaliq stamps the learner from her claim, never from the body.
		expect(mine?.learnerRef).toBe(profileRef)
		// Her report is attributed to her, not to whoever happened to be
		// signed in: the pupil is the submitter of her own absence.
		expect(mine?.submittedByRef ?? profileRef).toBe(profileRef)

		// The page was drawn before she sent it, and the site does not push a
		// new row onto a list it already rendered; her own reload is what she
		// would do, and what this asserts.
		await openRoute(pupil.page, PORTAL, 'learniq/studentExcuseRequests')
		await expect(pupil.page.getByText(reason).first()).toBeVisible({
			timeout: 20_000,
		})
		await shot(pupil.page, SHOTS, 'd2-absence-sent')
	})

	test('d2. the form to report an absence names its fields', async () => {
		await openRoute(pupil.page, PORTAL, 'learniq/studentExcuseRequests')
		await shot(pupil.page, SHOTS, 'd2-absence-form')
		const form = pupil.page.getByRole('form').first()
		await expect(form).toBeVisible({ timeout: 20_000 })

		// Her form reads in words, not in field names. An instance whose
		// learniq predates those labels draws `dateFrom` and `reasonKind`, and
		// says so instead of failing: the labels are asserted on the manifest
		// by GuardianSitePagesTest, which runs in CI on this branch's code.
		const manifest = await pupil.page.request.get(
			'/apps/portaliq/portal/api/contributions',
			{ headers: { Authorization: `Bearer ${pupil.token}` } },
		)
		const actions = ((await manifest.json()).contributions ?? []).flatMap(
			(contribution: Record<string, any>) => contribution.actions ?? [],
		)
		const absence = actions.find(
			(action: Record<string, any>) => action.id === 'createExcuseRequest',
		)
		const label = String(absence?.fieldConfigs?.reason?.label ?? '')
		test.skip(
			label === '',
			"this instance's learniq draws the pupil's absence form with its field names",
		)
		await expect(form.getByText(label, { exact: false }).first()).toBeVisible()
	})

	test('e. another pupil is not hers to read', async () => {
		const foreign = await pupil.page.request.get(
			`/apps/portaliq/portal/api/collections/learniq/submission/${otherProfileRef}?collection=studentSubmissions`,
			{ headers: { Authorization: `Bearer ${pupil.token}` } },
		)
		expect(foreign.status()).toBeGreaterThanOrEqual(400)

		// And every row of every collection she may list is her own.
		for (const [schema, collection] of [
			['grade-entry', 'studentGrades'],
			['submission', 'studentSubmissions'],
			['attendance-record', 'studentAttendance'],
		]) {
			const rows = await portalRows(pupil, schema, collection)
			for (const row of rows) {
				expect(row.learnerRef).toBe(profileRef)
			}
		}
	})

	test('f. she enters a week of her own BPV hours', async () => {
		// An instance whose learniq predates internship-hours declares neither
		// the collection nor the action, and says so rather than failing for
		// its own build.
		const manifest = await pupil.page.request.get(
			'/apps/portaliq/portal/api/contributions',
			{ headers: { Authorization: `Bearer ${pupil.token}` } },
		)
		const actions = ((await manifest.json()).contributions ?? []).flatMap(
			(contribution: Record<string, any>) => contribution.actions ?? [],
		)
		hoursSupported = actions.some(
			(action: Record<string, any>) => action.id === 'submitHourWeek',
		)
		test.skip(
			hoursSupported === false || placementId === '',
			"this instance's learniq has no hour weeks, so it predates internship-hours",
		)

		// Her placement is what the form's picker reads, so it must be hers and
		// readable before the form can be filled at all.
		await openRoute(pupil.page, PORTAL, 'learniq/studentBpvPlacements')
		await shot(pupil.page, SHOTS, 'f1-placement')
		await expect(pupil.page.getByText(COMPANY).first()).toBeVisible({
			timeout: 20_000,
		})

		// Her placement list is hers alone. It is asserted here and not with
		// the other collections in step e, because an instance whose learniq
		// predates this change does not declare it and answers 403.
		const placements = await portalRows(
			pupil,
			'bpv-placement',
			'studentBpvPlacements',
		)
		expect(placements.map((row) => String(row.id))).toContain(placementId)
		expect(new Set(placements.map((row) => row.learnerRef))).toEqual(
			new Set([profileRef]),
		)

		// What her form sends: the placement, the week, the hours. Who she is,
		// when she sent it and which school it belongs to are the server's
		// (HourWeekSubmissionStamp), which is why they are not in the body.
		const sent = await pupil.page.request.post(
			'/apps/portaliq/portal/api/collections/learniq/bpv-hour-week',
			{
				headers: { Authorization: `Bearer ${pupil.token}` },
				data: {
					bpvPlacementId: placementId,
					isoWeek: ISO_WEEK,
					hoursSubmitted: HOURS_WORKED,
				},
			},
		)
		expect(sent.status(), await sent.text()).toBeLessThan(300)

		const weeks = await portalRows(pupil, 'bpv-hour-week', 'studentHourWeeks')
		for (const row of weeks) {
			if (String(row.isoWeek ?? '') === ISO_WEEK) {
				created.push({
					register: 'learniq',
					schema: 'bpv-hour-week',
					id: String(row.id),
				})
			}
		}

		const mine = weeks.find((row) => String(row.isoWeek ?? '') === ISO_WEEK)
		expect(mine, 'the week she just entered').toBeTruthy()
		expect(Number(mine?.hoursSubmitted)).toBe(HOURS_WORKED)
		expect(mine?.learnerRef).toBe(profileRef)
		// She reads WHEN she sent it, which is what answers "have I actually
		// handed in this week?" while it waits for her trainer.
		expect(String(mine?.submittedAt ?? '')).not.toBe('')
		// But not WHO sent it: on her own page that is always herself, so the
		// collection does not project it. It is asserted below, from an admin
		// read, where it is evidence rather than decoration.
		expect(mine?.submittedBy).toBeUndefined()
		// Nobody has decided it yet, so her trainer's number is still empty.
		expect(mine?.lifecycle).toBe('submitted')
		expect(mine?.hoursApproved ?? null).toBeFalsy()

		// WHAT THIS PROVES, AND WHY IT IS READ AS ADMIN. HourWeekSubmissionStamp
		// is the only thing that writes `submittedBy`, `submittedAt`,
		// `learnerRef` and `tenant_id`, all four from the placement the week
		// names. `tenant_id` is required by the schema and sent by nobody, so
		// if the stamp ever stops running every submission above is refused
		// outright. None of that is visible through her own collection, so
		// without this read the stamp would have a unit test and no live
		// evidence at all.
		const stored = await admin.get(
			`/apps/openregister/api/objects/learniq/bpv-hour-week/${String(mine?.id)}`,
		)
		expect(stored.status(), await stored.text()).toBe(200)
		const row = await stored.json()
		expect(row.submittedBy).toBe(profileRef)
		expect(String(row.submittedAt ?? '')).not.toBe('')
		expect(row.learnerRef).toBe(profileRef)
		expect(String(row.tenant_id ?? '')).toBe(TENANT)
		// Every week she reads is her own.
		expect(new Set(weeks.map((row) => row.learnerRef))).toEqual(
			new Set([profileRef]),
		)

		// The page was drawn before she sent it, and the site does not push a
		// new row onto a list it already rendered; her own reload is what she
		// would do, and what this asserts.
		await openRoute(pupil.page, PORTAL, 'learniq/studentHourWeeks')
		await expect(pupil.page.getByText(ISO_WEEK).first()).toBeVisible({
			timeout: 20_000,
		})
		await shot(pupil.page, SHOTS, 'f2-hours-sent')
	})

	test("g. a week on another pupil's placement is refused", async () => {
		test.skip(
			hoursSupported === false || foreignPlacementId === '',
			'this build has no hour weeks, or the instance had no second pupil to put another placement on',
		)

		// Portaliq stamps her own learnerRef, so without the cross-reference
		// guard this would be stored: her hours on another student's placement,
		// and the rollup would add them to that placement's total.
		const foreign = await pupil.page.request.post(
			'/apps/portaliq/portal/api/collections/learniq/bpv-hour-week',
			{
				headers: { Authorization: `Bearer ${pupil.token}` },
				data: {
					bpvPlacementId: foreignPlacementId,
					isoWeek: '2026-W41',
					hoursSubmitted: 8,
				},
			},
		)
		expect(foreign.status(), await foreign.text()).toBeGreaterThanOrEqual(400)

		// And nothing of hers was created by the attempt.
		const weeks = await portalRows(pupil, 'bpv-hour-week', 'studentHourWeeks')
		expect(
			weeks.filter((row) => String(row.isoWeek ?? '') === '2026-W41'),
		).toHaveLength(0)
	})
})
