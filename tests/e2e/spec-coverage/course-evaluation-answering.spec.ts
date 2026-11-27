/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage: a learner answers a course evaluation
 * (assessment-course-evaluation-answer-page, task 1.2b).
 *
 * Covers:
 *   @e2e openspec/specs/course-evaluation-answering/spec.md#scenario-a-learner-answers-an-invitation
 *   @e2e openspec/specs/course-evaluation-answering/spec.md#scenario-a-learner-cannot-answer-twice
 *
 * The run makes its own learner account, an open built-in campaign with one
 * likert and one free-text question on a borrowed course, and one invitation
 * for that learner. The learner opens My evaluations, fills in the form and
 * sends it; the invitation is then gone from the page and a second answer is
 * refused. The campaign, the invitation and the account are removed
 * afterwards; the response row is ownerless by design (DECISIONS row 63) and
 * is left to the campaign's own retention.
 */
import type { APIRequestContext, Browser, Page } from '@playwright/test'
import type { SeededRow } from '../helpers/portal-fixture.ts'

import { expect, test } from '@playwright/test'
import {
	asUser,
	createNextcloudAccount,
	createRow,
	removeNextcloudAccount,
	removeRows,
} from '../helpers/portal-fixture.ts'

const TENANT =
	process.env.AUDIENCE_FLOW_TENANT ?? '00000000-0000-4000-8000-000000000000'
const ADMIN = {
	user: process.env.NC_ADMIN_USER ?? 'admin',
	pass: process.env.NC_ADMIN_PASS ?? 'admin',
}
const RUN = Date.now().toString(36)
const LEARNER = {
	user: `lq-e2e-evaluatie-${RUN}`,
	pass: `Lq-e2e-${RUN}-evaluatie!`,
	name: 'Evaluatie Leerling',
}
const CAMPAIGN_NAME = `Cursusevaluatie e2e ${RUN}`
const MY_EVALUATIONS_URL = '/index.php/apps/learniq/my-evaluations'

/**
 * A page signed in to Nextcloud as the given account, with no shared state.
 *
 * @param {Browser} browser The browser.
 * @param {{user: string, pass: string}} who The account.
 * @return {Promise<Page>} The page.
 */
async function signIn(
	browser: Browser,
	who: { user: string; pass: string },
): Promise<Page> {
	const page = await (
		await browser.newContext({
			storageState: undefined,
			viewport: { width: 1400, height: 1000 },
		})
	).newPage()
	await page.goto('/index.php/login')
	await page.locator('input[name="user"]').fill(who.user)
	await page.locator('input[name="password"]').fill(who.pass)
	await page.locator('button[type="submit"]').click()
	await page.waitForURL((url) => !url.pathname.includes('/login'))
	return page
}

