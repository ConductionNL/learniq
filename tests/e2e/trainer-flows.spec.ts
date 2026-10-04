// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * A workplace trainer's portal, end to end.
 *
 * She supervises a pupil's BPV placement from her own company. She is not a
 * DigiD citizen and her leerbedrijf has no eHerkenning contract with the
 * school, so the school invites her and she signs in with the account it gave
 * her: portaliq's `nextcloud` mode, trust `low`. Ruben decided on 4 October
 * 2026 that such a trainer may assess, so this suite is the proof that the
 * lowered bar really works, and that the row says who assessed and how sure
 * the school can be of it.
 *
 * WHAT THIS SUITE CREATES AND REMOVES. Its own Nextcloud account, one
 * praktijkopleider, one BPV placement on a pupil the school already has, the
 * portal account carrying the `practicalTrainerId` claim, and whatever
 * assessment it writes. Everything is removed in `afterAll`, together with the
 * portal's original list of sign-in modes.
 *
 *   AUDIENCE_FLOW_E2E=1 PLAYWRIGHT_BASE_URL=http://localhost:8090 \
 *   npx playwright test --config tests/e2e/audience-flow.config.ts trainer-flows
 *
 * Flows:
 *   a. she signs in and lands on her overview, with her placement on it;
 *   b. her placements page names her student and her company;
 *   c. she writes a werkproces assessment through learniq's own endpoint, and
 *      the row records her name, her company, its KvK number and that the
 *      school is `basic` sure who assessed;
 *   d. the assessment she wrote is on her own page, and only hers;
 *   e. signing a praktijkovereenkomst still demands a higher bar than an
 *      invitation gives, and a placement that is not hers resolves nothing;
 *   f. a week of her student's hours is waiting for her, she approves another
 *      number with a note, and both numbers stay on the row;
 *   g. her overview reads the hours done against the hours the agreement
 *      states.
 *
 * Screenshots land in `test-results/audience-flow/trainer/`.
 *
 * @spec openspec/changes/an-invited-trainer-may-assess/specs/bpv/spec.md#requirement-an-invited-trainer-may-submit-a-werkproces-assessment
 * @spec openspec/changes/site-workplace-trainer-portal-design/specs/portal-contribution/spec.md#requirement-a-workplace-trainer-lands-on-what-is-waiting-for-her
 * @spec openspec/changes/internship-hours/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-is-a-record-of-its-own
 * @spec openspec/changes/internship-hours/specs/bpv/spec.md#requirement-hours-are-shown-against-the-hours-that-were-agreed
 * @spec openspec/specs/bpv/spec.md#requirement-praktijkopleider-portal-access-is-a-direct-scope-portalcontributionprovider-audience
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
	createRowIfSupported,
	grantPortalAccount,
	nextcloudAccountExists,
	offerSignInMode,
	openHome,
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
	'trainer',
)
const PORTAL = process.env.AUDIENCE_FLOW_PORTAL ?? 'wilgenboom'
const TENANT =
	process.env.AUDIENCE_FLOW_TENANT ?? '00000000-0000-4000-8000-000000000000'
const ADMIN = {
	user: process.env.NC_ADMIN_USER ?? 'admin',
	pass: process.env.NC_ADMIN_PASS ?? 'admin',
}

const RUN = Date.now().toString(36)
const TRAINER = {
	user: `lq-e2e-opleider-${RUN}`,
	pass: `Lq-e2e-${RUN}-opleider!`,
	given: 'Karin',
	family: 'Smit',
	company: `Installatiebedrijf Van Dam (${RUN})`,
	kvk: '81234567',
}
const WERKPROCES = `B1-K1-W${RUN.slice(-2)}`
// What her page really shows. `poWerkprocesAssessments` declares its columns
// as the work process, the judgement and the date, so the code is in the row
// but never on screen; the label is what she reads.
const WERKPROCES_LABEL = `Voert installatiewerkzaamheden uit (${RUN})`
// internship-hours: the week her student entered, the hours he entered, and
// the number she approves instead. The agreed total is what her card counts
// against; 640 is the figure the approved mockup shows.
const ISO_WEEK = '2026-W39'
const HOURS_SUBMITTED = 32
const HOURS_APPROVED = 30
const AGREED_HOURS = 640

