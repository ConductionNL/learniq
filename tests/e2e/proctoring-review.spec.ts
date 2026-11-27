/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The proctoring flag review queue: an instructor opens it from the menu and
 * decides two pending flags; a learner sees no entry and cannot decide a flag
 * on their own session.
 *
 * Covers:
 *   @e2e openspec/specs/assessment/spec.md#scenario-a-teacher-opens-the-queue
 *   @e2e openspec/specs/assessment/spec.md#scenario-a-learner-does-not-see-the-entry
 *   @e2e openspec/specs/assessment/spec.md#scenario-a-decided-flag-shows-who-decided
 *   @e2e openspec/specs/assessment/spec.md#scenario-a-learner-tries-to-allow-their-own-flag
 *   @e2e openspec/specs/assessment/spec.md#scenario-native-test-mode-still-appends-a-flag
 */
import type { Page } from '@playwright/test'
import type { TempUser } from './live-fixtures.ts'

import { expect, test } from './fixtures.ts'
import { apiAs, LiveFixtures, signInAs } from './live-fixtures.ts'

const SESSIONS = '/index.php/apps/openregister/api/objects/learniq/proctoring-session'

/**
 * Two pending flags a minute apart.
 *
 * @param run The run id, so the flags are this run's own.
 * @return The flags.
 */
function pendingFlags(run: string): Array<Record<string, string>> {
	const at = Date.now() - 10 * 60 * 1000
	return [
		{ flagId: `f1-${run}`, kind: 'tab-hidden', severity: 'medium', occurredAt: new Date(at).toISOString(), reviewDecision: 'pending' },
		{ flagId: `f2-${run}`, kind: 'fullscreen-exit', severity: 'low', occurredAt: new Date(at + 60_000).toISOString(), reviewDecision: 'pending' },
	]
}

/**
 * Open the review queue through the app menu.
 *
 * @param page A signed-in page.
 */
async function openQueueFromMenu(page: Page): Promise<void> {
	await page.goto('/index.php/apps/learniq/')
	const entry = page.getByRole('link', { name: 'Proctoring review' })
	await expect(page.getByRole('link', { name: 'Learning', exact: true })).toBeVisible({ timeout: 60_000 })
	if (!(await entry.first().isVisible())) {
		// The entry sits in the collapsed Learning group; its toggle opens it.
		await page
			.locator('li', { has: page.getByRole('link', { name: 'Learning', exact: true }) })
			.first()
			.getByRole('button')
			.last()
			.click()
	}
	await entry.first().click()
	await expect(page).toHaveURL(/\/assessments\/proctoring\/review/)
}

test.describe('proctoring flag review queue', () => {
	const fx = new LiveFixtures()
	let learner: TempUser
	let teacher: TempUser
	let sessionId = ''

	test.beforeAll(async () => {
		learner = await fx.user('learner', ['learners'])
		teacher = await fx.user('teacher', ['instructors'])
		const [result] = await fx.find('assessment-result', {})
		expect(result, 'a seeded assessment result').toBeTruthy()
		sessionId = await fx.object('proctoring-session', {
			assessmentResultId: result.id,
			learnerId: learner.id,
			provider: 'native',
			flags: pendingFlags(fx.run),
		})
	})

	test.afterAll(async () => {
		await fx.teardown()
	})

	// @e2e openspec/specs/assessment/spec.md#scenario-a-learner-tries-to-allow-their-own-flag
	// @e2e openspec/specs/assessment/spec.md#scenario-native-test-mode-still-appends-a-flag
	test('a learner cannot decide a flag on their own session, but can append one', async () => {
		const api = await apiAs(learner.id, learner.password)
		const flags = pendingFlags(fx.run)
		const decided = [{ ...flags[0], reviewDecision: 'allowed' }, flags[1]]
		const refused = await api.patch(`${SESSIONS}/${sessionId}`, { data: { flags: decided } })
		expect(refused.status(), await refused.text()).toBeGreaterThanOrEqual(400)
		const stored = await fx.read('proctoring-session', sessionId)
		expect(stored.flags.map((f: Record<string, string>) => f.reviewDecision)).toEqual(['pending', 'pending'])

		const appended = [...stored.flags, {
			flagId: `f3-${fx.run}`, kind: 'window-blur', severity: 'low', occurredAt: new Date().toISOString(), reviewDecision: 'pending',
		}]
		const ok = await api.patch(`${SESSIONS}/${sessionId}`, { data: { flags: appended } })
		expect(ok.status(), await ok.text()).toBe(200)
		expect((await fx.read('proctoring-session', sessionId)).flags).toHaveLength(3)
		await api.dispose()
	})

	// @e2e openspec/specs/assessment/spec.md#scenario-a-learner-does-not-see-the-entry
	test('a learner sees no proctoring review entry', async ({ browser }) => {
		const page = await signInAs(browser, learner)
		await page.goto('/index.php/apps/learniq/')
		await expect(page.locator('#app-navigation, nav').first()).toBeVisible({ timeout: 60_000 })
		await expect(page.getByRole('link', { name: 'Proctoring review' })).toHaveCount(0)
		await page.context().close()
	})

	// @e2e openspec/specs/assessment/spec.md#scenario-a-teacher-opens-the-queue
	// @e2e openspec/specs/assessment/spec.md#scenario-a-decided-flag-shows-who-decided
	test('an instructor opens the queue from the menu, allows one flag and annuls another', async ({ browser }) => {
		test.setTimeout(180_000)
		const page = await signInAs(browser, teacher)
		await openQueueFromMenu(page)

		const card = page.locator('.proctoring-review-queue__session').filter({ hasText: learner.id })
		await expect(card).toBeVisible({ timeout: 60_000 })
		const open = (await fx.read('proctoring-session', sessionId)).flags.filter(
			(f: Record<string, string>) => f.reviewDecision === 'pending',
		).length
		await expect(card).toContainText(`${open} open flags`)

		const row = (kind: RegExp) => card.locator('.proctoring-review-queue__flag').filter({ hasText: kind })
		await row(/tab/i).getByRole('button', { name: 'Allow' }).click()
		await expect(row(/tab/i)).toContainText(/Allowed by .+ on /)
		await row(/full ?screen/i).getByRole('button', { name: 'Annul' }).click()
		await expect(row(/full ?screen/i)).toContainText(/Annulled by .+ on /)
		await expect(row(/tab/i).getByRole('button')).toHaveCount(0)

		const stored = await fx.read('proctoring-session', sessionId)
		const byId = Object.fromEntries(stored.flags.map((f: Record<string, string>) => [f.flagId, f]))
		expect(byId[`f1-${fx.run}`].reviewDecision).toBe('allowed')
		expect(byId[`f1-${fx.run}`].reviewedBy).toBe(teacher.id)
		expect(byId[`f2-${fx.run}`].reviewDecision).toBe('annulled')
		await page.context().close()
	})
})
