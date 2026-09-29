/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every static `type: "custom"` page mounts its component.
 *
 * registry-component-fix found 14 custom pages naming a component that
 * src/registry.js never registered. CnPageRenderer resolves a custom page's
 * component only against the app's registry, so a miss renders its builder
 * empty state, "This page is empty", on a route that looks fine in every
 * other test. tests/unit-js/registryComponentCoverage.test.mjs guards the
 * registry keys statically. This spec is the browser half: it opens every
 * static custom route in the effective manifest and asserts that the empty
 * state never appears.
 *
 * pages.spec.ts was named as the browser check for this change, but its
 * route table is 24 hardcoded entries and covers few of these pages.
 *
 * Dynamic routes (`:id`) are left out: with a placeholder id they render a
 * not-found state, which says nothing about whether the component resolved.
 *
 * @e2e openspec/changes/registry-component-fix/specs/component-registry/spec.md#requirement-every-type-custom-manifest-pages-component-must-be-registered
 */
import { effectiveManifest } from './effective-manifest.ts'
import { expect, test } from './fixtures.ts'

const APP_BASE = '/index.php/apps/learniq'

const CUSTOM_PAGES = effectiveManifest.pages.filter(
	(p) => p.type === 'custom' && !p.route.includes(':'),
)

test.describe('custom pages mount their component', () => {
	test('the manifest declares static custom pages to check', () => {
		expect(CUSTOM_PAGES.length).toBeGreaterThan(0)
	})

	for (const page of CUSTOM_PAGES) {
		test(`${page.id} (${page.route}) is not an empty page`, async ({
			loggedInPage,
		}) => {
			await loggedInPage.goto(`${APP_BASE}${page.route}`, {
				waitUntil: 'domcontentloaded',
				timeout: 60_000,
			})
			await expect(loggedInPage.locator('[data-testid="cn-nav"]')).toBeVisible(
				{ timeout: 45_000 },
			)
			// The renderer decides between the component and the empty state as
			// soon as the page resolves; give a slow instance time to get there.
			await expect(loggedInPage.locator('.cn-page-renderer')).toBeAttached({
				timeout: 30_000,
			})
			await expect(
				loggedInPage.locator('.cn-page-renderer__empty'),
				`${page.id} names component ${page.component ?? '(none)'} and renders "This page is empty"`,
			).toHaveCount(0)
		})
	}
})
