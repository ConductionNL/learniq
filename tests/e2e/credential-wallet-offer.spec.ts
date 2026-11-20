// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * The learner sees the wallet offer of her own certificate
 * (credential-wallet-offer-for-the-learner, task 4.2).
 *
 * @e2e openspec/changes/credential-wallet-offer-for-the-learner/specs/certification/spec.md#scenario-the-learner-scans-the-offer
 * @e2e openspec/changes/credential-wallet-offer-for-the-learner/specs/certification/spec.md#scenario-staff-do-not-get-the-qr-code
 *
 * Staff run "offer to wallet" (`offerToWallet`, a self-loop on an issued
 * credential) against integriq: on an instance with integriq enabled the
 * transition must leave a `walletOfferUri` on the credential. On an instance
 * without integriq the staff step is skipped and the offer integriq would
 * have written is put on the run's own credential instead, so the learner's
 * half still runs. Then the learner signs in and sees, on her certificate,
 * the QR code and the "To wallet" link over that offer; another user sees
 * no wallet block. The run's credential and learner account are removed
 * afterwards.
 */

import type { APIRequestContext, Browser, Page } from '@playwright/test'
import type { SeededRow } from './helpers/portal-fixture.ts'

import { expect, test } from '@playwright/test'
import {
	asUser,
	createNextcloudAccount,
	createRow,
	removeNextcloudAccount,
	removeRows,
} from './helpers/portal-fixture.ts'

const TENANT =
	process.env.AUDIENCE_FLOW_TENANT ?? '00000000-0000-4000-8000-000000000000'
const ADMIN = {
	user: process.env.NC_ADMIN_USER ?? 'admin',
	pass: process.env.NC_ADMIN_PASS ?? 'admin',
}
const RUN = Date.now().toString(36)
const LEARNER = {
	user: `lq-e2e-wallet-${RUN}`,
	pass: `Lq-e2e-${RUN}-wallet!`,
	name: 'Wallet Leerling',
}
const OR = '/index.php/apps/openregister/api/objects'
/** The shape of link integriq hands back (`openid-credential-offer://`). */
const SEEDED_OFFER = `openid-credential-offer://?credential_offer_uri=https%3A%2F%2Fwallet.example.org%2Foffers%2F${RUN}`

/**
 * A page signed in to Nextcloud as the given account, with no shared state.
 *
 * @param {Browser} browser The browser.
 * @param {{user: string, pass: string}} who The account.
 * @return {Promise<Page>} The page.
 */
async function signIn(
	browser: Browser,
	who: { user: string; pass: string },
): Promise<Page> {
	const page = await (
		await browser.newContext({
			storageState: undefined,
			viewport: { width: 1400, height: 1000 },
		})
	).newPage()
	await page.goto('/index.php/login')
	await page.locator('input[name="user"]').fill(who.user)
	await page.locator('input[name="password"]').fill(who.pass)
	await page.locator('button[type="submit"]').click()
	await page.waitForURL((url) => !url.pathname.includes('/login'))
	return page
}

/**
 * Whether integriq is enabled on the instance.
 *
 * @param {APIRequestContext} admin An admin context.
 * @return {Promise<boolean>}
 */
async function integriqEnabled(admin: APIRequestContext): Promise<boolean> {
	const res = await admin.get('/ocs/v2.php/cloud/apps?filter=enabled&format=json')
	if (!res.ok()) {
		return false
	}
	const apps: string[] = (await res.json())?.ocs?.data?.apps ?? []
	return apps.includes('integriq')
}

test.describe.serial('credential-wallet-offer: the holder sees her offer', () => {
	const created: SeededRow[] = []
	let admin: APIRequestContext
	let credentialId = ''
	let accountCreated = false
	let withIntegriq = false

	test.beforeAll(async () => {
		admin = await asUser(ADMIN)
		await createNextcloudAccount(admin, LEARNER, LEARNER.name)
		accountCreated = true
		withIntegriq = await integriqEnabled(admin)

		const credential = await createRow(admin, created, 'learniq', 'credential', {
			learnerId: LEARNER.user,
			learnerUserId: LEARNER.user,
			kind: 'certificate',
			issuedAt: new Date().toISOString(),
			lifecycle: 'issued',
			tenant_id: TENANT,
			...(withIntegriq ? {} : { walletOfferUri: SEEDED_OFFER, walletOfferedAt: new Date().toISOString() }),
		})
		credentialId = String(credential.id ?? credential.uuid)
	})

	test.afterAll(async () => {
		if (admin === undefined) {
			return
		}
		await removeRows(admin, created, 'credential-wallet-offer')
		if (accountCreated === true) {
			await removeNextcloudAccount(admin, LEARNER.user)
		}
		await admin.dispose()
	})

	test('staff offer the certificate to the wallet through integriq', async () => {
		test.skip(
			withIntegriq === false,
			'integriq is not enabled on this instance; the offer integriq would write is seeded instead',
		)
		const offered = await admin.post(`${OR}/${credentialId}/transition`, {
			data: { action: 'offerToWallet' },
		})
		expect(offered.status(), await offered.text()).toBeLessThan(300)

		const stored = await admin.get(`${OR}/learniq/credential/${credentialId}`)
		expect(stored.status(), await stored.text()).toBe(200)
		const row = await stored.json()
		expect(String(row.walletOfferUri ?? '')).toMatch(/^openid-credential-offer:\/\//)
		expect(row.lifecycle).toBe('issued')
	})

	// @e2e openspec/changes/credential-wallet-offer-for-the-learner/specs/certification/spec.md#scenario-the-learner-scans-the-offer
	test('the learner sees the QR code and the wallet link on her certificate', async ({
		browser,
	}) => {
		const stored = await (await admin.get(`${OR}/learniq/credential/${credentialId}`)).json()
		const uri = String(stored.walletOfferUri ?? '')
		expect(uri, 'an open offer on the credential').not.toBe('')

		const page = await signIn(browser, LEARNER)
		try {
			await page.goto(`/index.php/apps/learniq/credentials/${credentialId}`)
			const block = page.getByTestId('credential-wallet-offer')
			await expect(block).toBeVisible({ timeout: 30_000 })
			// The QR code over the offer, and the link that opens the wallet app.
			await expect(block.locator('svg, canvas, img').first()).toBeVisible()
			const link = block.locator('a.wallet-offer__link')
			await expect(link).toBeVisible()
			await expect(link).toHaveAttribute('href', uri)
		} finally {
			await page.context().close()
		}
	})

	// @e2e openspec/changes/credential-wallet-offer-for-the-learner/specs/certification/spec.md#scenario-staff-do-not-get-the-qr-code
	test('another user sees no wallet block on that certificate', async ({ browser }) => {
		// The admin is not the holder: the block renders for the holder only.
		const page = await signIn(browser, ADMIN)
		try {
			await page.goto(`/index.php/apps/learniq/credentials/${credentialId}`)
			await expect(page.locator('main, #content').first()).toBeVisible({ timeout: 30_000 })
			await expect(page.getByTestId('credential-wallet-offer')).toHaveCount(0)
		} finally {
			await page.context().close()
		}
	})
})
