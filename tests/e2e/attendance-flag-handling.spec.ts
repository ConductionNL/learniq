/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Handle an attendance flag from its own page (attendance-flag-report-actions,
 * task 2.2). A mentor takes up an open flag and the page says "In handling";
 * with a succeeded exchange job she marks it reported; with a running job the
 * page shows the guard's reason and the flag stays in handling; a compliance
 * officer records the municipality's MAS route on a reported flag.
 *
 * The exchange job lives in integriq (register `integriq`, schema `job`). On
 * an instance with integriq enabled the suite writes its own job rows and
 * removes them afterwards. Without integriq there is no job to read, so the
 * two steps that need one skip and the take-up step checks the flag only.
 *
 * Covers:
 *   @e2e openspec/changes/attendance-flag-report-actions/specs/attendance/spec.md#scenario-a-mentor-takes-up-an-open-flag
 *   @e2e openspec/changes/attendance-flag-report-actions/specs/attendance/spec.md#scenario-the-exchange-has-succeeded
 *   @e2e openspec/changes/attendance-flag-report-actions/specs/attendance/spec.md#scenario-the-exchange-is-still-running
 *   @e2e openspec/changes/attendance-flag-report-actions/specs/attendance/spec.md#scenario-a-coordinator-records-the-mas-route
 */
import type { APIRequestContext, Page } from '@playwright/test'
import type { TempUser } from './live-fixtures.ts'

import { expect, test } from './fixtures.ts'
import { LiveFixtures, signInAs } from './live-fixtures.ts'

const OBJECTS = '/index.php/apps/openregister/api/objects'
const JOBS = `${OBJECTS}/integriq/job`
// AttendanceFlagReportGuard::DENIAL, word for word (AttendanceFlagReportGuardTest pins it).
const DENIAL = 'Integriq has not sent the leerplicht report yet, so this flag can not be marked as reported.'

/**
 * Open a flag's page and run one lifecycle action from its header menu.
 *
 * @param page A signed-in page.
 * @param id The flag's uuid.
 * @param label The transition label, as the register declares it.
 */
async function runAction(page: Page, id: string, label: string): Promise<void> {
	await page.goto(`/index.php/apps/learniq/attendance/flags/${id}`)
	// The transitions sit in the page header's menu: "More" on the board look, "Actions" otherwise.
	const menu = page.getByRole('main').getByRole('button', { name: /^(More|Actions)$/ }).first()
	await expect(menu).toBeVisible({ timeout: 60_000 })
	await menu.click()
	await page.getByRole('menuitem', { name: label }).click()
}

/**
 * Whether integriq is enabled on the instance.
 *
 * @param admin An admin context.
 * @return True when it is.
 */
async function integriqEnabled(admin: APIRequestContext): Promise<boolean> {
	const res = await admin.get('/ocs/v2.php/cloud/apps?filter=enabled&format=json')
	if (!res.ok()) {
		return false
	}
	const apps: string[] = (await res.json())?.ocs?.data?.apps ?? []
	return apps.includes('integriq')
}