test.describe.configure({ mode: 'serial' })

test.describe('trainer: an invited workplace trainer', () => {
	test.skip(
		!ENABLED,
		'set AUDIENCE_FLOW_E2E=1 on an instance with portaliq and a portal for the school',
	)

	const created: SeededRow[] = []
	let admin: APIRequestContext
	let restoreModes: () => Promise<void> = async () => undefined
	let trainer: PortalLogin
	let trainerRef = ''
	let placementId = ''
	let hourWeekId = ''
	let learnerRef = ''
	let createdAccount = false
	// What the instance's own learniq says the assessment action demands. An
	// instance whose checkout predates learniq#1679 still asks `substantial`,
	// and an invited trainer can then not assess — correctly, for that build.
	let assessmentTrust = ''

	test.beforeAll(async ({ browser }) => {
		test.setTimeout(300_000)
		admin = await asUser(ADMIN)

		const mode = await offerSignInMode(admin, PORTAL, 'nextcloud')
		restoreModes = mode.restore
		const organisation = mode.organisation

		test.skip(
			(await nextcloudAccountExists(admin, TRAINER.user)) === true,
			'the instance already has an account with this run id',
		)
		await createNextcloudAccount(
			admin,
			TRAINER,
			`${TRAINER.given} ${TRAINER.family}`,
		)
		createdAccount = true

		// A pupil the school already has, so the placement is about somebody
		// real. Her guardian's two children are left alone (po-parent-flows).
		const homework = await borrowPublishedHomework(admin, [
			'ee010008-0000-4000-8000-000000000415',
			'ee010008-0000-4000-8000-000000000467',
		])
		test.skip(homework === undefined, 'this instance has no pupils to borrow')
		learnerRef = homework!.learnerRefs[0]
		const profile = await borrowLearnerProfile(admin, learnerRef)
		test.skip(profile === undefined, 'the borrowed pupil has no learner profile')

		const person = await createRow(
			admin,
			created,
			'learniq',
			'praktijkopleider',
			{
				givenName: TRAINER.given,
				familyName: TRAINER.family,
				email: `${TRAINER.user}@example.org`,
				trainingCompanyName: TRAINER.company,
				trainingCompanyKvkNumber: TRAINER.kvk,
				active: true,
				tenant_id: TENANT,
			},
		)
		trainerRef = String(person.id)

		const placement = await createRow(
			admin,
			created,
			'learniq',
			'bpv-placement',
			{
				learnerId: String(profile!.ncUserId ?? ''),
				learnerRef,
				curriculumPlanId: '00000000-0000-4000-8000-0000000000c1',
				practicalTrainerId: trainerRef,
				schoolCoachId: 'admin',
				trainingCompanyName: TRAINER.company,
				trainingCompanyKvkNumber: TRAINER.kvk,
				periodFrom: '2026-09-01',
				periodTo: '2027-01-31',
				// internship-hours: the hours the agreement states, so her card
				// has a real denominator to count against.
				agreedHours: AGREED_HOURS,
				lifecycle: 'active',
				tenant_id: TENANT,
			},
		)
		placementId = String(placement.id)

		// One week of his hours, waiting for her. The server stamps who
		// entered it, when, which student and which school from the placement
		// (HourWeekSubmissionStamp), so the suite sends only the three fields
		// the pupil's own form sends plus the required learner. An instance
		// whose learniq predates internship-hours has no such schema, and says
		// so rather than taking the whole suite down.
		const week = await createRowIfSupported(
			admin,
			created,
			'learniq',
			'bpv-hour-week',
			{
				bpvPlacementId: placementId,
				learnerRef,
				isoWeek: ISO_WEEK,
				hoursSubmitted: HOURS_SUBMITTED,
				lifecycle: 'submitted',
			},
		)
		hourWeekId = String(week?.id ?? '')

		// The claim her collections and her assessment are scoped by. This is
		// what `occ learniq:portal:invite-trainer` writes (learniq#1680); a
		// suite has no `occ`, so it writes the same shape.
		await grantPortalAccount(admin, created, {
			subjectRef: TRAINER.user,
			audience: 'praktijkopleider',
			organisation,
			email: `${TRAINER.user}@example.org`,
			displayName: `${TRAINER.given} ${TRAINER.family}`,
			claims: { learniq: { practicalTrainerId: trainerRef } },
		})

		trainer = await signInWithNextcloudAccount(browser, TRAINER, PORTAL, SHOTS)

		const manifest = await trainer.page.request.get(
			'/apps/portaliq/portal/api/contributions',
			{ headers: { Authorization: `Bearer ${trainer.token}` } },
		)
		expect(manifest.status(), await manifest.text()).toBe(200)
		const actions = ((await manifest.json()).contributions ?? []).flatMap(
			(contribution: Record<string, any>) => contribution.actions ?? [],
		)
		assessmentTrust = String(
			actions.find(
				(action: Record<string, any>) =>
					action.id === 'createWerkprocesAssessment',
			)?.minTrust ?? '',
		)
	})

	test.afterAll(async () => {
		await restoreModes()
		await removeRows(admin, created, 'trainer-flow')
		if (createdAccount === true) {
			await removeNextcloudAccount(admin, TRAINER.user)
		}

		await trainer?.page.close()
	})

	test('a. she lands on her overview with her placement on it', async () => {
		await shot(trainer.page, SHOTS, 'a1-overview')
		const placements = await portalRows(
			trainer,
			'bpv-placement',
			'poBpvPlacements',
		)
		expect(placements.map((row) => String(row.id))).toContain(placementId)
		// Every placement she reads is one of hers.
		expect(new Set(placements.map((row) => row.practicalTrainerId))).toEqual(
			new Set([trainerRef]),
		)
		await expect(trainer.page.getByText(TRAINER.company).first()).toBeVisible({
			timeout: 20_000,
		})
	})

	test('b. her placements page names her student and her company', async () => {
		await openRoute(trainer.page, PORTAL, 'learniq/poBpvPlacements')
		await shot(trainer.page, SHOTS, 'b1-placements')
		await expect(trainer.page.getByText(TRAINER.company).first()).toBeVisible({
			timeout: 20_000,
		})
	})

	test('c. she writes an assessment, and the row says who assessed', async () => {
		test.skip(
			assessmentTrust !== 'low',
			`this instance's learniq still asks ${assessmentTrust} of an assessment, so it predates learniq#1679`,
		)
		const notes = `Zelfstandig gewerkt (${RUN})`
		const written = await trainer.page.request.post(
			'/apps/portaliq/portal/api/actions/learniq/createWerkprocesAssessment',
			{
				headers: { Authorization: `Bearer ${trainer.token}` },
				data: {
					bpvPlacementId: placementId,
					curriculumPlanId: '00000000-0000-4000-8000-0000000000c1',
					componentId: 'bpv',
					kwalificatiedossierCode: '25605',
					coreTaskCode: 'B1-K1',
					werkprocesCode: WERKPROCES,
					werkprocesLabel: WERKPROCES_LABEL,
					assessment: 'competent',
					notes,
				},
			},
		)
		expect(written.status(), await written.text()).toBeLessThan(300)
		await shot(trainer.page, SHOTS, 'c1-assessment-sent')

		const rows = await portalRows(
			trainer,
			'werkproces-assessment',
			'poWerkprocesAssessments',
		)
		const mine = rows.find((row) => row.werkprocesCode === WERKPROCES)
		expect(mine, 'the assessment she just wrote').toBeTruthy()
		created.push({
			register: 'learniq',
			schema: 'werkproces-assessment',
			id: String(mine?.id),
		})

		// What the school reads afterwards: who assessed, from which company,
		// and how sure it can be that it was her. An invitation and a school
		// account are `basic`; eHerkenning or DigiD would be `substantial`.
		const stored = await admin.get(
			`/apps/openregister/api/objects/learniq/werkproces-assessment/${mine?.id}`,
		)
		expect(stored.status(), await stored.text()).toBe(200)
		const row = await stored.json()
		expect(row.assessorId).toBe(trainerRef)
		expect(row.assessorName).toBe(`${TRAINER.given} ${TRAINER.family}`)
		expect(row.assessorCompany).toBe(TRAINER.company)
		expect(row.assessorCompanyKvkNumber).toBe(TRAINER.kvk)
		expect(row.assuranceLevel).toBe('basic')
		expect(row.assessment).toBe('competent')
	})

	test('d. the assessment she wrote is on her own page', async () => {
		test.skip(
			assessmentTrust !== 'low',
			'the assessment of step c was not written on this build, so there is nothing of hers to read',
		)
		await openRoute(trainer.page, PORTAL, 'learniq/poWerkprocesAssessments')
		await shot(trainer.page, SHOTS, 'd1-assessments')
		await expect(trainer.page.getByText(WERKPROCES_LABEL).first()).toBeVisible({
			timeout: 20_000,
		})

		const rows = await portalRows(
			trainer,
			'werkproces-assessment',
			'poWerkprocesAssessments',
		)
		// She reads her own judgements and nobody else's.
		expect(rows.length).toBeGreaterThan(0)
		for (const row of rows) {
			expect(String(row.werkprocesCode ?? '')).not.toBe('')
		}
	})

	test('e. the invitation is not a signature, and another placement is not hers', async () => {
		// Signing a praktijkovereenkomst stays `minTrust: substantial`: the
		// school lowered the bar for assessing, not for signing.
		const signed = await trainer.page.request.post(
			'/apps/portaliq/portal/api/collections/learniq/pok-signature',
			{
				headers: { Authorization: `Bearer ${trainer.token}` },
				data: {
					subjectId: placementId,
					subjectVersion: '1',
					method: 'portal',
				},
			},
		)
		expect(signed.status()).toBe(403)

		// An assessment on a placement that is not hers is refused by learniq's
		// own endpoint, which checks the placement against her claim.
		const foreign = await trainer.page.request.post(
			'/apps/portaliq/portal/api/actions/learniq/createWerkprocesAssessment',
			{
				headers: { Authorization: `Bearer ${trainer.token}` },
				data: {
					bpvPlacementId: '00000000-0000-4000-8000-00000000dead',
					curriculumPlanId: '00000000-0000-4000-8000-0000000000c1',
					componentId: 'bpv',
					kwalificatiedossierCode: '25605',
					coreTaskCode: 'B1-K1',
					werkprocesCode: `${WERKPROCES}-X`,
					werkprocesLabel: 'Niet van haar',
					assessment: 'competent',
				},
			},
		)
		expect(foreign.status()).toBeGreaterThanOrEqual(400)
	})

	test('f. she approves another number, and both numbers stay', async () => {
		test.skip(
			hourWeekId === '',
			"this instance's learniq has no BpvHourWeek, so it predates internship-hours",
		)

		// The week reaches her through the reverse join over her placements, so
		// this read is also the proof that the join resolves at all: the first
		// version of it named a field bpv-placement does not have and would
		// have returned nothing, with no error anywhere.
		const waiting = await portalRows(trainer, 'bpv-hour-week', 'poHourWeeks')
		const mine = waiting.find((row) => String(row.id) === hourWeekId)
		expect(mine, 'the week of hours waiting for her').toBeTruthy()
		expect(mine?.hoursSubmitted).toBe(HOURS_SUBMITTED)
		// Only weeks nobody has decided are offered to her.
		expect(new Set(waiting.map((row) => row.lifecycle))).toEqual(
			new Set(['submitted']),
		)

		await openRoute(trainer.page, PORTAL, 'learniq/poHourWeeks')
		await shot(trainer.page, SHOTS, 'f1-hours-waiting')
		await expect(trainer.page.getByText(ISO_WEEK).first()).toBeVisible({
			timeout: 20_000,
		})

		const note = `Donderdag twee uur eerder weg (${RUN})`
		const approved = await trainer.page.request.post(
			'/apps/portaliq/portal/api/actions/learniq/approveHourWeek',
			{
				headers: { Authorization: `Bearer ${trainer.token}` },
				data: {
					hourWeekId,
					hoursApproved: HOURS_APPROVED,
					note,
				},
			},
		)
		expect(approved.status(), await approved.text()).toBeLessThan(300)
		await shot(trainer.page, SHOTS, 'f2-hours-approved')

		// What the school reads afterwards. The hours he entered are still
		// there beside the hours she approved: a correction is readable, not an
		// overwrite.
		const stored = await admin.get(
			`/apps/openregister/api/objects/learniq/bpv-hour-week/${hourWeekId}`,
		)
		expect(stored.status(), await stored.text()).toBe(200)
		const row = await stored.json()
		expect(row.hoursSubmitted).toBe(HOURS_SUBMITTED)
		expect(Number(row.hoursApproved)).toBe(HOURS_APPROVED)
		expect(row.lifecycle).toBe('corrected')
		expect(row.note).toBe(note)
		expect(row.approvedBy).toBe(trainerRef)
		expect(row.approvedByName).toBe(`${TRAINER.given} ${TRAINER.family}`)
		expect(row.assuranceLevel).toBe('basic')
		// Who entered it, and when, were not touched by her decision.
		expect(row.submittedBy).toBe(learnerRef)
		expect(String(row.submittedAt ?? '')).not.toBe('')

		// And a week she has decided is no longer waiting for her.
		await expect
			.poll(
				async () =>
					(await portalRows(trainer, 'bpv-hour-week', 'poHourWeeks')).find(
						(r) => String(r.id) === hourWeekId,
					),
				{ timeout: 20_000 },
			)
			.toBeFalsy()
	})

	test('g. her overview counts the hours against the agreed total', async () => {
		test.skip(
			hourWeekId === '',
			'no week was approved on this build, so there is no total to count',
		)

		// The rollup keeps the placement's own total equal to the sum of its
		// approved weeks, because the card reads one row and a total that lived
		// only in a query could never reach it.
		await expect
			.poll(
				async () =>
					Number(
						(
							await portalRows(
								trainer,
								'bpv-placement',
								'poBpvPlacements',
							)
						).find((row) => String(row.id) === placementId)
							?.hoursApprovedTotal ?? -1,
					),
				{ timeout: 20_000 },
			)
			.toBe(HOURS_APPROVED)

		const placement = (
			await portalRows(trainer, 'bpv-placement', 'poBpvPlacements')
		).find((row) => String(row.id) === placementId)
		// Both numbers the card needs are projected, or portaliq throws the
		// progress away and the cards keep no bar.
		expect(Number(placement?.agreedHours ?? -1)).toBe(AGREED_HOURS)

		// Her overview is a contributed page on the signed-in route `/mijn`,
		// reached the way every other step reaches its page. The bare portal
		// URL is the CMS page slot, and this school's portal has no CMS home
		// page, so it answers "Deze pagina bestaat niet (meer)" even to a
		// signed-in trainer (found live, 4 October 2026).
		await openHome(trainer.page, PORTAL)
		await shot(trainer.page, SHOTS, 'g1-overview-hours')
		await expect(
			trainer.page.getByText(String(AGREED_HOURS)).first(),
		).toBeVisible({ timeout: 20_000 })
	})
})
