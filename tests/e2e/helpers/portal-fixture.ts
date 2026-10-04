// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * What the pupil, trainer and assessor flows need before they can sign in.
 *
 * The guardian flows (po-parent-flows.spec.ts) sign in through a stub DigiD
 * broker, because a guardian is a DigiD citizen and her collections demand
 * `minTrust: substantial`. The three audiences here sign in differently, and
 * this module builds those paths rather than bending them through the DigiD
 * stub:
 *
 *   - a PUPIL signs in with her school account: portaliq's `nextcloud`
 *     authentication mode, where the Nextcloud session IS the credential;
 *   - a TRAINER and an ASSESSOR are invited. They are not DigiD citizens and
 *     the school has no eHerkenning contract with every leerbedrijf, so they
 *     sign in with an account the school gave them, through the same
 *     `nextcloud` mode.
 *
 * All three therefore reach the portal at trust `low` — the level a username
 * and password carries (portaliq SessionController::nextcloud, deliberately
 * below DigiD) — and learniq records that as assurance `basic` on anything
 * they write (an-invited-trainer-may-assess). That is the honest level, and it
 * is what the collections of these three audiences declare (`minTrust: low`).
 * A trainer who does have eHerkenning reaches `substantial` through the
 * broker; a school that insists on it raises the floor with
 * `bpv_assessment_min_assurance`, which is asserted on the API, not here.
 *
 * WHAT THESE HELPERS TOUCH ON THE INSTANCE, AND WHAT THEY PUT BACK. Every
 * account, row and user a suite needs is created by the suite and removed
 * again. The one thing that is changed rather than created is the portal's
 * list of sign-in modes: `nextcloud` is added when the portal does not offer
 * it, and `restore()` writes the original list back. Nothing else is written:
 * no app config, no broker config, no example set.
 *
 * The claim is what makes a portal account resolve rows at all
 * (`scopeClaim` in learniq's manifests), and `grantPortalAccount` writes the
 * same claim learniq's own invitations write:
 * `learniq:portal:invite-trainer` / `:invite-assessor` (learniq#1680) and
 * `POST /apps/learniq/api/portal/guardians/{ref}/invite`. A suite seeds it
 * directly because an e2e has no `occ`, and the invitation itself is covered
 * by its own unit tests.
 */

import type { APIRequestContext, Browser, Page } from '@playwright/test'

import { expect, request } from '@playwright/test'
import * as path from 'node:path'
import { baseUrl } from '../base-url.ts'

/** The sessionStorage key the site keeps its bearer under. */
export const SITE_TOKEN_KEY = 'portaliq.session.token'

/** A row this run created, so a suite can take it away again. */
export type SeededRow = { register: string; schema: string; id: string }

/** An account signed in to the site. */
export type PortalLogin = { page: Page; token: string }

/**
 * An API context authenticated as a Nextcloud account.
 *
 * @param {{user: string, pass: string}} who The account.
 * @return {Promise<APIRequestContext>} The context.
 */
export async function asUser(who: {
	user: string
	pass: string
}): Promise<APIRequestContext> {
	return await request.newContext({
		baseURL: baseUrl(),
		extraHTTPHeaders: {
			'OCS-APIRequest': 'true',
			Authorization: `Basic ${Buffer.from(`${who.user}:${who.pass}`).toString('base64')}`,
		},
	})
}

/**
 * Create an OpenRegister object and remember it for cleanup.
 *
 * @param {APIRequestContext} api The caller.
 * @param {SeededRow[]} created The cleanup ledger, appended to.
 * @param {string} register The register.
 * @param {string} schema The schema slug.
 * @param {object} data The object.
 * @return {Promise<Record<string, any>>} The created object.
 */
export async function createRow(
	api: APIRequestContext,
	created: SeededRow[],
	register: string,
	schema: string,
	data: Record<string, unknown>,
): Promise<Record<string, any>> {
	const res = await api.post(
		`/apps/openregister/api/objects/${register}/${schema}`,
		{ data },
	)
	expect(res.status(), await res.text()).toBeLessThan(300)
	const row = await res.json()
	const id = String(row?.id ?? row?.uuid ?? '')
	expect(id, `created ${schema} without an id`).not.toBe('')
	created.push({ register, schema, id })
	return row
}

/**
 * Remove everything a run created, newest first, and report what stayed.
 *
 * A refusal is reported and never thrown: the suite's verdict is the tests',
 * not the cleanup's (po-parent-flows).
 *
 * @param {APIRequestContext} api The caller.
 * @param {SeededRow[]} created The ledger, emptied.
 * @param {string} label The suite's name for the log line.
 * @return {Promise<void>}
 */
export async function removeRows(
	api: APIRequestContext,
	created: SeededRow[],
	label: string,
): Promise<void> {
	const failed: string[] = []
	for (const row of [...created].reverse()) {
		const res = await api
			.delete(
				`/apps/openregister/api/objects/${row.register}/${row.schema}/${row.id}`,
			)
			.catch(() => null)
		if (!res || res.status() >= 300) {
			failed.push(`${row.schema}/${row.id}${res ? ` (${res.status()})` : ''}`)
		}
	}
	console.log(
		`${label} cleanup: removed ${created.length - failed.length} of ${created.length} row(s)`,
	)
	if (failed.length > 0) {
		console.warn(`${label} cleanup could not remove: ${failed.join(', ')}`)
	}
	created.length = 0
}

/**
 * Learner profiles a suite may hang its own rows on.
 *
 * WHY A SUITE DOES NOT CREATE THEM. `learner-profile` is an archival schema
 * (`x-openregister-archival`, retention P5Y), and OpenRegister refuses a
 * user-driven delete on such a schema: a suite that created profiles would
 * leave one behind on every run, for five years. So it borrows existing ones
 * and removes only its own, deletable rows.
 *
 * It borrows profiles with no guardian on them, so a run can never put a row
 * in front of a guardian reading her child's portal in po-parent-flows.
 *
 * @param {APIRequestContext} admin An admin context.
 * @param {number} count How many are needed.
 * @return {Promise<Array<Record<string, any>>>} The profiles, fewer than asked for when the instance has fewer.
 */
export async function borrowLearnerProfile(
	admin: APIRequestContext,
	learnerRef: string,
): Promise<Record<string, any> | undefined> {
	const res = await admin.get(
		`/apps/openregister/api/objects/learniq/learner-profile/${learnerRef}`,
	)
	if (res.status() !== 200) {
		return undefined
	}

	return await res.json()
}

/**
 * A published assignment with pupils on it, and the pupils it is for.
 *
 * WHY A SUITE DOES NOT CREATE ONE. `Assignment.learnerRefs` is derived by
 * `AssignmentLearnerRefsStamp` from the enrolments of the assignment's group,
 * and a value sent for it is replaced — so an assignment a suite invents has
 * no pupils on it and the pupil's homework collection stays empty. Borrowing
 * one that the school really published is the honest fixture, and it is read
 * only, so there is nothing to clean up.
 *
 * @param {APIRequestContext} admin An admin context.
 * @param {string[]} except Learner uuids to leave alone (other suites' people).
 * @return {Promise<{assignment: Record<string, any>, learnerRefs: string[]}|undefined>} The assignment and its pupils.
 */
export async function borrowPublishedHomework(
	admin: APIRequestContext,
	except: string[] = [],
): Promise<{ assignment: Record<string, any>; learnerRefs: string[] } | undefined> {
	const res = await admin.get(
		'/apps/openregister/api/objects/learniq/assignment?_limit=50',
	)
	expect(res.status(), await res.text()).toBe(200)
	const rows: Array<Record<string, any>> = (await res.json()).results ?? []
	for (const assignment of rows) {
		if (String(assignment.lifecycle ?? '') !== 'published') {
			continue
		}

		const learnerRefs = (assignment.learnerRefs ?? []).filter(
			(ref: string) => except.includes(ref) === false,
		)
		if (learnerRefs.length >= 2) {
			return { assignment, learnerRefs }
		}
	}

	return undefined
}

/**
 * Whether the instance already has this Nextcloud account.
 *
 * A suite only ever creates and removes accounts of its own, so it asks first:
 * an account that was already there belongs to somebody else, and its password
 * is not the suite's to set.
 *
 * @param {APIRequestContext} admin An admin context.
 * @param {string} uid The account.
 * @return {Promise<boolean>} True when it exists.
 */
export async function nextcloudAccountExists(
	admin: APIRequestContext,
	uid: string,
): Promise<boolean> {
	const res = await admin.get(
		`/ocs/v2.php/cloud/users/${encodeURIComponent(uid)}?format=json`,
	)
	if (res.status() !== 200) {
		return false
	}

	const body = await res.json().catch(() => null)
	return Number(body?.ocs?.meta?.statuscode ?? 0) === 100
}

/**
 * Create a Nextcloud account for the run, through the provisioning API.
 *
 * @param {APIRequestContext} admin An admin context.
 * @param {{user: string, pass: string}} who The account to create.
 * @param {string} displayName The name to show.
 * @return {Promise<void>}
 */
export async function createNextcloudAccount(
	admin: APIRequestContext,
	who: { user: string; pass: string },
	displayName: string,
): Promise<void> {
	const res = await admin.post('/ocs/v2.php/cloud/users?format=json', {
		form: {
			userid: who.user,
			password: who.pass,
			displayName,
		},
	})
	const body = await res.text()
	// OCS answers a created account with its own status code (100 on v1, 200
	// on v2), so the check reads the code rather than the HTTP status alone.
	expect(
		res.status() === 200 && /"statuscode":\s*(100|200)/.test(body) === true,
		`creating ${who.user}: ${res.status()} ${body}`,
	).toBeTruthy()
}

/**
 * Remove a Nextcloud account the run created.
 *
 * @param {APIRequestContext} admin An admin context.
 * @param {string} uid The account.
 * @return {Promise<void>}
 */
export async function removeNextcloudAccount(
	admin: APIRequestContext,
	uid: string,
): Promise<void> {
	const res = await admin
		.delete(`/ocs/v2.php/cloud/users/${encodeURIComponent(uid)}?format=json`)
		.catch(() => null)
	if (!res || res.status() >= 300) {
		console.warn(`could not remove the account ${uid}`)
	}
}

/**
 * Give an existing Nextcloud account a portal account with its app claims.
 *
 * The `subjectRef` IS the Nextcloud user id, because that is what the
 * `nextcloud` sign-in mode looks the account up by
 * (`PortalAccountService::findBySubjectRef`).
 *
 * @param {APIRequestContext} admin An admin context.
 * @param {SeededRow[]} created The cleanup ledger.
 * @param {object} account The account to write.
 * @return {Promise<Record<string, any>>} The account row.
 */
export async function grantPortalAccount(
	admin: APIRequestContext,
	created: SeededRow[],
	account: {
		subjectRef: string
		audience: string
		organisation: string
		email: string
		displayName: string
		claims: Record<string, Record<string, string>>
	},
): Promise<Record<string, any>> {
	return await createRow(admin, created, 'portaliq', 'portalAccount', {
		audience: account.audience,
		subjectRef: account.subjectRef,
		organisation: account.organisation,
		email: account.email,
		verifiedEmail: true,
		displayName: account.displayName,
		status: 'active',
		claims: account.claims,
	})
}

/**
 * Make sure the portal offers a sign-in mode, and hand back how to undo it.
 *
 * @param {APIRequestContext} admin An admin context.
 * @param {string} slug The portal slug.
 * @param {string} mode The mode to offer, e.g. `nextcloud`.
 * @return {Promise<{restore: () => Promise<void>, organisation: string}>} The undo and the portal's tenant.
 */
export async function offerSignInMode(
	admin: APIRequestContext,
	slug: string,
	mode: string,
): Promise<{ restore: () => Promise<void>; organisation: string }> {
	const list = await admin.get(
		'/apps/openregister/api/objects/portaliq/portal?_limit=100',
	)
	expect(list.status(), await list.text()).toBe(200)
	const portals = (await list.json()).results ?? []
	const portal = portals.find(
		(row: Record<string, any>) => String(row.slug ?? '') === slug,
	)
	expect(portal, `the portal ${slug} is not on this instance`).toBeTruthy()

	const authentication = { ...(portal.authentication ?? {}) }
	const before: string[] = Array.isArray(authentication.modes)
		? [...authentication.modes]
		: []
	const organisation = String(portal.organisation ?? '')

	if (before.includes(mode)) {
		return { restore: async () => undefined, organisation }
	}

	const write = async (modes: string[]) => {
		const res = await admin.patch(
			`/apps/openregister/api/objects/portaliq/portal/${portal.id}`,
			{ data: { authentication: { ...authentication, modes } } },
		)
		expect(res.status(), await res.text()).toBeLessThan(300)
	}

	await write([...before, mode])
	return {
		restore: async () => {
			await write(before).catch(() => {
				console.warn(`could not put the sign-in modes of ${slug} back`)
			})
		},
		organisation,
	}
}

/**
 * Sign in to the site with a Nextcloud account: the `nextcloud` mode.
 *
 * Nextcloud's own login form takes the password — the portal never sees it —
 * and the auth edge hands the bearer back in the URL fragment, which the site
 * reads into sessionStorage.
 *
 * @param {Browser} browser The browser.
 * @param {{user: string, pass: string}} who The account.
 * @param {string} portal The portal slug.
 * @param {string} shots Where to write the screenshots.
 * @return {Promise<PortalLogin>} The signed-in page and its bearer.
 */
export async function signInWithNextcloudAccount(
	browser: Browser,
	who: { user: string; pass: string },
	portal: string,
	shots: string,
): Promise<PortalLogin> {
	// A Dutch browser: learniq's labels follow the request's language.
	const page = await (
		await browser.newContext({ storageState: undefined, locale: 'nl-NL' })
	).newPage()
	const site = siteUrl(portal)

	await page.goto(site)
	await page.getByTestId('site-account-signin').waitFor({ timeout: 20_000 })
	await shot(page, shots, 'login-choices')
	// The button for this mode, as the site renders it (authApi.signInRoutes).
	await page
		.getByRole('link', { name: 'Inloggen met uw account', exact: false })
		.first()
		.click()

	// Nextcloud's login form, then back to the site with `#token=`.
	await page.locator('input[name="user"]').waitFor({ timeout: 20_000 })
	await page.locator('input[name="user"]').fill(who.user)
	await page.locator('input[name="password"]').fill(who.pass)
	await page.locator('button[type="submit"]').click()
	await page.waitForURL(/\/apps\/portaliq\/(site|portal)/, { timeout: 30_000 })
	await waitForAccountPage(page)

	const readToken = async () =>
		await page.evaluate((key) => sessionStorage.getItem(key), SITE_TOKEN_KEY)
	await expect.poll(readToken, { timeout: 20_000 }).toBeTruthy()
	const token = (await readToken()) ?? ''

	const session = await page.request.get('/apps/portaliq/portal/api/session', {
		headers: { Authorization: `Bearer ${token}` },
	})
	expect(session.status(), await session.text()).toBe(200)
	const body = await session.json()
	// A password is an eIDAS-'low' sign-in, and these audiences are built for
	// it. A suite asserting anything else would be asserting a wish.
	expect(body.trust).toBe('low')
	expect(String(body.subjectRef ?? '')).toBe(who.user)

	return { page, token }
}

/**
 * The site's URL for a portal.
 *
 * @param {string} portal The portal slug.
 * @return {string} The URL.
 */
export function siteUrl(portal: string): string {
	return `/apps/portaliq/site?portal=${encodeURIComponent(portal)}`
}

/**
 * Open one of the audience's pages by its signed-in route.
 *
 * @param {Page} page The site page.
 * @param {string} portal The portal slug.
 * @param {string} route The route below `/mijn/`.
 * @return {Promise<void>}
 */
export async function openRoute(
	page: Page,
	portal: string,
	route: string,
): Promise<void> {
	await page.goto(
		`${siteUrl(portal)}&route=${encodeURIComponent(`/mijn/${route}`)}`,
	)
	await waitForAccountPage(page)
}

/**
 * Wait until the signed-in area shows its page title.
 *
 * Every signed-in page heads itself with `#site-account-title`: a contributed
 * page with its label, and the `/mijn` home with the greeting, which renders
 * the id but no `data-testid` (po-parent-flows, learniq#1675).
 *
 * @param {Page} page The site page.
 * @return {Promise<void>}
 */
export async function waitForAccountPage(page: Page): Promise<void> {
	await page.locator('#site-account-title').first().waitFor({ timeout: 30_000 })
	await page
		.waitForLoadState('networkidle', { timeout: 10_000 })
		.catch(() => undefined)
}

/**
 * The rows of one portal collection, read with the audience's own bearer.
 *
 * @param {PortalLogin} login The signed-in account.
 * @param {string} schema The learniq schema.
 * @param {string} collection The collection id.
 * @return {Promise<Array<Record<string, any>>>} The rows.
 */
export async function portalRows(
	login: PortalLogin,
	schema: string,
	collection: string,
): Promise<Array<Record<string, any>>> {
	const res = await login.page.request.get(
		`/apps/portaliq/portal/api/collections/learniq/${schema}?collection=${encodeURIComponent(collection)}`,
		{ headers: { Authorization: `Bearer ${login.token}` } },
	)
	expect(res.status(), await res.text()).toBe(200)
	return (await res.json()).objects ?? []
}

/**
 * Save a full-page screenshot.
 *
 * @param {Page} page The page.
 * @param {string} dir The directory.
 * @param {string} name The file name without extension.
 * @return {Promise<void>}
 */
export async function shot(page: Page, dir: string, name: string): Promise<void> {
	await page.screenshot({
		path: path.join(dir, `${name}.png`),
		fullPage: page.url().includes('/apps/portaliq/'),
		timeout: 20_000,
	})
}
