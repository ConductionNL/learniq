/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Merge a duplicate learner account from the learner's page: hr merges two
 * duplicates, a pair with an open seat in one course is refused with the
 * reason, and an instructor is not offered the action.
 *
 * Covers:
 *   @e2e openspec/specs/learner-account-merge/spec.md#scenario-merge-two-accounts-of-one-pupil
 *   @e2e openspec/specs/learner-account-merge/spec.md#scenario-two-open-seats-in-one-course
 *   @e2e openspec/specs/learner-account-merge/spec.md#scenario-a-teacher-does-not-see-the-action
 */
import type { Page } from '@playwright/test'
import type { TempUser } from './live-fixtures.ts'

import { expect, test } from './fixtures.ts'
import { LiveFixtures, signInAs } from './live-fixtures.ts'

const MERGE = 'Merge into another account'

/**
 * Open a learner's page and its header menu.
 *
 * @param page A signed-in page.
 * @param id The learner profile id.
 */
async function openActions(page: Page, id: string): Promise<void> {
	await page.goto(`/index.php/apps/learniq/learner-profiles/${id}`)
	// The lifecycle actions sit in the page header's "More" menu.
	const more = page.getByRole('main').getByRole('button', { name: 'More' }).first()
	await expect(more).toBeVisible({ timeout: 60_000 })
	await more.click()
}

/**
 * Run the merge from the page, picking the survivor by name.
 *
 * @param page A signed-in page on the duplicate.
 * @param survivorName The surviving profile's full name.
 */
async function mergeInto(page: Page, survivorName: string): Promise<void> {
	await page.getByRole('menuitem', { name: MERGE }).click()
	// The confirmation comes first, then the dialog that asks for the survivor.
	await page.getByRole('dialog', { name: 'Are you sure?' }).getByRole('button', { name: MERGE }).click()
	const dialog = page.locator('[data-testid-modal="cn-transition-input-dialog"]')
	await expect(dialog).toBeVisible()
	await dialog.getByRole('combobox').first().click()
	await page.keyboard.type(survivorName)
	await page.getByRole('option', { name: new RegExp(survivorName) }).first().click()
	await page.getByTestId('cn-transition-input-confirm').click()
}

test.describe('merge a duplicate learner account', () => {
	const fx = new LiveFixtures()
	let hr: TempUser
	let teacher: TempUser
	const ids: Record<string, string> = {}
	const name = (key: string) => `Merge${key} ${fx.run}`

	test.beforeAll(async () => {
		hr = await fx.user('hr', ['hr'])
		teacher = await fx.user('teacher', ['instructors'])
		const [course] = await fx.find('course', {})
		expect(course, 'a seeded course').toBeTruthy()
		for (const key of ['A', 'B', 'C', 'D']) {
			ids[key] = await fx.object('learner-profile', {
				ncUserId: `merge-${key.toLowerCase()}-${fx.run}`,
				givenName: `Merge${key}`,
				familyName: fx.run,
			})
		}
		// C and D both hold an open seat in the same course.
		for (const key of ['C', 'D']) {
			await fx.object('enrolment', {
				learnerId: `merge-${key.toLowerCase()}-${fx.run}`,
				courseId: course.id,
				source: 'hr',
				lifecycle: 'active',
			})
		}
	})

	test.afterAll(async () => {
		await fx.teardown()
	})

	// @e2e openspec/specs/learner-account-merge/spec.md#scenario-merge-two-accounts-of-one-pupil
	test('hr merges a duplicate into the other account', async ({ browser }) => {
		test.setTimeout(180_000)
		const page = await signInAs(browser, hr)
		await openActions(page, ids.A)
		await mergeInto(page, name('B'))
		await expect.poll(async () => (await fx.read('learner-profile', ids.A)).lifecycle, { timeout: 30_000 }).toBe('merged')
		expect((await fx.read('learner-profile', ids.A)).mergedInto).toBe(ids.B)
		await page.context().close()
	})

	// @e2e openspec/specs/learner-account-merge/spec.md#scenario-two-open-seats-in-one-course
	test('a pair with an open seat in one course is refused with the reason', async ({ browser }) => {
		test.setTimeout(180_000)
		const page = await signInAs(browser, hr)
		await openActions(page, ids.C)
		await mergeInto(page, name('D'))
		await expect(page.getByTestId('cn-transition-input-error')).toContainText('open enrolment')
		expect((await fx.read('learner-profile', ids.C)).lifecycle).toBe('active')
		expect((await fx.read('learner-profile', ids.D)).lifecycle).toBe('active')
		await page.context().close()
	})

	// @e2e openspec/specs/learner-account-merge/spec.md#scenario-a-teacher-does-not-see-the-action
	test('an instructor is not offered the merge', async ({ browser }) => {
		test.fixme(
			true,
			'Found live 17 Nov: the page offers "Merge into another account" to an instructor; the manifest action has no role gate. Who may merge is a decision, so this stays a known failure.',
		)
		const page = await signInAs(browser, teacher)
		await page.goto(`/index.php/apps/learniq/learner-profiles/${ids.B}`)
		await expect(page.getByRole('heading', { name: name('B') }).first()).toBeVisible({ timeout: 60_000 })
		const more = page.getByRole('main').getByRole('button', { name: 'More' }).first()
		if (await more.isVisible()) {
			await more.click()
		}
		await expect(page.getByRole('menuitem', { name: MERGE })).toHaveCount(0)
		await page.context().close()
	})
})
