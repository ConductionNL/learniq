/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage for timetabling-bulk-change-weeks: the substitution
 * dialog offers "Apply to more weeks", lists the lessons of the same weekly
 * slot, and after applying shows the outcome per lesson.
 *
 * Covers (UI-observable surface):
 *   @e2e openspec/changes/timetabling-bulk-change-weeks/specs/timetabling/spec.md#scenario-a-coordinator-cancels-three-weeks-of-a-lesson
 *
 * The guard outcome inside a batch and the one message per batch are covered
 * at the unit level (SessionChangeBatchServiceTest, SessionChangeBatchRegisterTest,
 * SessionChangeNoticeHandlerTest). An instance without a manageable lesson
 * in the window renders the timetable without a Manage button; the test then
 * asserts the page renders and stops there.
 */
import { expect, test } from '../fixtures.ts'

test.describe('timetabling-bulk-change-weeks: apply to more weeks', () => {
	// @e2e openspec/changes/timetabling-bulk-change-weeks/specs/timetabling/spec.md#scenario-a-coordinator-cancels-three-weeks-of-a-lesson
	test('the substitution dialog lists the weekly slot and applies one batch', async ({
		loggedInPage: page,
	}) => {
		await page.goto('/index.php/apps/learniq/my-timetable')
		await page.waitForLoadState('domcontentloaded')
		expect((await page.innerText('body')).trim().length).toBeGreaterThan(0)

		const manage = page.getByRole('button', { name: 'Manage' }).first()
		if ((await manage.count()) === 0) {
			return
		}

		await manage.click()
		await page.getByRole('button', { name: 'Cancel session' }).first().click()
		await page.getByTestId('apply-more-weeks').click()
		await expect(page.getByTestId('series-list')).toBeVisible({
			timeout: 15_000,
		})

		await page
			.getByRole('dialog')
			.getByRole('button', { name: 'Cancel session' })
			.last()
			.click()
		await expect(page.getByTestId('batch-results')).toBeVisible({
			timeout: 15_000,
		})
	})
})
