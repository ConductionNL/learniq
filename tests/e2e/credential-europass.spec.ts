/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * credentials-europass-edci-export, Task 6: an HR officer creates the
 * Europass version of an earlier certificate, and the learner it belongs to
 * downloads it from the certificate page.
 *
 * Actors: a temporary user in `hr` (CredentialEuropassController::create
 * accepts hr and compliance officers) and a temporary learner. The learner has
 * a learner profile, and the certificate's `learnerId` is that profile's uuid
 * (Credential.learnerId is a LearnerProfile reference); learniq resolves it to
 * the learner's Nextcloud account wherever it compares the two.
 *
 * Admin creates the course and a certificate issued last spring with no
 * `edciPayload`, the state of a certificate issued before this change.
 *
 * @e2e openspec/changes/credentials-europass-edci-export/specs/certification/spec.md#requirement-staff-create-the-europass-form-for-an-earlier-certificate
 * @e2e openspec/changes/credentials-europass-edci-export/specs/certification/spec.md#requirement-a-learner-downloads-their-certificate-for-europass
 */
import type { Page } from '@playwright/test'

import { readFileSync } from 'fs'
import { expect, test } from './fixtures.ts'
import { LiveFixtures, signInAs } from './live-fixtures.ts'

const APP = '/index.php/apps/learniq'
const ISSUED_AT = '2026-03-03T09:00:00+00:00'

/**
 * Open the certificate page's Actions menu and return one of its items.
 *
 * @param page The page, on the certificate.
 * @param name The menu item's label.
 * @return The menu item.
 */
async function headerAction(page: Page, name: string) {
	await page
		.getByRole('button', { name: 'Actions' })
		.first()
		.click({ timeout: 60_000 })
	return page.getByRole('menuitem', { name })
}

test.describe('certificate for Europass', () => {
	test.describe.configure({ mode: 'serial', timeout: 300_000 })

	const fx = new LiveFixtures()
	const code = `R5MINOR${fx.run}`.toUpperCase().slice(0, 20)
	let learner = { id: '', password: '' }
	let credentialId = ''

	test.afterAll(async () => {
		await fx.teardown()
	})

	test('an HR officer creates the Europass version of an earlier certificate', async ({
		browser,
	}) => {
		await fx.ensureSigningKey()
		const officer = await fx.user('hr', ['hr'])
		learner = await fx.user('learner', ['learners'])
		const profileId = await fx.object('learner-profile', {
			ncUserId: learner.id,
		})
		const courseId = await fx.object('course', {
			code,
			name: `r5 Minor duurzame bedrijfsvoering ${fx.run}`,
			level: 'corporate',
			language: 'nl',
			lifecycle: 'published',
		})
		credentialId = await fx.object('credential', {
			courseId,
			learnerId: profileId,
			kind: 'certificate',
			issuedAt: ISSUED_AT,
			issuerDid: 'did:web:r5-live-2.invalid',
			signature: `r5-${fx.run}`,
			openbadges3Payload: { name: `r5 minor ${fx.run}` },
			lifecycle: 'issued',
		})
		expect(
			(await fx.read('credential', credentialId)).edciPayload ?? null,
		).toBeNull()

		const page = await signInAs(browser, officer)
		try {
			await page.goto(`${APP}/credentials/${credentialId}`, {
				waitUntil: 'domcontentloaded',
			})
			await (
				await headerAction(page, 'Create Europass version')
			).click({
				timeout: 30_000,
			})
			const confirm = page
				.getByRole('dialog')
				.getByRole('button', {
					name: /Confirm|Create Europass version|OK|Yes/,
				})
			if (await confirm.isVisible({ timeout: 10_000 }).catch(() => false)) {
				await confirm.click()
			}
			await expect(
				page.getByText('The Europass version was created.'),
			).toBeVisible({
				timeout: 30_000,
			})
			// The certificate now offers the download.
			await page.keyboard.press('Escape')
			await expect(
				await headerAction(page, 'Download for Europass'),
			).toBeVisible({
				timeout: 30_000,
			})
		} finally {
			await page.context().close()
		}

		const after = await fx.read('credential', credentialId)
		expect(typeof after.edciPayload).toBe('object')
		expect(after.edciPayload).not.toBeNull()
		expect(after.issuedAt).toBe(ISSUED_AT)
	})

	test('the learner downloads their certificate for Europass', async ({
		browser,
	}) => {
		test.skip(credentialId === '', 'the backfill test did not run')
		const page = await signInAs(browser, learner)
		try {
			await page.goto(`${APP}/credentials/${credentialId}`, {
				waitUntil: 'domcontentloaded',
			})
			const item = await headerAction(page, 'Download for Europass')
			const [download] = await Promise.all([
				page.waitForEvent('download', { timeout: 60_000 }),
				item.click({ timeout: 30_000 }),
			])
			// Named after the course and the issue date.
			expect(download.suggestedFilename()).toBe(
				`europass-${code}-2026-03-03.jsonld`,
			)
			const file = await download.path()
			const payload = JSON.parse(readFileSync(file, 'utf8'))
			const stored = (await fx.read('credential', credentialId)).edciPayload
			// The file is the signed European Digital Credential of this certificate.
			expect(payload).toEqual(stored)
			expect(JSON.stringify(payload)).toContain('proof')
		} finally {
			await page.context().close()
		}
	})
})
