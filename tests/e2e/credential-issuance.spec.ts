/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * credentials-europass-edci-export, Task 1: one live issue. A teacher
 * completes an enrolment on a course with a certificate template, and
 * CredentialIssuanceHandler issues a signed certificate with its Europass
 * form. The learner reads it and downloads the Europass file.
 *
 * Actors: a temporary instructor (completes the enrolment through its
 * lifecycle action) and a temporary learner with a learner profile. Admin
 * creates the course and the active enrolment.
 *
 * The learner's `issuedToLearner` notification is dispatched by OpenRegister
 * through a queued AnnotationNotificationDispatchJob, which cron delivers; the
 * shared instance runs no cron, so the spec checks the job is queued.
 *
 * @e2e openspec/specs/certification/spec.md#requirement-an-issued-certificate-carries-a-signed-europass-form
 * @e2e openspec/specs/certification/spec.md#requirement-a-learner-downloads-their-certificate-for-europass
 */
import { readFileSync } from 'fs'
import { expect, test } from './fixtures.ts'
import { listJobs, LiveFixtures, signInAs } from './live-fixtures.ts'

const APP = '/index.php/apps/learniq'

test.describe('certificate issuance', () => {
	test.describe.configure({ mode: 'serial', timeout: 360_000 })

	const fx = new LiveFixtures()

	test.afterAll(async () => {
		await fx.teardown()
	})

	test('a teacher completes an enrolment and the learner gets a signed certificate with its Europass form', async ({
		browser,
	}) => {
		await fx.ensureSigningKey()
		const teacher = await fx.user('instructor', ['instructors'])
		const learner = await fx.user('learner', ['learners'])
		const profileId = await fx.object('learner-profile', {
			ncUserId: learner.id,
		})
		const code = `R5BHV${fx.run}`.toUpperCase().slice(0, 20)
		const courseId = await fx.object('course', {
			code,
			name: `r5 BHV basisopleiding ${fx.run}`,
			level: 'corporate',
			language: 'nl',
			lifecycle: 'published',
			certificateTemplate: '/Templates/r5-certificate.pdf',
		})
		const enrolmentId = await fx.object('enrolment', {
			learnerId: learner.id,
			courseId,
			source: 'hr',
			lifecycle: 'active',
		})

		// The teacher completes the enrolment from its lifecycle actions.
		const page = await signInAs(browser, teacher)
		try {
			await page.goto(`${APP}/enrolments/${enrolmentId}`, {
				waitUntil: 'domcontentloaded',
			})
			await expect(page.locator('[data-testid="cn-detail-page"]')).toBeVisible(
				{
					timeout: 60_000,
				},
			)
			await page
				.getByRole('button', { name: 'Complete', exact: true })
				.click({ timeout: 60_000 })
			const confirm = page
				.getByRole('dialog')
				.getByRole('button', { name: /^(Complete|Confirm)$/ })
			if (await confirm.isVisible({ timeout: 5_000 }).catch(() => false)) {
				await confirm.click()
			}
			await expect
				.poll(
					async () => (await fx.read('enrolment', enrolmentId)).lifecycle,
					{
						timeout: 30_000,
					},
				)
				.toBe('completed')
		} finally {
			await page.context().close()
		}

		// CredentialIssuanceHandler issued one signed certificate.
		let credentials: Array<Record<string, any>> = []
		await expect
			.poll(
				async () => {
					credentials = await fx.find('credential', { enrolmentId })
					return credentials.length
				},
				{ timeout: 60_000 },
			)
			.toBe(1)
		const credentialId = String(credentials[0].id)
		fx.adopt('credential', credentialId)
		const credential = await fx.read('credential', credentialId)
		expect(credential.learnerId).toBe(profileId)
		expect(credential.learnerUserId).toBe(learner.id)
		expect(credential.courseId).toBe(courseId)
		expect(credential.kind).toBe('certificate')
		expect(credential.lifecycle).toBe('issued')
		for (const field of ['signature', 'issuerDid', 'issuedAt']) {
			expect(String(credential[field] ?? ''), field).not.toBe('')
		}
		expect(typeof credential.openbadges3Payload, 'openbadges3Payload').toBe(
			'object',
		)
		expect(JSON.stringify(credential.edciPayload ?? null)).toContain('proof')

		// The learner's notification is queued for dispatch.
		expect(
			listJobs(
				'OCA\\OpenRegister\\BackgroundJob\\AnnotationNotificationDispatchJob',
			).some((job) =>
				(job.argument.entries ?? []).some(
					(entry: any) =>
						entry.uuid === credentialId && entry.trigger === 'created',
				),
			),
			'no queued dispatch for the new credential',
		).toBe(true)

		// The learner reads it and downloads the Europass file.
		const learnerPage = await signInAs(browser, learner)
		try {
			await learnerPage.goto(`${APP}/credentials/${credentialId}`, {
				waitUntil: 'domcontentloaded',
			})
			await learnerPage
				.getByRole('button', { name: 'Actions' })
				.first()
				.click({ timeout: 60_000 })
			const [download] = await Promise.all([
				learnerPage.waitForEvent('download', { timeout: 60_000 }),
				learnerPage
					.getByRole('menuitem', { name: 'Download for Europass' })
					.click({ timeout: 30_000 }),
			])
			const day = String(credential.issuedAt).slice(0, 10)
			expect(download.suggestedFilename()).toBe(
				`europass-${code}-${day}.jsonld`,
			)
			const payload = JSON.parse(readFileSync(await download.path(), 'utf8'))
			expect(payload).toEqual(credential.edciPayload)
			expect(payload.proof).toBeTruthy()
		} finally {
			await learnerPage.context().close()
		}
	})
})
