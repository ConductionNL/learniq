// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * An external assessor's portal, end to end.
 *
 * He assesses a candidate's portfolio for an examination board, from outside
 * the school. The school invites him and he signs in with the account it gave
 * him (portaliq's `nextcloud` mode, trust `low`), exactly as the workplace
 * trainer does. His portal is read only: the manifest gives him no action at
 * all, and what he may read is one list of the shares granted to him.
 *
 * WHAT THIS SUITE CREATES AND REMOVES. Its own Nextcloud account, one external
 * assessor, one portfolio with an entry, three shares (one active, one
 * revoked, one that belongs to somebody else) and the portal account carrying
 * the `externalAssessorId` claim. All of it is removed in `afterAll`, together
 * with the portal's original list of sign-in modes.
 *
 *   AUDIENCE_FLOW_E2E=1 PLAYWRIGHT_BASE_URL=http://localhost:8090 \
 *   npx playwright test --config tests/e2e/audience-flow.config.ts assessor-flows
 *
 * Flows:
 *   a. he signs in and lands on an overview listing what is shared with him,
 *      by candidate, portfolio and the date his access runs to;
 *   b. a revoked share grants nothing, and neither does another assessor's;
 *   c. his portal offers him one action, the practical exam form, and a
 *      write he was never offered is refused;
 *   and, in the second block (practical-exam-assessment T9):
 *   d. his day lists only the candidates he is assigned to, not another
 *      assessor's sitting;
 *   e. he fills the form, and the stored assessment names him and records
 *      `assuranceLevel: basic`;
 *   f. a sitting he is not assigned to resolves nothing and takes no
 *      assessment from him;
 *   g. the second assessor signs (fixme: the portal declares no signing
 *      action yet, so nothing in portaliq reaches the signatures endpoint).
 *
 * Screenshots land in `test-results/audience-flow/assessor/`.
 *
 * @spec openspec/specs/portal-contribution/spec.md#requirement-an-external-assessor-lands-on-what-is-shared-with-him-and-until-when
 * @spec openspec/specs/portal-contribution/spec.md#requirement-new-a-share-names-its-candidate-and-portfolio
 * @spec openspec/specs/eportfolio/spec.md#requirement-bpv-praktijkopleider-and-external-assessor-sharing-reuse-the-adr-046-portal-audience-mechanism
 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-an-assessor-is-linked-to-an-exam-sitting-by-a-record
 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-a-practical-exam-assessment-records-who-assessed-and-how-sure-the-school-is
 *
 * Covers:
 *   @e2e openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#scenario-his-day-lists-only-his-own-candidates
 *   @e2e openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#scenario-a-sitting-he-is-not-assigned-to-grants-nothing
 *   @e2e openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#scenario-an-invited-assessor-fills-the-form
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
	openRoute,
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
	'assessor',
)
const PORTAL = process.env.AUDIENCE_FLOW_PORTAL ?? 'wilgenboom'
const TENANT =
	process.env.AUDIENCE_FLOW_TENANT ?? '00000000-0000-4000-8000-000000000000'
const ADMIN = {
	user: process.env.NC_ADMIN_USER ?? 'admin',
	pass: process.env.NC_ADMIN_PASS ?? 'admin',
}

const RUN = Date.now().toString(36)
const ASSESSOR = {
	user: `lq-e2e-beoordelaar-${RUN}`,
	pass: `Lq-e2e-${RUN}-beoordelaar!`,
	given: 'Ruud',
	family: 'Jansen',
	organisationName: `Examinering Zuiddrecht (${RUN})`,
}
const PORTFOLIO_TITLE = `Examenportfolio installatietechniek (${RUN})`

test.describe.configure({ mode: 'serial' })