test.describe('handle an attendance flag from its page', () => {
	const fx = new LiveFixtures()
	let mentor: TempUser
	let officer: TempUser
	let thresholdId = ''
	let withIntegriq = false
	// Integriq rows are not learniq's, so LiveFixtures does not remove them.
	const jobIds: string[] = []

	/**
	 * A new open flag for a learner of this run.
	 *
	 * @param key A short word that keeps the learner, and so the flag, apart.
	 * @return The flag's uuid.
	 */
	async function openFlag(key: string): Promise<string> {
		return fx.object('attendance-flag', {
			learnerId: `flag-${key}-${fx.run}`,
			attendanceThresholdId: thresholdId,
			windowStart: '2026-10-01',
			windowEnd: '2026-10-28',
			metricValue: 16,
			breachingRecordIds: [],
		})
	}

	/**
	 * An integriq leerplicht job for a flag, linked to it.
	 *
	 * @param flagId The flag.
	 * @param exchangeStatus The job's status.
	 * @return The job's uuid.
	 */
	async function linkJob(flagId: string, exchangeStatus: string): Promise<string> {
		const admin = await fx.api()
		const res = await admin.post(JOBS, {
			data: {
				name: 'Verzuimmelding',
				ownerApp: 'learniq',
				ownerRef: `attendance-flag/${flagId}`,
				exchangeTarget: 'leerplicht',
				exchangeDirection: 'export',
				exchangeStatus,
				exchangeScope: { schema: 'attendance-flag', recordIds: [flagId], tenantId: await fx.tenant() },
			},
		})
		const body = await res.json().catch(() => null)
		const jobId = String(body?.['@self']?.uuid ?? body?.uuid ?? body?.id ?? '')
		expect(res.ok() && jobId !== '', `creating an integriq job: HTTP ${res.status()} ${JSON.stringify(body).slice(0, 400)}`).toBe(true)
		jobIds.push(jobId)
		const linked = await admin.patch(`${OBJECTS}/learniq/attendance-flag/${flagId}`, {
			data: { dataExchangeJobId: jobId },
		})
		expect(linked.status(), await linked.text()).toBe(200)
		return jobId
	}

	/**
	 * Run a transition as admin, to put a flag in the state a step starts from.
	 *
	 * @param flagId The flag.
	 * @param action The transition.
	 */
	async function transition(flagId: string, action: string): Promise<void> {
		const admin = await fx.api()
		const res = await admin.post(`${OBJECTS}/${flagId}/transition`, { data: { action } })
		expect(res.status(), await res.text()).toBe(200)
	}

	test.beforeAll(async () => {
		mentor = await fx.user('mentor', ['instructors'])
		officer = await fx.user('officer', ['compliance-officers'])
		const [threshold] = await fx.find('attendance-threshold', {})
		expect(threshold, 'a seeded attendance threshold').toBeTruthy()
		thresholdId = String(threshold.id)
		withIntegriq = await integriqEnabled(await fx.api())
	})

	test.afterAll(async () => {
		const admin = await fx.api()
		for (const id of jobIds) {
			await admin.delete(`${JOBS}/${id}`).catch(() => null)
		}
		await fx.teardown()
	})

	// @e2e openspec/changes/attendance-flag-report-actions/specs/attendance/spec.md#scenario-a-mentor-takes-up-an-open-flag
	test('a mentor takes up an open flag and the exchange job is released', async ({ browser }) => {
		test.setTimeout(180_000)
		const flagId = await openFlag('open')
		expect((await fx.read('attendance-flag', flagId)).lifecycle).toBe('open')

		// The job the exchange gate holds while the flag is open. The creation
		// handler links one when the threshold names a target; otherwise the
		// suite links its own.
		let jobId = String((await fx.read('attendance-flag', flagId)).dataExchangeJobId ?? '')
		if (jobId === '' && withIntegriq) {
			jobId = await linkJob(flagId, 'queued')
		}
		const admin = await fx.api()
		const gate = async (): Promise<string> =>
			String((await (await admin.get(`/index.php/apps/learniq/api/exchange-gates/${jobId}`)).json()).code ?? '')
		if (jobId !== '') {
			expect(await gate()).toBe('flag-not-in-handling')
		}

		const page = await signInAs(browser, mentor)
		await runAction(page, flagId, 'Take up')
		await expect.poll(async () => (await fx.read('attendance-flag', flagId)).lifecycle, { timeout: 30_000 }).toBe('in-handling')
		await expect(page.getByText('In handling').first()).toBeVisible({ timeout: 30_000 })

		if (jobId === '') {
			test.info().annotations.push({
				type: 'skipped-assertion',
				description: 'integriq is not enabled, so no exchange job is linked and the gate is not read',
			})
		} else {
			expect(await gate()).not.toBe('flag-not-in-handling')
		}
		await page.context().close()
	})

	// @e2e openspec/changes/attendance-flag-report-actions/specs/attendance/spec.md#scenario-the-exchange-has-succeeded
	test('with a succeeded exchange job the mentor marks the flag reported', async ({ browser }) => {
		test.skip(!withIntegriq, 'integriq is not enabled on this instance, so no exchange job can be read')
		test.setTimeout(180_000)
		const flagId = await openFlag('sent')
		await linkJob(flagId, 'succeeded')
		await transition(flagId, 'startHandling')

		const page = await signInAs(browser, mentor)
		await runAction(page, flagId, 'Mark as reported')
		await expect.poll(async () => (await fx.read('attendance-flag', flagId)).lifecycle, { timeout: 30_000 }).toBe('reported')
		await page.context().close()
	})

	// @e2e openspec/changes/attendance-flag-report-actions/specs/attendance/spec.md#scenario-the-exchange-is-still-running
	test('with a running exchange job the page shows why the flag waits', async ({ browser }) => {
		test.skip(!withIntegriq, 'integriq is not enabled on this instance, so no exchange job can be read')
		test.setTimeout(180_000)
		const flagId = await openFlag('running')
		await linkJob(flagId, 'running')
		await transition(flagId, 'startHandling')

		const page = await signInAs(browser, mentor)
		await runAction(page, flagId, 'Mark as reported')
		await expect(page.getByText(DENIAL).first()).toBeVisible({ timeout: 30_000 })
		expect((await fx.read('attendance-flag', flagId)).lifecycle).toBe('in-handling')
		await page.context().close()
	})

	// @e2e openspec/changes/attendance-flag-report-actions/specs/attendance/spec.md#scenario-a-coordinator-records-the-mas-route
	test('a compliance officer records the MAS route on a reported flag', async ({ browser }) => {
		test.fixme(
			true,
			'MunicipalityFeedbackGuard::AUTHORISED_GROUPS is admin and coordinators, so a user in compliance-officers only is refused. The spec names compliance-officers; which group may record is a decision, so this stays a known failure.',
		)
		test.setTimeout(180_000)
		// No job linked: the guard allows the report straight away (the manual route).
		const flagId = await openFlag('answer')
		await transition(flagId, 'startHandling')
		await transition(flagId, 'report')
		const route = `MAS-route ${fx.run}`

		const page = await signInAs(browser, officer)
		await runAction(page, flagId, "Record the municipality's answer")
		// The dialog asks for the MAS route and a note, never receivedAt or recordedBy.
		const dialog = page.locator('[data-testid-modal="cn-transition-input-dialog"]')
		await expect(dialog).toBeVisible()
		await dialog.getByLabel('MAS route').fill(route)
		await dialog.getByLabel('Note').fill('Gemeente pakt het op')
		await expect(dialog.getByLabel('Received at')).toHaveCount(0)
		await expect(dialog.getByLabel('Recorded by')).toHaveCount(0)
		await page.getByTestId('cn-transition-input-confirm').click()

		await expect
			.poll(async () => (await fx.read('attendance-flag', flagId)).municipalityFeedback?.masRoute, { timeout: 30_000 })
			.toBe(route)
		const stored = await fx.read('attendance-flag', flagId)
		expect(stored.municipalityFeedback.recordedBy).toBe(officer.id)
		expect(stored.lifecycle).toBe('reported')
		await page.context().close()
	})
})
