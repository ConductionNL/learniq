/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The action matrix in Admin settings, Learniq: an administrator ticks a
 * group for one action, saves, reloads and finds the tick kept; a save that
 * leaves out a seeded action is refused and changes nothing.
 *
 * Covers:
 *   @e2e openspec/specs/action-authorization/spec.md#scenario-an-administrator-lets-team-leads-run-an-action
 *   @e2e openspec/specs/action-authorization/spec.md#scenario-a-malformed-save-keeps-the-old-matrix
 *
 * The test puts the stored matrix back as it found it.
 */
import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '../fixtures.ts'
import { LiveFixtures } from '../live-fixtures.ts'

const MATRIX = '/index.php/apps/learniq/api/admin/action-matrix'
const ACTION = 'regulation.assign'
const GROUP = 'team-leads'

type Matrix = Record<string, string[]>

/**
 * Read the stored matrix as admin.
 *
 * @param api The admin context.
 * @return The matrix and the declared actions.
 */
async function storedMatrix(
	api: APIRequestContext,
): Promise<{ matrix: Matrix; actions: string[] }> {
	const res = await api.get(MATRIX)
	expect(res.status(), await res.text()).toBe(200)
	const body = await res.json()
	return { matrix: body.matrix as Matrix, actions: body.actions as string[] }
}

/**
 * The checkbox of one action × group cell.
 *
 * @param page The settings page.
 * @return The checkbox input.
 */
function cell(page: Page) {
	return page
		.getByTestId('admin-action-auth-section')
		.getByRole('checkbox', {
			name: `Allow group ${GROUP} to perform ${ACTION}`,
		})
}

test.describe('action matrix set by admins', () => {
	const fx = new LiveFixtures()
	let original: Matrix | null = null

	test.beforeAll(async () => {
		const api = await fx.api()
		const { matrix } = await storedMatrix(api)
		original = matrix
		// Make sure the cell starts unticked and the group exists as a column.
		await api.post('/ocs/v2.php/cloud/groups?format=json', {
			form: { groupid: GROUP },
		})
		const without = {
			...matrix,
			[ACTION]: (matrix[ACTION] ?? ['admin']).filter((g) => g !== GROUP),
		}
		const put = await api.put(MATRIX, { data: { matrix: without } })
		expect(put.status(), await put.text()).toBe(200)
	})

	test.afterAll(async () => {
		if (original !== null) {
			const api = await fx.api()
			await api.put(MATRIX, { data: { matrix: original } })
		}
		await fx.teardown()
	})

	// @e2e openspec/specs/action-authorization/spec.md#scenario-an-administrator-lets-team-leads-run-an-action
	test('an admin ticks a group for one action, saves, reloads and sees the tick kept', async ({
		loggedInPage: page,
	}) => {
		await page.goto('/index.php/settings/admin/learniq')
		const box = cell(page)
		await expect(box).toBeVisible({ timeout: 30_000 })
		await expect(box).not.toBeChecked()

		await box.click({ force: true })
		await expect(box).toBeChecked()
		await page.getByTestId('admin-action-matrix-save').click()
		await expect(page.getByText('Action matrix saved.')).toBeVisible()

		await page.reload()
		await expect(cell(page)).toBeVisible({ timeout: 30_000 })
		await expect(cell(page)).toBeChecked()

		const { matrix } = await storedMatrix(await fx.api())
		expect(matrix[ACTION]).toContain(GROUP)
	})

	// @e2e openspec/specs/action-authorization/spec.md#scenario-a-malformed-save-keeps-the-old-matrix
	test('a save without a seeded action is refused and keeps the stored matrix', async () => {
		const api = await fx.api()
		const before = await storedMatrix(api)
		const partial = { ...before.matrix }
		delete partial[ACTION]

		const res = await api.put(MATRIX, { data: { matrix: partial } })
		expect(res.status()).toBe(400)

		const after = await storedMatrix(api)
		expect(after.matrix).toEqual(before.matrix)
	})
})
