/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Integrations page over integriq's connection registry
 * (adopt-connection-registry, hydra connection-registry D8 and D9).
 *
 * WHERE THE ROWS COME FROM. The rows are integriq's `app_connection` objects,
 * synced from learniq's `lib/Settings/connections.json`, with `app` equal to
 * `learniq`. Learniq writes no row. So this spec needs integriq installed and
 * synced, and reads the rows from
 * `/apps/openregister/api/objects/integriq/app_connection?app=learniq`.
 *
 * `app` is a BARE filter key. The objects endpoint reads `filter[app]` as a
 * filter on nothing and answers the empty set without an error.
 *
 * Locale: nothing forces the E2E language, so statuses are read from the API
 * and rows are found by their declared titles, which are not translated.
 *
 * First run 2026-09-29 against the shared dev instance (learniq + integriq).
 *
 * @e2e openspec/specs/integrations/spec.md#the-page-lists-only-learniqs-rows
 * @e2e openspec/specs/integrations/spec.md#add-integration-goes-to-integriq
 * @e2e openspec/specs/course-management/spec.md#scenario-an-administrator-sees-lti-as-working
 */
import type { APIRequestContext, Page } from '@playwright/test'

import * as fs from 'fs'
import * as path from 'path'
import { expect, test } from './fixtures.ts'

/** Integriq's objects endpoint for learniq's connection rows. */
const CONNECTIONS_API =
	'/index.php/apps/openregister/api/objects/integriq/app_connection?app=learniq&_limit=50'

/**
 * The declared keys and titles, read from the declaration itself.
 *
 * This list was hardcoded when the spec was written and went stale twice
 * before its first run: #1082 renamed `payment` to "Payments through
 * shillinq", and a hardcoded title makes the page look broken when only the
 * copy moved. The declaration is the authority for both, so read it.
 */
const DECLARED: Array<{ key: string; title: string }> = (
	JSON.parse(
		fs.readFileSync(
			path.join(__dirname, '..', '..', 'lib', 'Settings', 'connections.json'),
			'utf8',
		),
	).connections as Array<{ key: string; title: string }>
).map(({ key, title }) => ({ key, title }))

/**
 * Learniq's connection rows, keyed by connection key.
 *
 * @param request An admin request context.
 * @return The rows by key.
 */
async function rowsByKey(
	request: APIRequestContext,
): Promise<Record<string, Record<string, unknown>>> {
	const res = await request.get(CONNECTIONS_API, {
		headers: { Accept: 'application/json' },
	})
	expect(res.ok(), `list integriq/app_connection -> ${res.status()}`).toBeTruthy()
	const body = await res.json()
	const byKey: Record<string, Record<string, unknown>> = {}
	for (const row of (body.results ?? []) as Record<string, unknown>[]) {
		// A row from another app here means the bare filter was dropped.
		expect(String(row.app), 'a connection row from another app').toBe('learniq')
		byKey[String(row.key)] = row
	}
	return byKey
}

/**
 * Open the Integrations page the way its menu entry does, with the preset.
 *
 * @param page The Playwright page.
 */
async function openIntegrations(page: Page): Promise<void> {
	await page.goto('/index.php/apps/learniq/settings/integrations?app=learniq', {
		timeout: 60_000,
	})
	await expect(page.locator('.cn-index-page')).toBeVisible({ timeout: 30_000 })
}

test.describe('Integrations over the connection registry', () => {
	test("lists the eight declared connections, all of them learniq's", async ({
		loggedInPage: page,
	}) => {
		const byKey = await rowsByKey(page.request)
		expect(Object.keys(byKey).sort()).toEqual(DECLARED.map((d) => d.key).sort())

		await openIntegrations(page)
		for (const { title } of DECLARED) {
			// Matched through the title cell, not an anchored row name: a row's
			// accessible name starts with its "Select row" checkbox, so `^title`
			// can never match (proven in keepiq#717).
			await expect(
				page.getByRole('row').filter({
					has: page.getByRole('cell', { name: title, exact: true }),
				}),
			).toHaveCount(1)
		}
	})

	/*
	 * The scenario this replaces ("data exchange reads Not available and names
	 * api/sources/[target]/run") lost its subject in #1157: integriq carries the
	 * exchanges now, and `data-exchange` is declared available. No declared row
	 * names a missing endpoint any more; ConnectionsDeclarationTest still guards
	 * that any future unavailable message names the path the code calls.
	 */
	test('data exchange is declared available and offers its settings', async ({
		loggedInPage: page,
	}) => {
		const dataExchange = (await rowsByKey(page.request))['data-exchange']

		expect(dataExchange?.status).not.toBe('unavailable')
		expect(dataExchange?.settingsUrl).toBe(
			'/settings/admin/learniq#section-data-exchange',
		)
	})

	/*
	 * content-lti-launch-through-integriq: the `lti` row is reportedOnly, so its
	 * status is what learniq last reported (ConnectionReportService::observeLti),
	 * not anything integriq works out itself.
	 */
	test('the LTI row reads what learniq reported', async ({
		loggedInPage: page,
	}) => {
		const lti = (await rowsByKey(page.request)).lti
		const report = lti?.lastReport as Record<string, unknown> | undefined

		expect(report, 'learniq has not reported the lti row yet').toBeTruthy()
		expect(lti?.status).toBe(report?.status)
		// Integriq ships the launch event wherever this suite runs (CI installs it).
		expect(lti?.status).toBe('configured')
		expect(lti?.settingsUrl).toBe('/settings/admin/learniq#section-lti')
	})

	test('sends Add integration to integriq instead of offering a form', async ({
		loggedInPage: page,
	}) => {
		await openIntegrations(page)

		// No generic Add button: a row nothing declared has nothing to check.
		await expect(page.locator('[data-testid="cn-cta-primary"]')).toHaveCount(0)

		// The action lives in the overflow menu. English and Dutch are the two
		// catalogues this change ships, and nothing forces the E2E locale.
		await page.locator('[data-testid="cn-actions"] button').first().click()
		// Wait for the navigation to COMMIT, not for `load`: integriq's page pulls
		// every app's bundle and ran past the test timeout before `load` on the
		// shared instance, and integriq drops `link=1` from the address once it has
		// opened the dialog, so an end-anchored URL match can miss it as well.
		await Promise.all([
			page.waitForURL(/\/apps\/integriq\/connections\?app=learniq/, {
				timeout: 30_000,
				waitUntil: 'commit',
			}),
			page
				.getByRole('menuitem', {
					name: /Add integration|Integratie toevoegen/i,
				})
				.click(),
		])
		// What `link=1` asks for: integriq's link dialog, preset to learniq.
		const dialog = page.getByRole('dialog', {
			name: /Add integration|Integratie toevoegen/i,
		})
		await expect(dialog).toBeVisible({ timeout: 45_000 })
		await expect(dialog.getByText('learniq', { exact: true })).toBeVisible()
	})
})
