/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Temporary people and objects for the multi-actor specs.
 *
 * Several flows need a second person to sign in: a learner checks in, a
 * second marker marks, a learner joins a group. This helper creates those
 * people through the provisioning API, puts them ONLY in learniq's own groups,
 * and records every user and object it creates in a ledger. `teardown()`
 * deletes exactly what the ledger holds, newest first, so a run never removes
 * a row it did not create (see shared-instance.ts for why that matters).
 *
 * Call `teardown()` from `test.afterAll`, which Playwright runs when a test
 * fails as well.
 *
 * Users are named `lq-r5-e2e-<role>-<run>`, so a leftover is recognisable and
 * a sweep can find it. Admin calls use basic auth on their own request
 * context: no session cookie, so no CSRF token and no dependency on the
 * browser's login state.
 */
import type { APIRequestContext, Browser, Page } from '@playwright/test'

import { request as playwrightRequest } from '@playwright/test'
import { randomBytes } from 'crypto'
import { baseUrl } from './base-url.ts'

/** The groups learniq declares; the only ones a temporary user may join. */
const LEARNIQ_GROUPS = new Set([
	'learners',
	'instructors',
	'guardians',
	'coordinators',
	'team-leads',
	'hr',
	'compliance-officers',
])

export interface TempUser {
	id: string
	password: string
}

/**
 * Pull an object's uuid out of an OpenRegister create response.
 *
 * @param body The parsed response.
 * @return The uuid, or null.
 */
function objectIdOf(body: Record<string, any> | null): string | null {
	const id = body?.['@self']?.uuid ?? body?.uuid ?? body?.id
	return typeof id === 'string' ? id : null
}

export class LiveFixtures {
	readonly run = Date.now().toString(36)

	private admin: APIRequestContext | null = null

	private users: string[] = []

	private objects: Array<{ slug: string; id: string }> = []

	/**
	 * The admin request context, created on first use.
	 *
	 * @return The context.
	 */
	async api(): Promise<APIRequestContext> {
		if (this.admin === null) {
			this.admin = await playwrightRequest.newContext({
				baseURL: baseUrl(),
				httpCredentials: {
					username: process.env.NC_ADMIN_USER ?? 'admin',
					password: process.env.NC_ADMIN_PASS ?? 'admin',
					send: 'always',
				},
				extraHTTPHeaders: {
					'OCS-APIRequest': 'true',
					Accept: 'application/json',
				},
			})
		}
		return this.admin
	}

	/**
	 * Create a temporary user in learniq's own groups.
	 *
	 * @param role A short role word for the name, e.g. `learner`.
	 * @param groups Learniq group ids; any other group is refused here.
	 * @return The user id and password.
	 */
	async user(role: string, groups: string[]): Promise<TempUser> {
		for (const group of groups) {
			if (!LEARNIQ_GROUPS.has(group)) {
				throw new Error(
					`refusing to add a temporary user to non-learniq group ${group}`,
				)
			}
		}
		const id = `lq-r5-e2e-${role}-${this.run}`
		const password = `Lq-${randomBytes(12).toString('hex')}-9!`
		const api = await this.api()
		const res = await api.post('/ocs/v2.php/cloud/users?format=json', {
			form: { userid: id, password },
		})
		const body = await res.json().catch(() => null)
		if (!res.ok() || body?.ocs?.meta?.statuscode !== 200) {
			throw new Error(
				`creating ${id} was refused: HTTP ${res.status()} ${JSON.stringify(body?.ocs?.meta ?? body)}`,
			)
		}
		this.users.push(id)
		for (const group of groups) {
			const add = await api.post(
				`/ocs/v2.php/cloud/users/${id}/groups?format=json`,
				{ form: { groupid: group } },
			)
			const addBody = await add.json().catch(() => null)
			if (!add.ok() || addBody?.ocs?.meta?.statuscode !== 200) {
				throw new Error(
					`adding ${id} to ${group} was refused: HTTP ${add.status()} ${JSON.stringify(addBody?.ocs?.meta ?? addBody)}`,
				)
			}
		}
		return { id, password }
	}

	/**
	 * Create a learniq object as admin; throws with the server's answer when
	 * the create is refused, instead of handing back null.
	 *
	 * @param slug The schema slug.
	 * @param body The object.
	 * @return The object's uuid.
	 */
	async object(slug: string, body: Record<string, unknown>): Promise<string> {
		const api = await this.api()
		const res = await api.post(
			`/index.php/apps/openregister/api/objects/learniq/${slug}`,
			{ data: { tenant_id: await this.tenant(), ...body } },
		)
		const created = await res.json().catch(() => null)
		const id = objectIdOf(created)
		if (!res.ok() || id === null) {
			throw new Error(
				`creating a ${slug} was refused: HTTP ${res.status()} ${JSON.stringify(created).slice(0, 600)}`,
			)
		}
		this.objects.push({ slug, id })
		return id
	}

