/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Shared helpers for the v0.1 compliance-wedge e2e specs.
 *
 * The wedge is what Learniq is sold on: bulk-enrol every employee, capture a
 * signed attestation, detect the certificate that is about to expire, and
 * export an audit pack. Those four flows had no behavioural e2e coverage,
 * while Phase-2 school features each had a dedicated spec file.
 *
 * These helpers assert on the OpenRegister response rather than on the DOM
 * alone. A page whose schema stops resolving still renders a perfectly
 * pleasant empty state, so "the body has text" cannot tell a working surface
 * from a broken one. The request either comes back 2xx or the surface is
 * broken, and that is the assertion worth having.
 */
import type { Page } from '@playwright/test'

import { expect } from '@playwright/test'

/** Console noise that says nothing about the surface under test. */
const IGNORABLE = [
	'favicon',
	'font',
	'Failed to load resource',
	'net::ERR_ABORTED',
	'Failed to fetch',
	'ERR_CONNECTION_REFUSED',
	'Vue in development mode',
]

/**
 * Collect Learniq-originated console errors while a block runs.
 *
 * @param page The Playwright page to listen on.
 * @return A getter returning the filtered error list.
 */
export function watchConsole(page: Page): () => string[] {
	const errors: string[] = []
	page.on('console', (msg) => {
		if (msg.type() === 'error') {
			errors.push(msg.text())
		}
	})
	return () => errors.filter((e) => !IGNORABLE.some((skip) => e.includes(skip)))
}

/**
 * Open a Learniq route and assert its OpenRegister object call succeeds.
 *
 * Waits for the first response whose URL names the given schema under the
 * learniq register, then asserts a 2xx. A schema that is not registered
 * answers 4xx/5xx here while the page still paints an empty list, which is
 * exactly the regression this catches.
 *
 * The schema name is matched loosely on purpose. PHP addresses a schema by
 * its kebab-case slug (`external-training-record`) while the manifest-driven
 * UI requests it by its PascalCase key (`ExternalTrainingRecord`), and
 * OpenRegister resolves both. Matching the literal string therefore misses
 * the request the browser actually makes, which reads as "the page never
 * loaded" rather than "the matcher is too strict".
 *
 * @param page   The Playwright page.
 * @param route  App-relative route, e.g. '/enrolments'.
 * @param schema OpenRegister schema, in either slug or key form.
 * @return The parsed response body.
 */
export async function openAndExpectSchemaLoads(
	page: Page,
	route: string,
	schema: string,
): Promise<unknown> {
	const url = `/index.php/apps/learniq${route}`
	const prefix = '/apps/openregister/api/objects/learniq/'
	const normalise = (value: string) =>
		value.toLowerCase().replace(/[^a-z0-9]/g, '')
	const wanted = normalise(schema)

	const matches = (candidate: string): boolean => {
		const at = candidate.indexOf(prefix)
		if (at === -1) {
			return false
		}
		const segment = candidate.slice(at + prefix.length).split(/[?/#]/)[0]
		return normalise(segment) === wanted
	}

	// 20s, not 30s. CI runs these under tests/e2e/playwright.config.ts, whose
	// per-test budget is 40s. A 30s wait here plus a 20s visibility wait in the
	// caller exceeds that, and the test then dies on the budget with a bare
	// timeout instead of on the assertion that would have named the cause.
	const responsePromise = page.waitForResponse(
		(response) => matches(response.url()),
		{ timeout: 20_000 },
	)

	await page.goto(url, { waitUntil: 'domcontentloaded' })

	const response = await responsePromise
	expect(
		response.status(),
		`${prefix}${schema} answered ${response.status()} — the surface at ${route} `
			+ 'cannot read its own schema, so it renders an empty state that looks fine.',
	).toBeLessThan(400)

	return await response.json().catch(() => null)
}

/**
 * Assert a route paints something and raises no Learniq console error.
 *
 * Used for the custom views that make no single canonical object call.
 *
 * @param page  The Playwright page.
 * @param route App-relative route.
 */
export async function openAndExpectNoFatal(
	page: Page,
	route: string,
): Promise<void> {
	const errors = watchConsole(page)

	await page.goto(`/index.php/apps/learniq${route}`, {
		waitUntil: 'domcontentloaded',
	})
	await page.waitForSelector('body', { timeout: 15_000 })

	const body = await page.innerText('body')
	expect(body.trim().length, `${route} painted nothing`).toBeGreaterThan(0)

	const fatal = errors()
	expect(fatal, `${route} raised: ${fatal.join(' | ')}`).toHaveLength(0)
}