test.describe('assessor: a portfolio shared with an external assessor', () => {
	test.skip(
		!ENABLED,
		'set AUDIENCE_FLOW_E2E=1 on an instance with portaliq and a portal for the school',
	)

	const created: SeededRow[] = []
	let admin: APIRequestContext
	let assessor: PortalLogin
	let assessorRef = ''
	let activeShareId = ''
	let revokedShareId = ''
	let foreignShareId = ''
	let createdAccount = false

	test.beforeAll(async ({ browser }) => {
		test.setTimeout(300_000)
		admin = await asUser(ADMIN)

		const mode = await requireSignInMode(admin, PORTAL, 'nextcloud')
		const organisation = mode.organisation

		test.skip(
			(await nextcloudAccountExists(admin, ASSESSOR.user)) === true,
			'the instance already has an account with this run id',
		)
		await createNextcloudAccount(
			admin,
			ASSESSOR,
			`${ASSESSOR.given} ${ASSESSOR.family}`,
		)
		createdAccount = true

		const homework = await borrowPublishedHomework(admin, [
			'ee010008-0000-4000-8000-000000000415',
			'ee010008-0000-4000-8000-000000000467',
		])
		test.skip(homework === undefined, 'this instance has no pupils to borrow')
		const learnerRef = homework!.learnerRefs[0]
		const profile = await borrowLearnerProfile(admin, learnerRef)
		test.skip(profile === undefined, 'the borrowed candidate has no profile')
		const learnerId = String(profile!.ncUserId ?? '')

		const person = await createRow(
			admin,
			created,
			'learniq',
			'external-assessor',
			{
				givenName: ASSESSOR.given,
				familyName: ASSESSOR.family,
				email: `${ASSESSOR.user}@example.org`,
				organisationName: ASSESSOR.organisationName,
				active: true,
				tenant_id: TENANT,
			},
		)
		assessorRef = String(person.id)

		const portfolio = await createRow(admin, created, 'learniq', 'portfolio', {
			learnerId,
			learnerRef,
			kind: 'personal',
			title: PORTFOLIO_TITLE,
			lifecycle: 'active',
			tenant_id: TENANT,
		})

		const entry = await createRow(admin, created, 'learniq', 'portfolio-entry', {
			portfolioId: String(portfolio.id),
			learnerId,
			title: 'Werkplekopdracht: meterkast vervangen',
			evidenceKind: 'reflection',
			reflectionText: 'Ik heb de meterkast zelfstandig vervangen.',
			tenant_id: TENANT,
		})

		// What he may read: one active grant, until a date he can see.
		const active = await createRow(
			admin,
			created,
			'learniq',
			'portfolio-share',
			{
				portfolioId: String(portfolio.id),
				entryIds: [String(entry.id)],
				sharedWithKind: 'external-assessor',
				sharedWithExternalAssessorId: assessorRef,
				sharedBy: 'admin',
				expiresAt: '2026-12-31T23:59:59+00:00',
				lifecycle: 'active',
				tenant_id: TENANT,
			},
		)
		activeShareId = String(active.id)

		// What he may not: a grant the school took back.
		const revoked = await createRow(
			admin,
			created,
			'learniq',
			'portfolio-share',
			{
				portfolioId: String(portfolio.id),
				sharedWithKind: 'external-assessor',
				sharedWithExternalAssessorId: assessorRef,
				sharedBy: 'admin',
				expiresAt: '2026-12-31T23:59:59+00:00',
				lifecycle: 'revoked',
				tenant_id: TENANT,
			},
		)
		revokedShareId = String(revoked.id)

		// And what belongs to another assessor entirely.
		const other = await createRow(
			admin,
			created,
			'learniq',
			'external-assessor',
			{
				givenName: 'Iemand',
				familyName: 'Anders',
				email: `lq-e2e-ander-${RUN}@example.org`,
				organisationName: 'Een ander bureau',
				active: true,
				tenant_id: TENANT,
			},
		)
		const foreign = await createRow(
			admin,
			created,
			'learniq',
			'portfolio-share',
			{
				portfolioId: String(portfolio.id),
				sharedWithKind: 'external-assessor',
				sharedWithExternalAssessorId: String(other.id),
				sharedBy: 'admin',
				expiresAt: '2026-12-31T23:59:59+00:00',
				lifecycle: 'active',
				tenant_id: TENANT,
			},
		)
		foreignShareId = String(foreign.id)

		// The claim his list is scoped by. This is what
		// `occ learniq:portal:invite-assessor` writes (learniq#1680).
		await grantPortalAccount(admin, created, {
			subjectRef: ASSESSOR.user,
			audience: 'external-assessor',
			organisation,
			email: `${ASSESSOR.user}@example.org`,
			displayName: `${ASSESSOR.given} ${ASSESSOR.family}`,
			claims: { learniq: { externalAssessorId: assessorRef } },
		})

		assessor = await signInWithNextcloudAccount(browser, ASSESSOR, PORTAL, SHOTS)
	})

	test.afterAll(async () => {
		await removeRows(admin, created, 'assessor-flow')
		if (createdAccount === true) {
			await removeNextcloudAccount(admin, ASSESSOR.user)
		}

		await assessor?.page.close()
	})

	test('a. he lands on what is shared with him, and until when', async () => {
		await shot(assessor.page, SHOTS, 'a1-overview')
		const shares = await portalRows(
			assessor,
			'portfolio-share',
			'eaSharedPortfolios',
		)
		expect(shares.map((row) => String(row.id))).toContain(activeShareId)

		// The share names its candidate and its portfolio, so he reads a person
		// rather than a uuid. Both are readable copies the server stamps.
		const mine = shares.find((row) => String(row.id) === activeShareId)
		expect(String(mine?.portfolioTitle ?? '')).toBe(PORTFOLIO_TITLE)
		expect(String(mine?.learnerName ?? '')).not.toBe('')
		await expect(assessor.page.getByText(PORTFOLIO_TITLE).first()).toBeVisible({
			timeout: 20_000,
		})
		await expect(
			assessor.page.getByText(String(mine?.learnerName)).first(),
		).toBeVisible()
	})

	test('b. a revoked share grants nothing, and neither does another assessor s', async () => {
		const shares = await portalRows(
			assessor,
			'portfolio-share',
			'eaSharedPortfolios',
		)
		const ids = shares.map((row) => String(row.id))
		expect(ids).not.toContain(revokedShareId)
		expect(ids).not.toContain(foreignShareId)
		// Every row he reads is granted to him and still active.
		for (const row of shares) {
			expect(row.lifecycle).toBe('active')
		}

		for (const id of [revokedShareId, foreignShareId]) {
			const direct = await assessor.page.request.get(
				`/apps/portaliq/portal/api/collections/learniq/portfolio-share/${id}?collection=eaSharedPortfolios`,
				{ headers: { Authorization: `Bearer ${assessor.token}` } },
			)
			expect(direct.status()).toBeGreaterThanOrEqual(400)
		}

		await openRoute(assessor.page, PORTAL, 'learniq/eaSharedPortfolios')
		await shot(assessor.page, SHOTS, 'b1-shares')
	})

	test('c. his portal offers him the practical exam form and nothing else', async () => {
		const manifest = await assessor.page.request.get(
			'/apps/portaliq/portal/api/contributions',
			{ headers: { Authorization: `Bearer ${assessor.token}` } },
		)
		expect(manifest.status(), await manifest.text()).toBe(200)
		const body = await manifest.json()
		expect(body.audience).toBe('external-assessor')
		// practical-exam-assessment gave him one action: the assessment form.
		const actions = (body.contributions ?? []).flatMap(
			(contribution: { actions?: Array<{ id: string }> }) => (contribution.actions ?? []).map((action) => action.id),
		)
		expect(actions).toEqual(['assessPracticalExam'])

		// And a write he was never offered is refused rather than stored.
		const written = await assessor.page.request.post(
			'/apps/portaliq/portal/api/collections/learniq/portfolio-share',
			{
				headers: { Authorization: `Bearer ${assessor.token}` },
				data: {
					portfolioId: '00000000-0000-4000-8000-00000000dead',
					sharedWithKind: 'external-assessor',
					sharedBy: ASSESSOR.user,
				},
			},
		)
		expect(written.status()).toBeGreaterThanOrEqual(400)
	})
})

