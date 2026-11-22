/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A grade in a locked report period changes only on a correction a second
 * person approved (governance-four-eyes-on-approved-data, task 2.1b): a team
 * lead alone cannot republish, a teacher asks for a correction, another
 * person approves it on the correction's page, and the teacher's republish
 * then lands and links the request; the person who asked cannot approve.
 *
 * The grade entry is published before its report period exists, and the
 * period is then made with a lock date in the past, so the entry sits in a
 * locked period without the suite waiting for a date.
 *
 * Written from the change's scenarios; not run in the build lane (needs the
 * branch deployed on an instance with learniq's register imported).
 *
 * Covers:
 *   @e2e openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#scenario-a-principal-cannot-override-alone
 *   @e2e openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#scenario-a-second-person-approves-a-correction
 *   @e2e openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#scenario-the-requester-cannot-approve
 */
import type { APIRequestContext, Page } from '@playwright/test'
import type { TempUser } from './live-fixtures.ts'

import { expect, test } from './fixtures.ts'
import { apiAs, LiveFixtures, signInAs } from './live-fixtures.ts'

const OBJECTS = '/index.php/apps/openregister/api/objects'
// ReportPeriodLockGuard::DENIAL, its first sentence.
const LOCKED = 'This grade is in a locked report period.'

/**
 * Run a lifecycle transition through OpenRegister.
 *
 * @param api The caller's context.
 * @param id The object's uuid.
 * @param action The transition name.
 * @param inputs Transition inputs, if any.
 * @return The HTTP status and the answer's text.
 */
async function transition(
	api: APIRequestContext,
	id: string,
	action: string,
	inputs: Record<string, unknown> = {},
): Promise<{ status: number; text: string }> {
	const res = await api.post(`${OBJECTS}/${id}/transition`, { data: { action, ...inputs } })
	return { status: res.status(), text: await res.text() }
}

/**
 * Open a correction's page and run one lifecycle action from its header menu.
 *
 * @param page A signed-in page.
 * @param id The correction request's uuid.
 * @param label The action's label.
 */
async function runAction(page: Page, id: string, label: string): Promise<void> {
	await page.goto(`/index.php/apps/learniq/grades/corrections/${id}`)
	const menu = page.getByRole('main').getByRole('button', { name: /^(More|Actions)$/ }).first()
	await expect(menu).toBeVisible({ timeout: 60_000 })
	await menu.click()
	await page.getByRole('menuitem', { name: label }).click()
	// The approve transition takes an optional note; confirm the dialog when it shows.
	const confirm = page.getByTestId('cn-transition-input-confirm')
	if (await confirm.isVisible({ timeout: 5_000 }).catch(() => false)) {
		await confirm.click()
	}
}

test.describe('a correction to a grade in a locked period needs a second person', () => {
	const fx = new LiveFixtures()
	let teacher: TempUser
	let lead: TempUser
	let principal: TempUser
	let entryId = ''
	const periodCode = `E2E-${fx.run}`

	test.beforeAll(async () => {
		teacher = await fx.user('teacher', ['instructors'])
		lead = await fx.user('lead', ['team-leads'])
		principal = await fx.user('principal', ['administration-managers'])

		const planId = await fx.object('curriculum-plan', {
			name: `Four eyes ${fx.run}`,
			kind: 'generic',
			formula: 'weighted-average',
		})
		const scaleId = await fx.object('grade-scale', { name: `Scale ${fx.run}`, kind: 'numeric', min: 1, max: 10 })
		entryId = await fx.object('grade-entry', {
			learnerId: `four-eyes-${fx.run}`,
			curriculumPlanId: planId,
			componentId: 'toets-1',
			gradeScaleId: scaleId,
			value: 5.5,
			period: periodCode,
			grader: teacher.id,
		})
		const admin = await fx.api()
		const published = await transition(admin, entryId, 'publish')
		expect(published.status, published.text).toBeLessThan(300)

		// The period that governs the entry, locked since yesterday.
		const yesterday = new Date(Date.now() - 86_400_000).toISOString()
		await fx.object('report-period', {
			name: `Period ${fx.run}`,
			academicYear: '2026-2027',
			periodCode,
			startDate: '2026-08-01',
			endDate: '2026-12-31',
			lockDate: yesterday,
			curriculumPlanIds: [planId],
			cohortIds: [],
		})
	})

	test.afterAll(async () => {
		await fx.teardown()
	})

	// @e2e openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#scenario-a-principal-cannot-override-alone
	test('a team lead alone cannot republish the grade', async () => {
		const api = await apiAs(lead.id, lead.password)
		const revised = await transition(api, entryId, 'revise')
		expect(revised.status, revised.text).toBeLessThan(300)
		const republish = await transition(api, entryId, 'republish')
		expect(republish.status).toBeGreaterThanOrEqual(400)
		expect(republish.text).toContain(LOCKED)
		await api.dispose()
	})

	// @e2e openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#scenario-the-requester-cannot-approve
	test('the person who asked cannot approve the request', async () => {
		const api = await apiAs(lead.id, lead.password)
		const res = await api.post(`${OBJECTS}/learniq/data-correction-request`, {
			data: { tenant_id: await fx.tenant(), gradeEntryId: entryId, proposedValue: '6', reason: 'Asked by the approver himself' },
		})
		expect(res.ok(), await res.text()).toBe(true)
		const created = await res.json()
		const requestId = String(created.id ?? created['@self']?.id)
		fx.adopt('data-correction-request', requestId)
		const approve = await transition(api, requestId, 'approve')
		expect(approve.status).toBeGreaterThanOrEqual(400)
		expect((await fx.read('data-correction-request', requestId)).lifecycle).toBe('requested')
		await api.dispose()
	})

	// @e2e openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#scenario-a-second-person-approves-a-correction
	test('the teacher asks, the principal approves on the page, and the republish lands', async ({ browser }) => {
		test.setTimeout(180_000)
		const api = await apiAs(teacher.id, teacher.password)
		const res = await api.post(`${OBJECTS}/learniq/data-correction-request`, {
			data: { tenant_id: await fx.tenant(), gradeEntryId: entryId, proposedValue: '6.5', reason: 'Wrong answer key on question 4' },
		})
		expect(res.ok(), await res.text()).toBe(true)
		const body = await res.json()
		const requestId = String(body.id ?? body['@self']?.id)
		fx.adopt('data-correction-request', requestId)
		expect((await fx.read('data-correction-request', requestId)).requestedBy).toBe(teacher.id)

		const page = await signInAs(browser, principal)
		await runAction(page, requestId, 'Approve')
		await expect
			.poll(async () => (await fx.read('data-correction-request', requestId)).lifecycle, { timeout: 30_000 })
			.toBe('approved')
		expect((await fx.read('data-correction-request', requestId)).decidedBy).toBe(principal.id)
		await page.context().close()

		// The teacher publishes the grade with the approved value.
		const admin = await fx.api()
		const patched = await admin.patch(`${OBJECTS}/learniq/grade-entry/${entryId}`, { data: { value: 6.5 } })
		expect(patched.ok(), await patched.text()).toBe(true)
		const republish = await transition(api, entryId, 'republish')
		expect(republish.status, republish.text).toBeLessThan(300)

		const entry = await fx.read('grade-entry', entryId)
		expect(entry.lifecycle).toBe('published')
		expect(entry.correctionRequestId).toBe(requestId)
		await expect
			.poll(async () => (await fx.read('data-correction-request', requestId)).lifecycle, { timeout: 30_000 })
			.toBe('applied')
		await api.dispose()
	})
})
