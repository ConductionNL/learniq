/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * enrolment-catalogue-self-signup, Tasks 3 and 4.
 *
 * Admin, as the training coordinator, opens a closed course for sign-up on
 * the course form. A temporary learner then finds it in the catalogue, signs
 * up and withdraws, signs up for a programme of three courses, sees a
 * provider course with its provider, and requests a place on an on-request
 * course. The learner's manager, a temporary account in no staff group,
 * approves that request from the enrolment's lifecycle actions, and the
 * learner's notification is queued for dispatch.
 *
 * A learner needs a learner profile ({ncUserId}); without one, sign-up
 * answers 403 not_a_learner. learner-profile is an archival schema, so
 * teardown keeps it (reported as retained).
 *
 * @e2e openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-a-course-or-programme-says-whether-learners-may-sign-up
 * @e2e openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
 * @e2e openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-a-learner-signs-up-for-a-whole-programme
 * @e2e openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-a-learner-withdraws-their-own-sign-up
 * @e2e openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-provider-courses-show-their-provider
 * @e2e openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-a-request-waits-for-a-teacher-or-manager
 */
import type { Page } from '@playwright/test'

import { expect, test } from './fixtures.ts'
import { listJobs, LiveFixtures, signInAs } from './live-fixtures.ts'

const APP = '/index.php/apps/learniq'

/**
 * Search the catalogue and return the card for one entry.
 *
 * @param page The learner's page.
 * @param name The course name.
 * @return The card locator.
 */
async function findCard(page: Page, name: string) {
	await page.getByLabel('Search courses').fill(name)
	await page.getByRole('button', { name: 'Search', exact: true }).click()
	const card = page.locator('.course-catalogue__card', { hasText: name })
	await expect(card).toHaveCount(1, { timeout: 60_000 })
	return card
}

