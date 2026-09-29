/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage for the leaf-integrations change: the calendar,
 * contacts, forms and deck leaves declared on six detail pages.
 *
 * Covers:
 *   @e2e openspec/changes/leaf-integrations/specs/integration-leaves/spec.md#scenario-a-teacher-links-a-renewal-event-to-an-expiring-credential
 *   @e2e openspec/changes/leaf-integrations/specs/integration-leaves/spec.md#scenario-a-bpv-coordinator-links-the-practical-trainers-contact-card
 *   @e2e openspec/changes/leaf-integrations/specs/integration-leaves/spec.md#scenario-an-assignment-gains-a-structured-intake-form
 *   @e2e openspec/changes/leaf-integrations/specs/integration-leaves/spec.md#scenario-a-school-coach-tracks-a-placement-chase-as-a-card
 *
 * What the browser can answer: the widget is wired onto the page and follows
 * the leaf's required app. CnDetailPage hides an integration widget whose
 * `requiredApp` is not installed (nextcloud-vue CnDetailPage, the
 * `requiredApp` check in the grid visibility test), so both states are
 * asserted: installed means the card header with the leaf's label is visible,
 * absent means no card for that leaf. The manifest `title` is a page-authoring
 * label and is not rendered (see talk-classroom-spaces.spec.ts), so the
 * anchor is the registry leaf's label from nextcloud-vue.
 *
 * The admin session comes from the global setup.
 */
import type { Page } from '@playwright/test'

import { expect, test } from '../fixtures.ts'
import { firstObjectId } from '../or-api.ts'
import { requireFixture } from '../seeded.ts'

interface LeafCase {
	page: string
	route: string
	slug: string
	leaves: Array<{ requiredApp: string; label: string }>
}

const CASES: LeafCase[] = [
	{
		page: 'SessionDetail',
		route: '/sessions',
		slug: 'session',
		leaves: [{ requiredApp: 'calendar', label: 'Meetings' }],
	},
	{
		page: 'AssignmentDetail',
		route: '/assignments',
		slug: 'assignment',
		leaves: [
			{ requiredApp: 'calendar', label: 'Meetings' },
			{ requiredApp: 'forms', label: 'Forms' },
		],
	},
	{
		page: 'CredentialDetail',
		route: '/credentials',
		slug: 'credential',
		leaves: [{ requiredApp: 'calendar', label: 'Meetings' }],
	},
	{
		page: 'LearnerProfileDetail',
		route: '/learner-profiles',
		slug: 'learner-profile',
		leaves: [{ requiredApp: 'contacts', label: 'Contacts' }],
	},
	{
		page: 'PraktijkopleiderDetail',
		route: '/bpv/praktijkopleiders',
		slug: 'praktijkopleider',
		leaves: [{ requiredApp: 'contacts', label: 'Contacts' }],
	},
	{
		page: 'BpvPlacementDetail',
		route: '/bpv/placements',
		slug: 'bpv-placement',
		leaves: [{ requiredApp: 'deck', label: 'Cards' }],
	},
]

/**
 * The app ids enabled on the instance under test, read from the provisioning
 * API (the global setup signs in as admin).
 *
 * @param page An authenticated page.
 * @return The enabled app ids.
 */
async function enabledApps(page: Page): Promise<Set<string>> {
	const resp = await page.request.get(
		'/ocs/v2.php/cloud/apps?filter=enabled&format=json',
		{ headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' } },
	)
	expect(resp.ok(), 'the provisioning API did not list the enabled apps').toBe(
		true,
	)
	const json = await resp.json()
	return new Set<string>(json?.ocs?.data?.apps ?? [])
}

test.describe('leaf-integrations: calendar, contacts, forms and deck leaves', () => {
	for (const c of CASES) {
		// @e2e openspec/changes/leaf-integrations/specs/integration-leaves/spec.md
		test(`${c.page} carries its leaf widgets`, async ({
			loggedInPage: page,
		}) => {
			const id = await firstObjectId(page, c.slug)
			requireFixture(id, `a ${c.slug} object`)

			const apps = await enabledApps(page)
			test.info().annotations.push({
				type: 'precondition',
				description: c.leaves
					.map(
						(l) =>
							`${l.requiredApp} enabled: ${apps.has(l.requiredApp)}`,
					)
					.join(', '),
			})

			await page.goto(`/index.php/apps/learniq${c.route}/${id}`, {
				waitUntil: 'domcontentloaded',
			})
			await expect(page.locator('[data-testid="cn-detail-page"]')).toBeVisible(
				{
					timeout: 20_000,
				},
			)

			for (const leaf of c.leaves) {
				const header = page.locator('.cn-integration-widget__header-label', {
					hasText: leaf.label,
				})
				if (apps.has(leaf.requiredApp)) {
					await expect(
						header.first(),
						`${c.page} shows no ${leaf.requiredApp} leaf although the app is enabled`,
					).toBeVisible({ timeout: 15_000 })
				} else {
					await expect(
						header,
						`${c.page} renders a ${leaf.requiredApp} leaf although the app is not installed`,
					).toHaveCount(0)
				}
			}
		})
	}
})