test.describe.serial('course-evaluation-answering: a learner answers once', () => {
	const created: SeededRow[] = []
	let admin: APIRequestContext
	let learner: Page | undefined
	let invitationId = ''
	let accountCreated = false

	test.beforeAll(async ({ browser }) => {
		test.setTimeout(180_000)
		admin = await asUser(ADMIN)

		const courses = await admin.get(
			'/index.php/apps/openregister/api/objects/learniq/course?_limit=1',
		)
		expect(courses.status(), await courses.text()).toBe(200)
		const course = ((await courses.json()).results ?? [])[0]
		test.skip(course === undefined, 'this instance has no course to evaluate')
		const courseId = String(course.id ?? course.uuid)

		await createNextcloudAccount(admin, LEARNER, LEARNER.name)
		accountCreated = true

		const closesAt = new Date(Date.now() + 14 * 86_400_000).toISOString()
		const campaign = await createRow(
			admin,
			created,
			'learniq',
			'evaluation-campaign',
			{
				name: CAMPAIGN_NAME,
				closesAt,
				courseIds: [courseId],
				academicYear: '2026-2027',
				period: 'P1',
				instrumentKind: 'built-in',
				anonymityPolicy: 'fully-anonymous',
				lifecycle: 'open',
				questions: [
					{
						questionId: 'overall',
						kind: 'likert-5',
						required: true,
						text: { nl: 'Ik heb veel geleerd in deze cursus.', en: 'I learned a lot in this course.' },
					},
					{
						questionId: 'tip',
						kind: 'free-text',
						required: false,
						text: { nl: 'Wat kan beter?', en: 'What could be better?' },
					},
				],
				tenant_id: TENANT,
			},
		)

		const invitation = await createRow(
			admin,
			created,
			'learniq',
			'evaluation-invitation',
			{
				campaignId: String(campaign.id ?? campaign.uuid),
				courseId,
				learnerId: LEARNER.user,
				hasResponded: false,
				campaignClosesAt: closesAt,
				academicYear: '2026-2027',
				period: 'P1',
				tenant_id: TENANT,
			},
		)
		invitationId = String(invitation.id ?? invitation.uuid)

		learner = await signIn(browser, LEARNER)
	})

	test.afterAll(async () => {
		if (admin === undefined) {
			return
		}
		await removeRows(admin, created, 'course-evaluation-answering')
		if (accountCreated === true) {
			await removeNextcloudAccount(admin, LEARNER.user)
		}
		await learner?.close()
		await admin.dispose()
	})

	// @e2e openspec/specs/course-evaluation-answering/spec.md#scenario-a-learner-answers-an-invitation
	test('she answers her open evaluation and it leaves the page', async () => {
		const page = learner!
		await page.goto(MY_EVALUATIONS_URL)
		const item = page
			.locator('.my-evaluations__item')
			.filter({ hasText: CAMPAIGN_NAME })
		await expect(item).toHaveCount(1, { timeout: 30_000 })

		await item.getByRole('button').click()
		// The likert question: tick 4 of 5.
		const scale = page.getByRole('radiogroup').first()
		await expect(scale).toBeVisible({ timeout: 15_000 })
		await scale.getByText('4', { exact: true }).click()
		await page.locator('textarea').first().fill(`Meer oefentijd (${RUN})`)

		const sent = page.waitForResponse(
			(res) =>
				res.url().includes(`/api/evaluations/${invitationId}/answer`) &&
				res.request().method() === 'POST',
		)
		await page.locator('form button[type="submit"]').click()
		expect((await sent).status()).toBe(201)

		// The page no longer lists it, also after a reload.
		await expect(
			page.locator('.my-evaluations__item').filter({ hasText: CAMPAIGN_NAME }),
		).toHaveCount(0, { timeout: 15_000 })
		await page.reload()
		await expect(page.locator('main, #content').first()).toBeVisible()
		await expect(
			page.locator('.my-evaluations__item').filter({ hasText: CAMPAIGN_NAME }),
		).toHaveCount(0, { timeout: 15_000 })

		// The invitation is marked as answered.
		const stored = await admin.get(
			`/index.php/apps/openregister/api/objects/learniq/evaluation-invitation/${invitationId}`,
		)
		expect(stored.status(), await stored.text()).toBe(200)
		expect((await stored.json()).hasResponded).toBe(true)
	})

	// @e2e openspec/specs/course-evaluation-answering/spec.md#scenario-a-learner-cannot-answer-twice
	test('a second answer is refused', async () => {
		const page = learner!
		const requestToken = await page.evaluate(
			() => (window as any).OC?.requestToken ?? '',
		)
		const again = await page.request.post(
			`/index.php/apps/learniq/api/evaluations/${invitationId}/answer`,
			{
				headers: {
					'Content-Type': 'application/json',
					requesttoken: requestToken,
					'OCS-APIREQUEST': 'true',
				},
				data: JSON.stringify({ answers: { overall: 5 } }),
			},
		)
		expect(again.status()).toBe(409)
	})
})
