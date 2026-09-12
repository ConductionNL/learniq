/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — certification and expiry, the wedge's third flow.
 *
 * Covers (UI-observable surface):
 *   @e2e openspec/specs/certification/spec.md#credential-issued-with-verifiable-url
 *   @e2e openspec/specs/certification/spec.md#daily-expiry-detection-dispatches-tiered-notifications
 *
 * The daily detection job, the tiered notification cascade, wallet offers and
 * revocation propagation are backend behaviours with PHPUnit cover and are
 * annotated `@e2e exclude` in the spec. A browser can answer one thing the
 * job cannot: whether the officer can actually see which credentials are
 * expiring, which is the question the whole schedule exists to answer.
 *
 * The admin session comes from the global setup.
 */
import { expect, test } from '../fixtures.ts'
import { openAndExpectSchemaLoads, watchConsole } from './wedge-helpers.ts'

test.describe('certification — credentials and expiry', () => {
	// @e2e openspec/specs/certification/spec.md#credential-issued-with-verifiable-url
	test('the credentials index reads the Credential schema', async ({
		loggedInPage: page,
	}) => {
		await openAndExpectSchemaLoads(page, '/credentials', 'credential')
	})

	// @e2e openspec/specs/certification/spec.md#daily-expiry-detection-dispatches-tiered-notifications
	test('the credentials index exposes the expiry field to filter or sort on', async ({
		loggedInPage: page,
	}) => {
		const body = await openAndExpectSchemaLoads(
			page,
			'/credentials',
			'credential',
		)

		// Expiry detection is only useful if the expiry date reaches the
		// officer. Assert the API actually returns the field the schedule keys
		// on, rather than asserting a column header that a theme could rename.
		const results = (body as { results?: unknown[] } | null)?.results ?? []

		if (results.length === 0) {
			test.skip(
				true,
				'no credentials seeded on this instance — field presence needs a row',
			)
			return
		}

		const first = results[0] as Record<string, unknown>
		const expiryKey = Object.keys(first).find((k) =>
			/expir|validUntil|valid_to/i.test(k),
		)

		expect(
			expiryKey,
			`a Credential carries no expiry-shaped field. Keys: ${Object.keys(first).join(', ')}`,
		).toBeDefined()
	})

	// @e2e openspec/specs/certification/spec.md#credential-issued-with-verifiable-url
	test('the credentials index renders without a fatal error', async ({
		loggedInPage: page,
	}) => {
		const errors = watchConsole(page)

		await page.goto('/index.php/apps/learniq/credentials', {
			waitUntil: 'domcontentloaded',
		})
		await page.waitForSelector('body', { timeout: 15_000 })

		const text = await page.innerText('body')
		expect(text.trim().length).toBeGreaterThan(0)

		const fatal = errors()
		expect(fatal, `credentials raised: ${fatal.join(' | ')}`).toHaveLength(0)
	})
})