test.describe('course catalogue', () => {
	test.describe.configure({ mode: 'serial', timeout: 480_000 })

	const fx = new LiveFixtures()
	const courseIds: string[] = []
	const openName = `r5 Excel voor gevorderden ${fx.run}`
	const requestName = `r5 Leidinggeven aan hybride teams ${fx.run}`
	const programmeName = `r5 Basis projectmanagement ${fx.run}`
	const providerName = `r5 provider course ${fx.run}`
	let openId = ''
	let requestId = ''
	let programmeId = ''
	const trackIds: string[] = []
	let requestEnrolment = ''
	let manager = { id: '', password: '' }

	test.afterAll(async () => {
		// Enrolments the learner made through the app belong to this run too.
		for (const courseId of courseIds) {
			for (const e of await fx
				.find('enrolment', { courseId })
				.catch(() => [])) {
				fx.adopt('enrolment', String(e.id))
			}
		}
		await fx.teardown()
	})

	/**
	 * A published course as admin.
	 *
	 * @param code Short code suffix.
	 * @param name The name.
	 * @param extra Other fields.
	 * @return The uuid.
	 */
	async function course(
		code: string,
		name: string,
		extra: Record<string, unknown> = {},
	): Promise<string> {
		const id = await fx.object('course', {
			code: `R5-${code}-${fx.run}`,
			name,
			level: 'corporate',
			language: 'nl',
			lifecycle: 'published',
			...extra,
		})
		courseIds.push(id)
		return id
	}

	test('a training coordinator opens a course for sign-up on the course form', async ({
		loggedInPage: coordinator,
	}) => {
		openId = await course('OPEN', openName, { selfEnrolment: 'closed' })

		await coordinator.goto(`${APP}/courses/${openId}`, {
			waitUntil: 'domcontentloaded',
		})
		await coordinator
			.getByRole('button', { name: 'Edit', exact: true })
			.first()
			.click({ timeout: 60_000 })
		const form = coordinator.getByRole('dialog').filter({
			has: coordinator.getByText('Sign-up by learners'),
		})
		await expect(form).toBeVisible({ timeout: 30_000 })
		const select = form.getByRole('combobox', { name: /Sign-up by learners/ })
		await select.click()
		await coordinator
			.locator('.vs__dropdown-option', { hasText: /^\s*Open\s*$/ })
			.first()
			.click({ timeout: 30_000 })
		await form.getByRole('button', { name: /^(Save|Update)$/ }).click()
		await expect(form).toBeHidden({ timeout: 30_000 })

		await expect
			.poll(async () => (await fx.read('course', openId)).selfEnrolment, {
				timeout: 30_000,
			})
			.toBe('open')
	})

	test('a learner signs up and withdraws, takes a programme, sees a provider, and requests a place', async ({
		browser,
	}) => {
		test.skip(openId === '', 'the coordinator test did not run')
		const learner = await fx.user('learner', ['learners'])
		// Only someone with a learner profile may sign up.
		// The manager is a plain account, in no learniq staff group, so the
		// approval below runs on the managerId rule and nothing else.
		manager = await fx.user('manager', [])
		await fx.object('learner-profile', {
			ncUserId: learner.id,
			managerId: manager.id,
		})
		requestId = await course('REQ', requestName, { selfEnrolment: 'on-request' })
		for (const n of [1, 2, 3]) {
			trackIds.push(await course(`TRK${n}`, `r5 track course ${n} ${fx.run}`))
		}
		programmeId = await fx.object('programme', {
			code: `R5-PRG-${fx.run}`,
			name: programmeName,
			level: 'corporate',
			lifecycle: 'published',
			selfEnrolment: 'open',
			courseIds: trackIds,
		})
		await course('GO1', providerName, { selfEnrolment: 'open', author: 'Go1' })

		const page = await signInAs(browser, learner)
		try {
			await page.goto(`${APP}/catalogue`, { waitUntil: 'domcontentloaded' })

			// Open course: sign up at once, then withdraw.
			let card = await findCard(page, 'Excel voor gevorderden ' + fx.run)
			await card.getByRole('button', { name: 'Sign up', exact: true }).click()
			await expect(card.getByText('You are signed up')).toBeVisible({
				timeout: 30_000,
			})
			const active = await fx.find('enrolment', { courseId: openId })
			expect(active.map((e) => [e.learnerId, e.lifecycle, e.source])).toEqual([
				[learner.id, 'active', 'self'],
			])
			await card.getByRole('button', { name: 'Withdraw', exact: true }).click()
			await expect(
				card.getByRole('button', { name: 'Sign up', exact: true }),
			).toBeVisible({ timeout: 30_000 })
			expect(
				(await fx.read('enrolment', String(active[0].id))).lifecycle,
			).toBe('withdrawn')

			// Programme: one enrolment per course, each naming the programme.
			card = await findCard(page, programmeName)
			await expect(card).toContainText('Programme')
			await card.getByRole('button', { name: 'Sign up', exact: true }).click()
			// A programme card carries no enrolment of its own, so it answers
			// with the plain success note rather than "You are signed up".
			await expect(card.getByText('Done.')).toBeVisible({ timeout: 30_000 })
			for (const courseId of trackIds) {
				const rows = await fx.find('enrolment', { courseId })
				expect(
					rows.map((e) => [e.learnerId, e.programmeId, e.source]),
					`enrolment on track course ${courseId}`,
				).toEqual([[learner.id, programmeId, 'self']])
			}

			// Provider course: the card names the provider.
			card = await findCard(page, providerName)
			await expect(card).toContainText('Provider: Go1')

			// On-request course: the request waits.
			card = await findCard(page, requestName)
			await card
				.getByRole('button', { name: 'Request a place', exact: true })
				.click()
			await expect(
				card.getByText('Your request is waiting for approval'),
			).toBeVisible({ timeout: 30_000 })
		} finally {
			await page.context().close()
		}

		const pending = await fx.find('enrolment', { courseId: requestId })
		expect(
			pending.map((e) => [e.learnerId, e.lifecycle, e.source, e.managerId]),
		).toEqual([[learner.id, 'pending', 'self', manager.id]])
		requestEnrolment = String(pending[0].id)
	})

	test("the learner's manager approves the request and the learner's notification is queued", async ({
		browser,
	}) => {
		test.skip(requestEnrolment === '', 'the learner test did not run')
		const page = await signInAs(browser, manager)
		try {
			await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' })
			// The spec has the manager open "Sign-up requests". The menu entry is
			// shown by primary role only (instructor, coordinator, hr, team-lead,
			// administration-manager, admin), so a manager with none of those
			// roles does not get it. Soft, so the approval itself still runs.
			await expect
				.soft(page.getByRole('link', { name: 'Sign-up requests' }))
				.toBeVisible({ timeout: 60_000 })
			await page.goto(`${APP}/enrolments/${requestEnrolment}`, {
				waitUntil: 'domcontentloaded',
			})
			await expect(page.locator('[data-testid="cn-detail-page"]')).toBeVisible(
				{
					timeout: 60_000,
				},
			)
			await page
				.getByRole('button', { name: 'Approve', exact: true })
				.click({ timeout: 60_000 })
			const confirm = page
				.getByRole('dialog')
				.getByRole('button', { name: /^(Approve|Confirm)$/ })
			if (await confirm.isVisible({ timeout: 5_000 }).catch(() => false)) {
				await confirm.click()
			}
			await expect
				.poll(
					async () =>
						(await fx.read('enrolment', requestEnrolment)).lifecycle,
					{ timeout: 30_000 },
				)
				.toBe('active')
		} finally {
			await page.context().close()
		}

		// The learner is told through the Enrolment's `signUpApproved`
		// notification (trigger: the approve transition, recipient: learnerId).
		// OpenRegister queues it as an AnnotationNotificationDispatchJob that
		// cron delivers; the shared instance runs no cron, so the spec checks
		// that the approval by the manager was queued for dispatch.
		await expect
			.poll(
				() =>
					listJobs(
						'OCA\\OpenRegister\\BackgroundJob\\AnnotationNotificationDispatchJob',
					).some(
						(job) =>
							job.argument.userId === manager.id
							&& (job.argument.entries ?? []).some(
								(entry: any) =>
									entry.uuid === requestEnrolment
									&& entry.trigger === 'updated'
									&& entry.oldData?.lifecycle === 'pending',
							),
					),
				{ timeout: 60_000 },
			)
			.toBe(true)
	})
})
