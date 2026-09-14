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
 * Written, not run, in the change that added it: it needs an instance with
 * both learniq and integriq.
 *
 * @e2e openspec/changes/adopt-connection-registry/specs/integrations/spec.md#the-page-lists-only-learniqs-rows
 * @e2e openspec/changes/adopt-connection-registry/specs/integrations/spec.md#add-integration-goes-to-integriq
 * @e2e openspec/changes/adopt-connection-registry/specs/integrations/spec.md#a-connection-to-a-missing-endpoint-reads-not-available-and-names-the-path
 */
import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from './fixtures.ts'

/** Integriq's objects endpoint for learniq's connection rows. */
const CONNECTIONS_API = '/index.php/apps/openregister/api/objects/integriq/app_connection?app=learniq&_limit=50'

/** The declared keys and titles, in declared order. */
const DECLARED = [
	{ key: 'data-exchange', title: 'Data exchange' },
	{ key: 'timetable', title: 'Timetable import' },
	{ key: 'lti', title: 'LTI tools' },
	{ key: 'eudi-wallet', title: 'EUDI wallet' },
	{ key: 'payment', title: 'Payment provider' },
	{ key: 'sbb', title: 'SBB leerbedrijf check' },
	{ key: 'proctoring', title: 'Proctoring' },
	{ key: 'plagiarism', title: 'Plagiarism check' },
]

/**
 * Learniq's connection rows, keyed by connection key.
 *
 * @param request An admin request context.
 * @return The rows by key.
 */
async function rowsByKey(request: APIRequestContext): Promise<Record<string, Record<string, unknown>>> {
	const res = await request.get(CONNECTIONS_API, { headers: { Accept: 'application/json' } })
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
	await page.goto('/index.php/apps/learniq/settings/integrations?app=learniq', { timeout: 60_000 })
	await expect(page.locator('.cn-index-page')).toBeVisible({ timeout: 30_000 })
}

test.describe('Integrations over the connection registry', () => {
	test('lists the eight declared connections, all of them learniq\'s', async ({ loggedInPage: page }) => {
		const byKey = await rowsByKey(page.request)
		expect(Object.keys(byKey).sort()).toEqual(DECLARED.map((d) => d.key).sort())

		await openIntegrations(page)
		for (const { title } of DECLARED) {
			await expect(page.getByRole('row', { name: new RegExp(`^${title}\\b`, 'i') })).toHaveCount(1)
		}
	})

	test('says data exchange cannot run, and why', async ({ loggedInPage: page }) => {
		const dataExchange = (await rowsByKey(page.request))['data-exchange']

		expect(dataExchange?.status).toBe('unavailable')
		expect(String(dataExchange?.statusMessage)).toContain('api/sources/[target]/run')
		expect(dataExchange?.settingsUrl).toBe('/settings/admin/learniq#section-data-exchange')
	})

	test('sends Add integration to integriq instead of offering a form', async ({ loggedInPage: page }) => {
		await openIntegrations(page)

		// No generic Add button: a row nothing declared has nothing to check.
		await expect(page.locator('[data-testid="cn-cta-primary"]')).toHaveCount(0)

		// The action lives in the overflow menu. English and Dutch are the two
		// catalogues this change ships, and nothing forces the E2E locale.
		await page.locator('[data-testid="cn-actions"] button').first().click()
		await Promise.all([
			page.waitForURL(/\/apps\/integriq\/connections\?app=learniq&link=1$/, { timeout: 30_000 }),
			page.getByRole('menuitem', { name: /Add integration|Integratie toevoegen/i }).click(),
		])
	})
})