const EXAM_ASSESSOR = {
	user: `lq-e2e-examinator-${RUN}`,
	pass: `Lq-e2e-${RUN}-examinator!`,
	given: 'Ruud',
	family: `Jansen ${RUN}`,
	organisationName: `Examinering Zuiddrecht (${RUN})`,
}
const EXAM_TITLE = `Proeve installatietechniek (${RUN})`
const ROOM_NAME = `Werkplaats W2 (${RUN})`

test.describe('assessor: the practical exams he is assigned to', () => {
	test.skip(
		!ENABLED,
		'set AUDIENCE_FLOW_E2E=1 on an instance with portaliq and a portal for the school',
	)

	const created: SeededRow[] = []
	let admin: APIRequestContext
	let examiner: PortalLogin
	let examinerRef = ''
	let createdAccount = false
	const mySittings: string[] = []
	const myAssignments: string[] = []
	let myCandidate = ''
	let foreignSitting = ''
	let foreignAssignment = ''
	let foreignCandidate = ''

	test.beforeAll(async ({ browser }) => {
		test.setTimeout(300_000)
		admin = await asUser(ADMIN)
		const mode = await requireSignInMode(admin, PORTAL, 'nextcloud')

		test.skip(
			(await nextcloudAccountExists(admin, EXAM_ASSESSOR.user)) === true,
			'the instance already has an account with this run id',
		)
		await createNextcloudAccount(admin, EXAM_ASSESSOR, `${EXAM_ASSESSOR.given} ${EXAM_ASSESSOR.family}`)
		createdAccount = true

		const homework = await borrowPublishedHomework(admin, [
			'ee010008-0000-4000-8000-000000000415',
			'ee010008-0000-4000-8000-000000000467',
		])
		test.skip(homework === undefined, 'this instance has no pupils to borrow')
		const candidates = homework!.learnerRefs
		myCandidate = candidates[0]
		foreignCandidate = candidates[candidates.length - 1]

		const person = await createRow(admin, created, 'learniq', 'external-assessor', {
			givenName: EXAM_ASSESSOR.given,
			familyName: EXAM_ASSESSOR.family,
			email: `${EXAM_ASSESSOR.user}@example.org`,
			organisationName: EXAM_ASSESSOR.organisationName,
			active: true,
			tenant_id: TENANT,
		})
		examinerRef = String(person.id)
		const other = await createRow(admin, created, 'learniq', 'external-assessor', {
			givenName: 'Sanne',
			familyName: `Bakker ${RUN}`,
			email: `lq-e2e-tweede-${RUN}@example.org`,
			organisationName: 'Een ander bureau',
			active: true,
			tenant_id: TENANT,
		})

		const today = new Date().toISOString().slice(0, 10)
		const exam = await createRow(admin, created, 'learniq', 'exam', {
			title: EXAM_TITLE,
			lifecycle: 'published',
			tenant_id: TENANT,
		})
		const period = await createRow(admin, created, 'learniq', 'exam-period', {
			name: `Proevenweek (${RUN})`,
			startsOn: today,
			endsOn: today,
			tenant_id: TENANT,
		})

		// The room his day names beside each exam (practical-exam-assessment T5).
		const room = await createRow(admin, created, 'learniq', 'room', {
			name: ROOM_NAME,
			code: `W2-${RUN}`,
			capacity: 12,
			tenant_id: TENANT,
		})

		// Three sittings today with an assignment for him, a fourth for the other assessor only.
		for (const [index, hour] of ['09', '11', '13', '15'].entries()) {
			const sitting = await createRow(admin, created, 'learniq', 'exam-sitting', {
				examPeriodId: String(period.id),
				assessmentId: String(exam.id),
				startsAt: `${today}T${hour}:00:00+01:00`,
				endsAt: `${today}T${hour}:45:00+01:00`,
				roomIds: [String(room.id)],
				headcount: 1,
				lifecycle: 'planned',
				tenant_id: TENANT,
			})
			const mine = index < 3
			const assignment = await createRow(admin, created, 'learniq', 'exam-assessor-assignment', {
				examSittingId: String(sitting.id),
				externalAssessorId: mine ? examinerRef : String(other.id),
				learnerRef: mine ? candidates[index % candidates.length] : foreignCandidate,
				role: 'first',
				lifecycle: 'assigned',
				tenant_id: TENANT,
			})
			if (mine) {
				mySittings.push(String(sitting.id))
				myAssignments.push(String(assignment.id))
			} else {
				foreignSitting = String(sitting.id)
				foreignAssignment = String(assignment.id)
			}
		}

		await grantPortalAccount(admin, created, {
			subjectRef: EXAM_ASSESSOR.user,
			audience: 'external-assessor',
			organisation: mode.organisation,
			email: `${EXAM_ASSESSOR.user}@example.org`,
			displayName: `${EXAM_ASSESSOR.given} ${EXAM_ASSESSOR.family}`,
			claims: { learniq: { externalAssessorId: examinerRef } },
		})

		examiner = await signInWithNextcloudAccount(browser, EXAM_ASSESSOR, PORTAL, SHOTS)
	})

	test.afterAll(async () => {
		// The assessments he wrote, found by sitting, go with the rest.
		for (const sitting of [...mySittings, foreignSitting]) {
			const found = await admin?.get(
				`/apps/openregister/api/objects/learniq/practical-exam-assessment?examSittingId=${sitting}`,
			)
			for (const row of found?.ok() === true ? ((await found.json()).results ?? []) : []) {
				created.push({ register: 'learniq', schema: 'practical-exam-assessment', id: String(row.id ?? row.uuid) })
			}
		}

		await removeRows(admin, created, 'assessor-exam-flow')
		if (createdAccount === true) {
			await removeNextcloudAccount(admin, EXAM_ASSESSOR.user)
		}

		await examiner?.page.close()
	})

	test('d. his day lists only the candidates he is assigned to', async () => {
		const rows = await portalRows(examiner, 'exam-assessor-assignment', 'eaExams')
		const ids = rows.map((row) => String(row.id))
		for (const id of myAssignments) {
			expect(ids).toContain(id)
		}
		expect(ids).not.toContain(foreignAssignment)

		// Each row reads its time, candidate and exam, not a uuid.
		const first = rows.find((row) => String(row.id) === myAssignments[0])
		expect(String(first?.examTitle ?? '')).toBe(EXAM_TITLE)
		expect(String(first?.learnerName ?? '')).not.toBe('')
		expect(String(first?.sittingStartsAt ?? '')).not.toBe('')
		// His day also reads when the exam ends and where it is held.
		expect(String(first?.sittingEndsAt ?? '')).not.toBe('')
		expect(String(first?.sittingRoom ?? '')).toBe(ROOM_NAME)

		await openRoute(examiner.page, PORTAL, 'learniq/eaExams')
		await expect(examiner.page.getByText(EXAM_TITLE).first()).toBeVisible({ timeout: 20_000 })
		await shot(examiner.page, SHOTS, 'd1-exams-today')
	})

	test('e. he fills the form, and the assessment names him at basic assurance', async () => {
		const sent = await examiner.page.request.post(
			'/apps/portaliq/portal/api/actions/learniq/assessPracticalExam',
			{
				headers: { Authorization: `Bearer ${examiner.token}` },
				data: {
					examSittingId: mySittings[0],
					learnerRef: myCandidate,
					criteria: [{ criterionId: 'veilig-werken', score: 3 }],
					outcome: 'pass',
					// Whatever the form sends for these, the server writes its own.
					assessorId: 'someone-else',
					assuranceLevel: 'high',
				},
			},
		)
		expect(sent.status(), await sent.text()).toBeLessThan(300)

		const stored = await admin.get(
			`/apps/openregister/api/objects/learniq/practical-exam-assessment?examSittingId=${mySittings[0]}`,
		)
		expect(stored.status(), await stored.text()).toBe(200)
		const rows = (await stored.json()).results ?? []
		const mine = rows.find((row: Record<string, unknown>) => row.learnerRef === myCandidate)
		expect(mine, 'the assessment he just sent').toBeTruthy()
		expect(mine.assessorId).toBe(examinerRef)
		expect(mine.assessorName).toBe(`${EXAM_ASSESSOR.given} ${EXAM_ASSESSOR.family}`)
		expect(mine.assuranceLevel).toBe('basic')
		expect(mine.outcome).toBe('pass')
		expect(mine.lifecycle).toBe('submitted')
	})

	test('f. a sitting he is not assigned to resolves nothing and takes nothing from him', async () => {
		const direct = await examiner.page.request.get(
			`/apps/portaliq/portal/api/collections/learniq/exam-assessor-assignment/${foreignAssignment}?collection=eaExams`,
			{ headers: { Authorization: `Bearer ${examiner.token}` } },
		)
		expect(direct.status()).toBeGreaterThanOrEqual(400)

		const refused = await examiner.page.request.post(
			'/apps/portaliq/portal/api/actions/learniq/assessPracticalExam',
			{
				headers: { Authorization: `Bearer ${examiner.token}` },
				data: { examSittingId: foreignSitting, learnerRef: foreignCandidate, outcome: 'fail' },
			},
		)
		expect(refused.status()).toBeGreaterThanOrEqual(400)

		const stored = await admin.get(
			`/apps/openregister/api/objects/learniq/practical-exam-assessment?examSittingId=${foreignSitting}`,
		)
		expect(((await stored.json()).results ?? []).length, 'nothing was stored for the sitting').toBe(0)
	})

	test.fixme('g. a second assessor signs, and the result reaches the exam board', async () => {
		// The signatures endpoint (POST /api/portal/practical-exam-assessments/{id}/signatures)
		// takes a forwarded assertion, but the assessor portal declares no
		// signing action, so nothing in portaliq reaches it yet. The two-signature
		// rule is asserted in PortalPracticalExamSigningTest and PracticalExamBoardResultsTest.
	})
})
