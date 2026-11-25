// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * An mbo workplace trainer opens a student, saves an assessment halfway and
 * finishes it later (site-workplace-trainer-portal-design T9).
 *
 * trainer-flows.spec.ts proves that an invited trainer may assess, through
 * the API, and that the row records who assessed. This suite proves the
 * pages the change added: her students by name on the overview ("Mijn
 * studenten", `poLearners`), the assessment form in steps, the draft that
 * keeps her answers ("Bewaren voor later") without writing an assessment in
 * learniq, and the send that does.
 *
 * THE DRAFT IS PORTALIQ'S. The action declares `draft: {retentionDays: 30}`,
 * and portaliq keeps the answers once its drafts land (portaliq #1152). An
 * instance whose portaliq drops the key serves the action without it, and
 * the two draft steps skip with that reason.
 *
 * THE FORM STILL ASKS FOR CODES. The spec wants the work process picked by
 * label and the codes filled by the server; T3 notes that the werkproces is
 * still picked by code (follow-up). Until then the first step carries the
 * plan, component, dossier and core task fields under their own names, and
 * this suite fills them the way trainer-flows.spec.ts sends them.
 *
 * WHAT THIS SUITE CREATES AND REMOVES. Its own Nextcloud account, one
 * praktijkopleider, one BPV placement on a student the school already has,
 * the portal account carrying the `practicalTrainerId` claim, and the
 * assessment she sends. All of it is removed in `afterAll`.
 *
 *   AUDIENCE_FLOW_E2E=1 PLAYWRIGHT_BASE_URL=http://localhost:8090 \
 *   npx playwright test --config tests/e2e/audience-flow.config.ts mbo-trainer-flows
 *
 * Flows:
 *   a. she opens her student from "Mijn studenten" and reads his name;
 *   b. she fills the first step and her judgement, saves for later and
 *      leaves: no assessment exists in learniq;
 *   c. she opens the form again, finds her answers, and sends it; the
 *      assessment is hers, `submitted`, and on "Beoordelingen".
 *
 * Screenshots land in `test-results/audience-flow/mbo-trainer/`.
 *
 * @e2e openspec/changes/site-workplace-trainer-portal-design/specs/portal-contribution/spec.md#scenario-karin-sees-her-two-students
 * @e2e openspec/changes/site-workplace-trainer-portal-design/specs/portal-contribution/spec.md#scenario-saving-halfway
 * @e2e openspec/changes/site-workplace-trainer-portal-design/specs/portal-contribution/spec.md#scenario-karin-finds-what-she-sent
 */

import type { APIRequestContext, Locator } from '@playwright/test'
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
	'mbo-trainer',
)
const PORTAL = process.env.AUDIENCE_FLOW_PORTAL ?? 'esdoornveen'
const TENANT =
	process.env.AUDIENCE_FLOW_TENANT ?? '00000000-0000-4000-8000-000000000000'
const ADMIN = {
	user: process.env.NC_ADMIN_USER ?? 'admin',
	pass: process.env.NC_ADMIN_PASS ?? 'admin',
}

const RUN = Date.now().toString(36)
const TRAINER = {
	user: `lq-e2e-praktijk-${RUN}`,
	pass: `Lq-e2e-${RUN}-praktijk!`,
	given: 'Karin',
	family: 'Smit',
	company: `Bakker Techniek BV (${RUN})`,
	kvk: '81234567',
}
const WERKPROCES = `B1-K1-W${RUN.slice(-2)}`
const WERKPROCES_LABEL = `Voert installatiewerkzaamheden uit (${RUN})`
const NOTES = `Werkt zelfstandig en netjes (${RUN})`
// The codes the first step still asks for (see the header).
const CODES: Record<string, string> = {
	curriculumPlanId: '00000000-0000-4000-8000-0000000000c1',
	componentId: 'bpv',
	kwalificatiedossierCode: '25605',
	coreTaskCode: 'B1-K1',
}
// The action's label and its buttons, as the Dutch site shows them.
const FORM = 'Beoordeling van een werkproces versturen'
const NEXT = /^(Volgende|Verder)( stap)?$/
const KEEP = /Bewaren voor later/

/**
 * A field the form draws under its own name, found by that name in any
 * spelling ("curriculumPlanId", "Curriculum plan id").
 *
 * @param {Locator} form The form.
 * @param {string} key The field key.
 * @return {Locator} The text box.
 */
function codeField(form: Locator, key: string): Locator {
	const words = key.replace(/([a-z])([A-Z])/g, '$1 $2').split(' ')
	// An optional field reads "(niet verplicht)" after its name.
	return form.getByRole('textbox', { name: new RegExp(`^${words.join('\\s*')}(\\s*\\(niet verplicht\\))?$`, 'i') })
}

test.describe.configure({ mode: 'serial' })

test.describe('mbo trainer: a student, and an assessment finished later', () => {
	test.skip(
		!ENABLED,
		'set AUDIENCE_FLOW_E2E=1 on an instance with portaliq and the mbo example portal',
	)

	const created: SeededRow[] = []
	let admin: APIRequestContext
	let trainer: PortalLogin
	let trainerRef = ''
	let placementId = ''
	let studentName = ''
	let createdAccount = false
	// Whether this instance's portaliq kept the action's `draft` key.
	let draftKept = false

	/**
	 * The assessments learniq holds for this run's work process.
	 *
	 * @return {Promise<Array<Record<string, any>>>} The rows.
	 */
	async function assessments(): Promise<Array<Record<string, any>>> {
		const res = await admin.get(
			`/apps/openregister/api/objects/learniq/werkproces-assessment?werkprocesCode=${encodeURIComponent(WERKPROCES)}&_limit=20`,
		)
		expect(res.status(), await res.text()).toBe(200)
		return (await res.json()).results ?? []
	}

	/**
	 * Open the assessment form from the overview's tile.
	 *
	 * @return {Promise<Locator>} The form.
	 */
	async function openForm(): Promise<Locator> {
		await openHome(trainer.page, PORTAL)
		const main = trainer.page.locator('main').first()
		await main
			.getByRole('link', { name: 'Beoordeling invullen', exact: true })
			.or(main.getByRole('button', { name: 'Beoordeling invullen', exact: true }))
			.first()
			.click()
		const form = trainer.page.getByRole('form', { name: FORM })
		await expect(form).toBeVisible({ timeout: 20_000 })
		return form
	}

	test.beforeAll(async ({ browser }) => {
		test.setTimeout(300_000)
		admin = await asUser(ADMIN)
		const { organisation } = await requireSignInMode(admin, PORTAL, 'nextcloud')

		test.skip(
			(await nextcloudAccountExists(admin, TRAINER.user)) === true,
			'the instance already has an account with this run id',
		)
		await createNextcloudAccount(admin, TRAINER, `${TRAINER.given} ${TRAINER.family}`)
		createdAccount = true

		// A student the school already has, so the placement is about somebody real.
		const homework = await borrowPublishedHomework(admin, [
			'ee010008-0000-4000-8000-000000000415',
			'ee010008-0000-4000-8000-000000000467',
		])
		test.skip(homework === undefined, 'this instance has no students to borrow')
		const learnerRef = homework!.learnerRefs[0]
		const profile = await borrowLearnerProfile(admin, learnerRef)
		test.skip(profile === undefined, 'the borrowed student has no learner profile')
		studentName = `${profile!.givenName ?? ''} ${profile!.familyName ?? ''}`.trim()

		const person = await createRow(admin, created, 'learniq', 'praktijkopleider', {
			givenName: TRAINER.given,
			familyName: TRAINER.family,
			email: `${TRAINER.user}@example.org`,
			trainingCompanyName: TRAINER.company,
			trainingCompanyKvkNumber: TRAINER.kvk,
			active: true,
			tenant_id: TENANT,
		})
		trainerRef = String(person.id)

		const placement = await createRow(admin, created, 'learniq', 'bpv-placement', {
			learnerId: String(profile!.ncUserId ?? ''),
			learnerRef,
			curriculumPlanId: CODES.curriculumPlanId,
			practicalTrainerId: trainerRef,
			schoolCoachId: 'admin',
			trainingCompanyName: TRAINER.company,
			trainingCompanyKvkNumber: TRAINER.kvk,
			periodFrom: '2026-09-01',
			periodTo: '2027-01-31',
			agreedHours: 640,
			// A placement is confirmed only for a verified training company
			// (BpvConfirmationGuard); the seeded placements carry the same.
			trainingCompanyVerification: {
				provider: 'sbb',
				status: 'verified',
				verifiedAt: '2026-08-15T10:00:00+02:00',
				expiresAt: '2029-08-15T23:59:00+02:00',
			},
			// Created as proposed and moved through checkLeerbedrijf, confirm
			// and activate by createRow (OpenRegister refuses a non-initial
			// state on create).
			lifecycle: 'active',
			tenant_id: TENANT,
		})
		placementId = String(placement.id)

		// What `occ learniq:portal:invite-trainer` writes (learniq#1680).
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
		const action = ((await manifest.json()).contributions ?? [])
			.flatMap((contribution: Record<string, any>) => contribution.actions ?? [])
			.find((a: Record<string, any>) => a.id === 'createWerkprocesAssessment')
		draftKept = action?.draft !== undefined && action?.draft !== null
	})

	test.afterAll(async () => {
		if (admin !== undefined) {
			for (const row of await assessments().catch(() => [])) {
				created.push({ register: 'learniq', schema: 'werkproces-assessment', id: String(row.id) })
			}
			await removeRows(admin, created, 'mbo-trainer-flow')
		}
		if (createdAccount === true) {
			await removeNextcloudAccount(admin, TRAINER.user)
		}
		await trainer?.page.close()
	})

	// @e2e openspec/changes/site-workplace-trainer-portal-design/specs/portal-contribution/spec.md#scenario-karin-sees-her-two-students
	test('a. she opens her student from "Mijn studenten"', async () => {
		const students = await portalRows(trainer, 'learner-profile', 'poLearners')
		expect(students.map((row) => `${row.givenName ?? ''} ${row.familyName ?? ''}`.trim())).toContain(studentName)

		await openHome(trainer.page, PORTAL)
		const main = trainer.page.locator('main').first()
		await expect(main.getByText('Mijn studenten').first()).toBeVisible({ timeout: 20_000 })
		await shot(trainer.page, SHOTS, 'a1-overview')
		await main.getByText(studentName).first().click()
		await expect(trainer.page.getByText(studentName).first()).toBeVisible({ timeout: 20_000 })
		await shot(trainer.page, SHOTS, 'a2-student')
	})

	// @e2e openspec/changes/site-workplace-trainer-portal-design/specs/portal-contribution/spec.md#scenario-saving-halfway
	test('b. she saves the assessment halfway and leaves', async () => {
		test.skip(!draftKept, "this instance's portaliq drops the action's draft key (portaliq #1152 not landed)")
		const form = await openForm()

		// Step 1: which work process.
		await form.getByRole('combobox', { name: 'De stage', exact: true }).selectOption({ label: TRAINER.company })
		for (const [key, value] of Object.entries(CODES)) {
			await codeField(form, key).fill(value)
		}
		await form.getByRole('textbox', { name: 'Code van het werkproces', exact: true }).fill(WERKPROCES)
		await form.getByRole('textbox', { name: 'Werkproces', exact: true }).fill(WERKPROCES_LABEL)
		await form.getByRole('button', { name: NEXT }).click()

		// Step 2: her judgement, then she stops.
		await form
			.getByRole('group', { name: 'Uw oordeel', exact: true })
			.getByRole('radio', { name: 'Competent', exact: true })
			.check()
		await form.getByRole('textbox', { name: 'Waarom', exact: true }).fill(NOTES)
		await shot(trainer.page, SHOTS, 'b1-halfway')
		await form.getByRole('button', { name: KEEP }).click()

		// Learniq writes no draft assessment from the portal.
		await openHome(trainer.page, PORTAL)
		expect(await assessments()).toEqual([])
	})

	// @e2e openspec/changes/site-workplace-trainer-portal-design/specs/portal-contribution/spec.md#scenario-saving-halfway
	// @e2e openspec/changes/site-workplace-trainer-portal-design/specs/portal-contribution/spec.md#scenario-karin-finds-what-she-sent
	test('c. she finishes it later, and it is on her page as sent', async () => {
		test.skip(!draftKept, "this instance's portaliq drops the action's draft key (portaliq #1152 not landed)")
		const form = await openForm()

		// The form continues with her answers.
		await expect(form.getByRole('textbox', { name: 'Werkproces', exact: true })).toHaveValue(WERKPROCES_LABEL)
		await form.getByRole('button', { name: NEXT }).click()
		await expect(form.getByRole('textbox', { name: 'Waarom', exact: true })).toHaveValue(NOTES)
		await form.getByRole('button', { name: NEXT }).click()

		// The review step, then send.
		await expect(form.getByText(WERKPROCES_LABEL).first()).toBeVisible()
		await form.getByRole('button', { name: 'Beoordeling versturen', exact: true }).click()
		await expect(trainer.page.getByText('Uw beoordeling is verstuurd').first()).toBeVisible({ timeout: 20_000 })
		await shot(trainer.page, SHOTS, 'c1-sent')

		const rows = await assessments()
		expect(rows).toHaveLength(1)
		expect(rows[0].lifecycle).toBe('submitted')
		expect(rows[0].assessorId).toBe(trainerRef)
		expect(rows[0].bpvPlacementId).toBe(placementId)
		expect(rows[0].assessment).toBe('competent')
		expect(rows[0].notes).toBe(NOTES)

		// And on her own page, with the work process and her judgement. The
		// page has no status column (PO_WERKPROCES_ASSESSMENTS_COLUMNS), so the
		// scenario's "Verstuurd" is read from the row above, not the screen.
		await openRoute(trainer.page, PORTAL, 'learniq/poWerkprocesAssessments')
		const line = trainer.page.getByRole('row').filter({ hasText: WERKPROCES_LABEL }).first()
		await expect(line).toBeVisible({ timeout: 20_000 })
		await expect(line).toContainText('Competent')
		await shot(trainer.page, SHOTS, 'c2-assessments')
	})
})