	/**
	 * Read one learniq object as admin.
	 *
	 * @param slug The schema slug.
	 * @param id The uuid.
	 * @return The object.
	 */
	async read(slug: string, id: string): Promise<Record<string, any>> {
		const api = await this.api()
		const res = await api.get(
			`/index.php/apps/openregister/api/objects/learniq/${slug}/${id}`,
		)
		if (!res.ok()) {
			throw new Error(`reading ${slug}/${id}: HTTP ${res.status()}`)
		}
		return res.json()
	}

	/**
	 * Find learniq objects as admin.
	 *
	 * @param slug The schema slug.
	 * @param query Bare filter keys.
	 * @return The rows.
	 */
	async find(
		slug: string,
		query: Record<string, string>,
	): Promise<Array<Record<string, any>>> {
		const api = await this.api()
		const qs = new URLSearchParams({ _limit: '100', ...query }).toString()
		const res = await api.get(
			`/index.php/apps/openregister/api/objects/learniq/${slug}?${qs}`,
		)
		if (!res.ok()) {
			throw new Error(`listing ${slug}: HTTP ${res.status()}`)
		}
		return (await res.json()).results ?? []
	}

	/**
	 * Record an object someone else created (the app, a user in the browser),
	 * so teardown removes it too.
	 *
	 * @param slug The schema slug.
	 * @param id The uuid.
	 */
	adopt(slug: string, id: string): void {
		if (!this.objects.some((o) => o.id === id)) {
			this.objects.push({ slug, id })
		}
	}

	/**
	 * The tenant the instance's learniq objects carry.
	 *
	 * @return The tenant uuid.
	 */
	async tenant(): Promise<string> {
		const api = await this.api()
		const res = await api.get(
			'/index.php/apps/openregister/api/objects/learniq/session?_limit=1',
		)
		const row = res.ok() ? ((await res.json()).results ?? [])[0] : null
		return String(row?.tenant_id ?? '00000000-0000-0000-0000-000000000001')
	}

	/**
	 * Delete everything in the ledger, newest first. Never throws: a
	 * teardown that throws hides the test's own failure.
	 *
	 * @return What could not be deleted, for the report.
	 */
	async teardown(): Promise<string[]> {
		const left: string[] = []
		const api = await this.api()
		for (const { slug, id } of [...this.objects].reverse()) {
			const res = await api
				.delete(
					`/index.php/apps/openregister/api/objects/learniq/${slug}/${id}`,
				)
				.catch(() => null)
			if (res === null || (!res.ok() && res.status() !== 404)) {
				left.push(`${slug}/${id}`)
			}
		}
		for (const id of [...this.users].reverse()) {
			const res = await api
				.delete(`/ocs/v2.php/cloud/users/${id}?format=json`)
				.catch(() => null)
			if (res === null || !res.ok()) {
				left.push(`user ${id}`)
			}
		}
		this.objects = []
		this.users = []
		await this.admin?.dispose()
		this.admin = null
		if (left.length > 0) {
			console.warn('[live-fixtures] left behind:', left.join(', '))
		}
		return left
	}
}

/**
 * Open a fresh browser context signed in as a temporary user, with the
 * walkthrough and setup wizard suppressed the way global-setup does for admin.
 *
 * @param browser The browser.
 * @param user The user.
 * @return The page; close its context when done.
 */
export async function signInAs(browser: Browser, user: TempUser): Promise<Page> {
	const context = await browser.newContext({
		baseURL: baseUrl(),
		storageState: { cookies: [], origins: [] },
	})
	const page = await context.newPage()
	// A brand-new account gets Nextcloud's first-run welcome modal, and it
	// opens late, on whatever page is under test by then. Dismissing it through
	// firstrunwizard's DELETE /wizard answered 200 and did not stop it opening
	// (measured 2026-09-29), so close it whenever it stands in the way.
	await page.addLocatorHandler(
		page.locator('div[role="dialog"]#firstrunwizard'),
		async (dialog) => {
			await dialog.getByRole('button', { name: /close/i }).first().click()
		},
	)
	// nextcloud-vue's "Support <app>" dialog also greets a new account.
	await page.addLocatorHandler(
		page.locator('[data-testid-modal="cn-support-dialog"]'),
		async (dialog) => {
			await dialog.getByRole('button', { name: /close/i }).first().click()
		},
	)
	await page.goto('/index.php/login', { waitUntil: 'domcontentloaded' })
	await page.locator('input[name="user"]').fill(user.id)
	await page.locator('input[name="password"]').fill(user.password)
	await page
		.locator('#submit, button[type="submit"], input[type="submit"]')
		.first()
		.click()
	await page.waitForURL((url) => !url.pathname.includes('/login'), {
		timeout: 60_000,
	})
	await page.evaluate(() => {
		try {
			window.localStorage.setItem('cn-walkthrough-seen:learniq', '999.0.0')
			// nextcloud-vue's first-open "Support <app>" note (useSupportDialog):
			// a local flag is authoritative and skips the server check.
			window.localStorage.setItem('cn-support-dialog-shown:learniq', '1')
			for (let v = 0; v <= 20; v++) {
				window.localStorage.setItem(
					`cn-setup-wizard-dismissed:learniq:${v}`,
					'1',
				)
			}
		} catch {
			// no storage; the spec dismisses by hand
		}
	})
	return page
}
