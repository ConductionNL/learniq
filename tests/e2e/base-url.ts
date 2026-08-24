// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Single source of truth for the Nextcloud instance the e2e suite targets.
 *
 * WHY THIS EXISTS
 * ---------------
 * Before this module, three separate defaults resolved the target as
 * `process.env.PW_BASE_URL ?? 'http://localhost:8080'` (playwright.config.ts,
 * global-setup.ts, seed-example-data.mjs) and four specs in
 * spec-coverage/nextcloud-app.spec.ts built ABSOLUTE
 * `http://localhost:8080/...` request URLs that ignored `baseURL` entirely.
 *
 * `localhost:8080` is the SHARED developer Nextcloud container, which
 * bind-mounts real host checkouts. Running the suite without setting
 * `PW_BASE_URL` therefore fired admin logins, a register import, an example
 * dataset seed, `POST /api/settings` and `PUT /api/notification-preferences`
 * into somebody else's working environment — and reported the results as
 * though they came from the branch under test.
 *
 * Rules encoded here:
 *   1. NEVER a `localhost:8080` literal fallback. An unset target is a hard
 *      error, not a silent redirect to the shared box.
 *   2. Accept every name the shared CI quality workflow exports. Its "Run
 *      Playwright tests" step sets `BASE_URL`, `NEXTCLOUD_URL` AND
 *      `NC_BASE_URL` (its "Seed test data" step sets the same three), and it
 *      does NOT set `PLAYWRIGHT_BASE_URL`. A `PLAYWRIGHT_BASE_URL`-only
 *      resolver hard-fails every CI run — a sibling repo shipped exactly that
 *      and its E2E job has been red on every run since. All five names are
 *      accepted here; only the hardcoded fallback is gone.
 *   3. Specs that need an absolute URL must build it from `baseUrl()`, so
 *      relative `goto()` and absolute `request.get()` in the same spec can
 *      never disagree about which instance they are talking to.
 */

/**
 * Resolve the base URL of the Nextcloud instance under test.
 *
 * @throws {Error} when no target environment variable is set.
 * @return {string} The base URL, without a trailing slash.
 */
export function baseUrl(): string {
	const candidates = [
		'PLAYWRIGHT_BASE_URL',
		'BASE_URL',
		'NEXTCLOUD_URL',
		'NC_BASE_URL',
		'PW_BASE_URL',
	] as const

	for (const name of candidates) {
		const value = process.env[name]
		if (value && value.trim() !== '') {
			return value.trim().replace(/\/+$/, '')
		}
	}

	throw new Error(
		'No Nextcloud base URL configured for the e2e suite. Set one of '
			+ candidates.join(', ')
			+ '. There is deliberately no default: the old default was the SHARED '
			+ 'developer instance on :8080, which bind-mounts real host checkouts.',
	)
}

/**
 * Build an absolute URL against the instance under test.
 *
 * @param {string} pathname Absolute path, e.g. `/apps/learniq/api/settings`.
 * @return {string} Fully-qualified URL on the instance under test.
 */
export function apiUrl(pathname: string): string {
	return `${baseUrl()}${pathname.startsWith('/') ? pathname : `/${pathname}`}`
}
