/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The readable verification page on a certificate's link, signed out.
 *
 * A signed certificate is seeded the way the school makes one: a teacher
 * completes an enrolment and CredentialIssuanceHandler signs the certificate
 * (as in credential-issuance.spec.ts). A visitor with an EMPTY storage state,
 * the employer who got the link, then opens the link: the page names the
 * verdict, lists the four checks with a sentence each and gives the school's
 * phone number from its settings. An unknown id shows the not-found page
 * with no certificate title and no holder name.
 *
 * Base URL from the environment (tests/e2e/base-url.ts); users, objects and
 * the phone setting this run writes are removed afterwards.
 *
 * Covers, from openspec/changes/certificate-verification-and-key-rotation:
 *   task 1.3 (the four checks, the school's phone number, the not-found page)
 *
 * @spec openspec/changes/certificate-verification-and-key-rotation/specs/certification/spec.md
 */
import type { Browser, Page } from '@playwright/test'

import { execFileSync } from 'child_process'
import { randomUUID } from 'crypto'
import { expect, test } from '../fixtures.ts'
import { deleteLearniqConfig, learniqConfig, LiveFixtures, signInAs } from '../live-fixtures.ts'

const APP = '/index.php/apps/learniq'

const PHONE = '+31 30 123 45 67'

/**
 * Set one learniq app-config value through occ, as the live fixtures read it.
 *
 * @param key   The key.
 * @param value The value.
 */
function setLearniqConfig(key: string, value: string): void {
	const [command, ...prefix] = (process.env.LEARNIQ_E2E_OCC ?? 'docker exec -u www-data nextcloud php occ')
		.split(/\s+/)
		.filter((part) => part !== '')
	execFileSync(command, [...prefix, 'config:app:set', 'learniq', key, '--value', value], {
		encoding: 'utf8',
		timeout: 60_000,
		stdio: 'pipe',
	})
}

/**
 * Open a verification link in a signed-out browser.
 *
 * @param browser The browser.
 * @param id      The certificate id.
 * @return The page.
 */
async function openSignedOut(browser: Browser, id: string): Promise<Page> {
	const context = await browser.newContext({ storageState: { cookies: [], origins: [] } })
	const page = await context.newPage()
	await page.goto(`${APP}/verify/${id}`, { waitUntil: 'domcontentloaded', timeout: 45_000 })
	await expect(page.getByTestId('credential-verify-page')).toBeVisible({ timeout: 30_000 })
	await expect(page.getByTestId('credential-verify-verdict')).toBeVisible({ timeout: 30_000 })
	return page
}

test.describe('the certificate verification page, signed out', () => {
	test.describe.configure({ mode: 'serial', timeout: 360_000 })

	const fx = new LiveFixtures()
	let previousPhone = ''
	let credentialId = ''

	test.beforeAll(async () => {
		previousPhone = learniqConfig('schoolPhone')
		setLearniqConfig('schoolPhone', PHONE)
	})

	test.afterAll(async () => {
		if (previousPhone === '') {
			deleteLearniqConfig(['schoolPhone'])
		} else {
			setLearniqConfig('schoolPhone', previousPhone)
		}
		await fx.teardown()
	})

	test('a signed certificate reads real and valid, with the four checks and the school\'s phone', async ({ browser }) => {
		await fx.ensureSigningKey()
		const teacher = await fx.user('instructor', ['instructors'])
		const learner = await fx.user('learner', ['learners'])
		await fx.object('learner-profile', { ncUserId: learner.id })
		const courseId = await fx.object('course', {
			code: `VERIFY${fx.run}`.toUpperCase().slice(0, 20),
			name: `Verify page ${fx.run}`,
			level: 'corporate',
			language: 'nl',
			lifecycle: 'published',
		})
		const enrolmentId = await fx.object('enrolment', {
			learnerId: learner.id,
			courseId,
			source: 'hr',
			lifecycle: 'active',
		})

		const page = await signInAs(browser, teacher)
		try {
			await page.goto(`${APP}/enrolments/${enrolmentId}`, { waitUntil: 'domcontentloaded' })
			await expect(page.locator('[data-testid="cn-detail-page"]')).toBeVisible({ timeout: 60_000 })
			await page.getByRole('button', { name: 'Complete', exact: true }).click({ timeout: 60_000 })
			const confirm = page.getByRole('dialog').getByRole('button', { name: /^(Complete|Confirm)$/ })
			if (await confirm.isVisible({ timeout: 5_000 }).catch(() => false)) {
				await confirm.click()
			}
		} finally {
			await page.context().close()
		}

		let credentials: Array<Record<string, unknown>> = []
		await expect
			.poll(async () => {
				credentials = await fx.find('credential', { enrolmentId })
				return credentials.length
			}, { timeout: 60_000 })
			.toBe(1)
		credentialId = String(credentials[0].id)
		fx.adopt('credential', credentialId)

		const visitor = await openSignedOut(browser, credentialId)
		try {
			await expect(visitor.getByTestId('credential-verify-verdict')).toContainText('Real and valid')
			for (const [id, name, sentence] of [
				['signature', 'Signature', 'The certificate is signed with the school\'s key.'],
				['notRevoked', 'Not revoked', 'The school has not withdrawn the certificate.'],
				['notExpired', 'Not expired', 'The certificate is within its validity period.'],
				['unchanged', 'Not changed', 'The content is the same as when the school issued it.'],
			]) {
				const check = visitor.getByTestId(`credential-verify-check-${id}`)
				await expect(check, `check ${id}`).toContainText(name)
				await expect(check).toContainText('Passed')
				await expect(check).toContainText(sentence)
			}
			await expect(visitor.getByTestId('credential-verify-phone')).toContainText(PHONE)
		} finally {
			await visitor.context().close()
		}
	})

	test('an unknown id shows the not-found page, with no title and no holder name', async ({ browser }) => {
		const visitor = await openSignedOut(browser, randomUUID())
		try {
			await expect(visitor.getByTestId('credential-verify-verdict')).toContainText('No certificate found')
			await expect(visitor.getByTestId('credential-verify-verdict')).toContainText(
				'There is no certificate with this link. Check that the link is complete.',
			)
			const details = visitor.getByTestId('credential-verify-details')
			if (await details.count() > 0) {
				await expect(details).not.toContainText('Certificate')
				await expect(details).not.toContainText('Issued to')
			}
		} finally {
			await visitor.context().close()
		}
	})
})
