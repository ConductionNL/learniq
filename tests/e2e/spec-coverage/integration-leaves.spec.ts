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
 * the leaf's required app. Each leaf widget declares `requiredApp`, so both
 * states draw a card: installed means the leaf itself, absent means
 * CnDetailWidgetHost's "{app} is not installed" set-up state.
 *
 * Found on the first live run (2026-09-29). The design assumed a provider
 * `isEnabled()` hides a leaf whose app is absent; no such check exists.
 * CnDetailPage draws any leaf whose provider is registered, and the four
 * providers register whatever the app state, so a disabled Deck drew
 * "No cards linked yet", a claim about data the page cannot see. The widgets
 * now declare `requiredApp`, which is the library's own answer.
 *
 * The anchor is the grid item's group, which the grid names after the widget
 * id (read from the effective manifest). Card headings are not a usable
 * anchor: calendar and deck leaves show the manifest title, while contacts
 * shows the registry label, and an earlier heading anchor passed the absent
 * case for contacts only because the heading never matched.
 *
 * The admin session comes from the global setup.
 */
import type { Page } from '@playwright/test'

import { expect, test } from '../fixtures.ts'
import { effectiveManifest } from '../effective-manifest.ts'
import { firstObjectId } from '../or-api.ts'
import { requireFixture } from '../seeded.ts'

interface LeafCase {
	page: string
	route: string
	slug: string
	leaves: Array<{ requiredApp: string }>
}

const CASES: LeafCase[] = [
	{
		page: 'SessionDetail',
		route: '/sessions',
		slug: 'session',
		leaves: [{ requiredApp: 'calendar' }],
	},
	{
		page: 'AssignmentDetail',
		route: '/assignments',
		slug: 'assignment',
		leaves: [
			{ requiredApp: 'calendar' },
			{ requiredApp: 'forms' },
		],
	},
	{
		page: 'CredentialDetail',
		route: '/credentials',
		slug: 'credential',
		leaves: [{ requiredApp: 'calendar' }],
	},
	{
		page: 'LearnerProfileDetail',
		route: '/learner-profiles',
		slug: 'learner-profile',
		leaves: [{ requiredApp: 'contacts' }],
	},
	{
		page: 'PraktijkopleiderDetail',
		route: '/bpv/praktijkopleiders',
		slug: 'praktijkopleider',
		leaves: [{ requiredApp: 'contacts' }],
	},
	{
		page: 'BpvPlacementDetail',
		route: '/bpv/placements',
		slug: 'bpv-placement',
		leaves: [{ requiredApp: 'deck' }],
	},
]

/**
 * The id of the page's integration widget for one leaf.
 *
 * @param pageId The detail page id.
 * @param integrationId The leaf's integration id (also its required app).
 * @return The widget id.
 */
function leafWidgetId(pageId: string, integrationId: string): string {
	const page = (effectiveManifest.pages as Array<Record<string, any>>).find(
		(p) => p.id === pageId,
	)
	const widget = (page?.config?.widgets ?? []).find(
		(w: Record<string, unknown>) =>
			w.type === 'integration' && w.integrationId === integrationId,
	)
	expect(
		widget?.id,
		`${pageId} declares no ${integrationId} integration widget`,
	).toBeTruthy()
	return String(widget.id)
}

/**
 * Scroll every scrollable ancestor on the page to its end, once.
 *
 * Integration leaves on a body grid mount only once they scroll into view:
 * measured 2026-09-29 on SessionDetail, the Agenda, Session materials and Join
 * call cards were absent from the DOM after 30 s without scrolling and present
 * straight after. The grid itself arrives seconds after the page shell, so the
 * callers repeat this while polling rather than scrolling once up front.
 *
 * @param page The page.
 */
async function scrollToEnd(page: Page): Promise<void> {
	await page.evaluate(() => {
		document.querySelectorAll('*').forEach((el) => {
			const style = getComputedStyle(el)
			if (
				el.scrollHeight > el.clientHeight + 50 &&
				(style.overflowY === 'auto' || style.overflowY === 'scroll')
			) {
				el.scrollTop = el.scrollHeight
			}
		})
	})
}

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
	// The leaves mount on scroll after the grid loads; a cold detail page on a
	// busy instance takes 20-30 s before the poll even starts.
	test.describe.configure({ timeout: 120_000 })

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
				// The grid names each item's group after its widget id.
				const card = page.getByRole('group', {
					name: leafWidgetId(c.page, leaf.requiredApp),
					exact: true,
				})
				await expect
					.poll(
						async () => {
							await scrollToEnd(page)
							return card.count()
						},
						{
							message: `${c.page} draws no card for its ${leaf.requiredApp} leaf`,
							timeout: 45_000,
						},
					)
					.toBeGreaterThan(0)
				await card.first().scrollIntoViewIfNeeded()
				await expect(card.first()).toBeVisible()

				// The widget declares `requiredApp`, so an absent app answers with the
				// host's set-up state instead of an empty leaf that claims nothing is
				// linked yet (CnDetailWidgetHost `missingApp`).
				const setUpState = card.first().getByText(/is not installed/i)
				if (apps.has(leaf.requiredApp)) {
					await expect(
						setUpState,
						`${c.page} says ${leaf.requiredApp} is not installed although it is enabled`,
					).toHaveCount(0)
				} else {
					await expect(
						setUpState,
						`${c.page} draws a ${leaf.requiredApp} leaf without saying the app is missing`,
					).toBeVisible()
				}
			}
		})
	}
})
