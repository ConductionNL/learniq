/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage: the action matrix an administrator sets
 * (action-matrix-set-by-admins, task 2.2).
 *
 * Covers:
 *   @e2e openspec/changes/action-matrix-set-by-admins/specs/action-authorization/spec.md#requirement-an-administrator-changes-the-matrix-without-a-developer
 *   @e2e openspec/changes/action-matrix-set-by-admins/specs/action-authorization/spec.md#scenario-an-administrator-lets-team-leads-run-an-action
 *
 * An administrator ticks one group for one action in Admin settings, Learniq,
 * saves, reloads the page and still sees the tick. The matrix as it stood
 * before the test is read first and put back afterwards, so a shared
 * instance keeps its own matrix. REST is setup and cleanup only; the
 * assertion is on the page.
 */
import type { Page } from '@playwright/test'

import { apiUrl } from '../base-url.ts'
import { expect, test } from '../fixtures.ts'

/** The Nextcloud admin settings section of learniq (see nextcloud-app.spec.ts for the `/index.php/` prefix). */
const SETTINGS_URL = '/index.php/settings/admin/learniq'
const MATRIX_API = '/index.php/apps/learniq/api/admin/action-matrix'

/** The action and group the scenario names, used when this instance has them. */
const PREFERRED = { action: 'regulation.assign', group: 'team-leads' }

type Matrix = Record<string, string[]>

/**
 * The stored matrix, its actions and the instance's groups.
 *
 * @param {Page} page The admin's page.
 * @return {Promise<{matrix: Matrix, actions: string[], groups: string[]}>} What the endpoint answers.
 */
async function readMatrix(
	page: Page,
): Promise<{ matrix: Matrix; actions: string[]; groups: string[] }> {
	const res = await page.request.get(apiUrl(MATRIX_API), {
		headers: { 'OCS-APIREQUEST': 'true' },
	})
	expect(res.status(), await res.text()).toBe(200)
	const body = await res.json()
	const matrix: Matrix = body.matrix ?? {}
	const actions: string[] = Array.isArray(body.actions)
		? body.actions
		: Object.keys(matrix)
	const groups: string[] = Array.isArray(body.groups) ? body.groups : []
	return { matrix, actions, groups }
}

/**
 * Store a whole matrix, as the page's Save does.
 *
 * @param {Page} page The admin's page, on a Nextcloud page so it has a request token.
 * @param {Matrix} matrix The matrix.
 * @return {Promise<number>} The HTTP status.
 */
async function putMatrix(page: Page, matrix: Matrix): Promise<number> {
	const requestToken = await page.evaluate(
		() => (window as any).OC?.requestToken ?? '',
	)
	const res = await page.request.put(apiUrl(MATRIX_API), {
		headers: {
			'Content-Type': 'application/json',
			requesttoken: requestToken,
			'OCS-APIREQUEST': 'true',
		},
		data: JSON.stringify({ matrix }),
	})
	return res.status()
}

/**
 * The checkbox of one action and one group in the matrix on the page.
 *
 * @param {Page} page The settings page.
 * @param {string} action The action id (the row header).
 * @param {string} group The group id (the column header).
 * @return {Promise<import('@playwright/test').Locator>} The checkbox.
 */
async function cell(page: Page, action: string, group: string) {
	const section = page.getByTestId('admin-action-auth-section')
	const headers = (
		await section.locator('thead th').allInnerTexts()
	).map((text) => text.trim())
	const column = headers.indexOf(group)
	expect(column, `the column of ${group}`).toBeGreaterThan(0)
	const row = section.locator('tbody tr').filter({
		has: page.locator('th', { hasText: new RegExp(`^\\s*${action.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*$`) }),
	})
	await expect(row).toHaveCount(1)
	// The first column is the row header, so group column n is the (n-1)th td.
	return row.locator('td').nth(column - 1).getByRole('checkbox')
}

test.describe('action-authorization: an administrator sets the matrix', () => {
	// @e2e openspec/changes/action-matrix-set-by-admins/specs/action-authorization/spec.md#scenario-an-administrator-lets-team-leads-run-an-action
	test('a ticked group for one action is kept after save and reload', async ({
		loggedInPage: page,
	}) => {
		await page.goto(SETTINGS_URL)
		await expect(page.getByTestId('admin-action-auth-section')).toBeVisible({
			timeout: 30_000,
		})

		const before = await readMatrix(page)
		const groups = before.groups.filter((group) => group !== 'admin')
		test.skip(
			before.actions.length === 0 || groups.length === 0,
			'this instance has no action or no group besides admin to tick',
		)

		// The scenario's own pair when the instance has it; otherwise the
		// first action with a group it does not allow yet.
		let action = PREFERRED.action
		let group = PREFERRED.group
		const allowed = (a: string): string[] => before.matrix[a] ?? []
		if (
			before.actions.includes(action) === false ||
			groups.includes(group) === false ||
			allowed(action).includes(group)
		) {
			const pick = before.actions
				.map((a) => ({ a, g: groups.find((g) => allowed(a).includes(g) === false) }))
				.find((p) => p.g !== undefined)
			test.skip(pick === undefined, 'every action already allows every group')
			action = pick!.a
			group = pick!.g!
		}

		try {
			const box = await cell(page, action, group)
			await expect(box).not.toBeChecked()
			await box.check({ force: true })
			await expect(box).toBeChecked()

			const saved = page.waitForResponse(
				(res) =>
					res.url().includes('/api/admin/action-matrix') &&
					res.request().method() === 'PUT',
			)
			await page.getByTestId('admin-action-matrix-save').click()
			expect((await saved).status()).toBe(200)

			await page.reload()
			await expect(page.getByTestId('admin-action-auth-section')).toBeVisible({
				timeout: 30_000,
			})
			await expect(await cell(page, action, group)).toBeChecked({
				timeout: 15_000,
			})
			// The rest of that row is as it was.
			for (const other of allowed(action).filter((g) => g !== 'admin')) {
				await expect(await cell(page, action, other)).toBeChecked()
			}

			// And the server holds it, so the next guarded request follows it.
			const after = await readMatrix(page)
			expect(after.matrix[action]).toContain(group)
		} finally {
			// Every action the page lists, as the page itself saves them: an
			// action the stored matrix never held goes back to admin-only.
			const whole: Matrix = Object.fromEntries(
				before.actions.map((a) => [a, before.matrix[a] ?? ['admin']]),
			)
			const restored = await putMatrix(page, whole)
			expect(restored, 'the matrix as it was before the test').toBe(200)
		}
	})
})
