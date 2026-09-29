/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * credentials-bulk-reissue, Task 3: a compliance officer reissues every
 * certificate of a course from the course page, and the certificate keeps its
 * issue date while its history shows who reissued it and why.
 *
 * Actors: a temporary user in learniq's `compliance-officers` group (the
 * reissue route and the Credential update rule both name it). Admin creates
 * the course and its four certificates: two issued, one revoked, one expired.
 *
 * The run is a queued job and the shared instance runs no cron, so the spec
 * executes that one job through occ (runLearniqJobs), the way cron would.
 *
 * Credentials are append-only: teardown cannot delete them and says so.
 *
 * @e2e openspec/changes/credentials-bulk-reissue/specs/certification/spec.md#requirement-staff-reissue-every-certificate-of-a-course-in-one-action
 * @e2e openspec/changes/credentials-bulk-reissue/specs/certification/spec.md#requirement-a-reissue-keeps-who-and-when-and-records-why
 */
import { randomUUID } from 'crypto'
import { expect, test } from './fixtures.ts'
import { LiveFixtures, runLearniqJobs, signInAs } from './live-fixtures.ts'

const APP = '/index.php/apps/learniq'
const JOB = 'OCA\\Learniq\\BackgroundJob\\CredentialReissueJob'
const ISSUED_AT = '2026-03-03T09:00:00+00:00'

test.describe('certificate reissue', () => {
	test.describe.configure({ mode: 'serial', timeout: 300_000 })

	const fx = new LiveFixtures()
	const reason = `Nieuwe tekst certificaat na wijziging NIBHV-eisen ${fx.run}`
	let courseId = ''
	let officer = { id: '', password: '' }
	const issued: string[] = []
	let revokedId = ''
	let expiredId = ''

	test.afterAll(async () => {
		await fx.teardown()
	})

	/**
	 * Create one certificate of the course as admin.
	 *
	 * @param lifecycle issued, revoked or expired.
	 * @return The credential uuid.
	 */
	async function certificate(lifecycle: string): Promise<string> {
		return fx.object('credential', {
			courseId,
			learnerId: randomUUID(),
			kind: 'certificate',
			issuedAt: ISSUED_AT,
			issuerDid: 'did:web:r5-live-2.invalid',
			signature: `r5-before-${fx.run}`,
			openbadges3Payload: { name: `r5 before reissue ${fx.run}` },
			lifecycle,
		})
	}

	test('a compliance officer reissues a course; revoked and expired stay as they are', async ({
		browser,
	}) => {
		await fx.ensureSigningKey()
		officer = await fx.user('compliance', ['compliance-officers'])
		courseId = await fx.object('course', {
			code: `R5BHV${fx.run}`.toUpperCase().slice(0, 20),
			name: `r5 BHV basisopleiding ${fx.run}`,
			level: 'corporate',
			language: 'nl',
			lifecycle: 'published',
		})
		issued.push(await certificate('issued'), await certificate('issued'))
		revokedId = await certificate('revoked')
		expiredId = await certificate('expired')
		const revokedBefore = await fx.read('credential', revokedId)
		const expiredBefore = await fx.read('credential', expiredId)

		const page = await signInAs(browser, officer)
		try {
			await page.goto(`${APP}/courses/${courseId}`, {
				waitUntil: 'domcontentloaded',
			})
			// Header actions past the first few live in the page's Actions menu.
			await page
				.getByRole('button', { name: 'Actions' })
				.first()
				.click({ timeout: 60_000 })
			await page
				.getByRole('menuitem', { name: 'Reissue certificates' })
				.click({ timeout: 30_000 })
			await expect(page).toHaveURL(new RegExp(`/courses/${courseId}/reissue`))

			const view = page.locator('.reissue-certificates')
			await expect(view).toContainText(
				'2 issued certificates will be rebuilt and signed again.',
				{ timeout: 60_000 },
			)
			await expect(view).toContainText(
				'1 revoked and 1 expired certificates are left as they are.',
			)
			await view
				.getByRole('textbox', { name: /Reason for the reissue/ })
				.fill(reason)
			await view
				.getByRole('button', { name: 'Reissue certificates', exact: true })
				.click()
			await expect(
				view.locator('.notecard--success, [class*="success"]').first(),
			).toContainText('The reissue has started', { timeout: 30_000 })
		} finally {
			await page.context().close()
		}

		const ran = runLearniqJobs(JOB, (argument) => argument.courseId === courseId)
		expect(ran, 'no queued reissue job for this course').toBe(1)

		for (const id of issued) {
			const after = await fx.read('credential', id)
			expect(after.issuedAt).toBe(ISSUED_AT)
			expect(after.kind).toBe('certificate')
			expect(after.courseId).toBe(courseId)
			expect(Number(after.reissueCount)).toBe(1)
			expect(after.reissueReason).toBe(reason)
			expect(after.reissuedBy).toBe(officer.id)
			expect(String(after.reissuedAt)).not.toBe('')
			expect(after.signature).not.toBe(`r5-before-${fx.run}`)
		}
		const revokedAfter = await fx.read('credential', revokedId)
		const expiredAfter = await fx.read('credential', expiredId)
		for (const [before, after] of [
			[revokedBefore, revokedAfter],
			[expiredBefore, expiredAfter],
		]) {
			expect(after.signature).toBe(before.signature)
			expect(after.lifecycle).toBe(before.lifecycle)
			expect(Number(after.reissueCount ?? 0)).toBe(0)
		}
	})

	test('the certificate still shows its issue date and its history shows the reissue', async ({
		browser,
	}) => {
		test.skip(issued.length === 0, 'the reissue test did not run')
		const page = await signInAs(browser, officer)
		try {
			await page.goto(`${APP}/credentials/${issued[0]}`, {
				waitUntil: 'domcontentloaded',
			})
			const main = page.locator('main, #app-content-vue, #content').first()
			// 3 March, in whatever format the data widget uses.
			await expect(main).toContainText(
				/2026-03-03|3 (Mar|mrt)|03\/03\/2026|3\/3\/2026/,
				{
					timeout: 60_000,
				},
			)
			await expect(main).toContainText(reason)
			await expect(main).toContainText(officer.id)

			await page
				.getByRole('tab', { name: 'History' })
				.click({ timeout: 30_000 })
			const history = page.getByRole('tabpanel', { name: 'History' })
			await expect(history).toContainText(officer.id, { timeout: 30_000 })
		} finally {
			await page.context().close()
		}
	})
})
