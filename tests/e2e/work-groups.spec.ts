/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * enrolment-self-join-work-group, Tasks 3 and 4.
 *
 * Teacher: adds a work group to a class through the Work groups page, and the
 * class page lists it. Groups are added one at a time; the "make five groups
 * of four in one action" in the original task was not built (see tasks.md).
 *
 * Learner: a temporary learner in the class sees the open set, a full group
 * offers no button, and joining a group with a free place makes them a member.
 *
 * @e2e openspec/specs/enrolment/spec.md#requirement-a-teacher-sets-up-work-groups-with-a-maximum-size
 * @e2e openspec/specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place
 * @e2e openspec/specs/enrolment/spec.md#requirement-a-learner-is-in-one-work-group-per-set
 * @e2e openspec/specs/enrolment/spec.md#requirement-a-group-hand-in-names-the-whole-work-group
 */
import { writeFileSync } from 'fs'
import { expect, test } from './fixtures.ts'
import { LiveFixtures, signInAs } from './live-fixtures.ts'

const APP = '/index.php/apps/learniq'

test.describe('work groups', () => {
	test.describe.configure({ mode: 'serial', timeout: 240_000 })

	const fx = new LiveFixtures()
	const nextWeek = new Date(Date.now() + 7 * 86_400_000).toISOString()

	test.afterAll(async () => {
		await fx.teardown()
	})

	// Unblocked by nextcloud-vue 2.57.4: the Class picker (a `$ref: Cohort`
	// field in CnFormDialog) lists classes (#1263), and the dialog no longer
	// asks for a Tenant; it is filled from learniq's caller tenant (#1283,
	// learniq #1447). The teacher is a temporary instructor.
	test('a teacher adds a work group to a class', async ({ browser }) => {
		const instructor = await fx.user('instructor', ['instructors'])
		const cohortName = `r5 work groups class ${fx.run}`
		const cohortId = await fx.object('cohort', {
			name: cohortName,
			period: 'Q1',
			academicYear: '2026-2027',
			learnerIds: [],
		})
		const groupName = `r5 Groep 1 ${fx.run}`
		const setName = `r5 Project campagne periode 2 ${fx.run}`

		const teacher = await signInAs(browser, instructor)
		try {
			await teacher.goto(`${APP}/work-groups`, {
				waitUntil: 'domcontentloaded',
			})
			await teacher
				.locator('[data-testid="cn-cta-primary"]')
				.first()
				.click({ timeout: 60_000 })
			const form = teacher.getByRole('dialog', { name: 'Create Work group' })
			await expect(form).toBeVisible({ timeout: 30_000 })

			const classBox = form.getByRole('combobox', { name: /Class/ })
			await classBox.click()
			await classBox.pressSequentially(cohortName)
			await teacher
				.locator('.vs__dropdown-option', { hasText: cohortName })
				.first()
				.click({ timeout: 30_000 })
			await form.getByLabel(/Maximum members/).fill('4')
			await form.getByLabel(/^Name/).fill(groupName)
			await form.getByLabel(/^Set/).fill(setName)
			// The tenant comes from the caller, not from the user.
			await expect(form.getByLabel(/^Tenant/)).toHaveCount(0)
			await form.getByRole('button', { name: 'Create' }).click()
			await expect(form).toBeHidden({ timeout: 30_000 })

			const created = await fx.find('work-group', { cohortId })
			for (const g of created) fx.adopt('work-group', String(g.id))
			expect(created.map((g) => g.name)).toEqual([groupName])
			expect(Number(created[0].maxMembers)).toBe(4)
			expect(created[0].setName).toBe(setName)
			// The dialog fills tenant_id from learniq's caller tenant
			// (CallerTenantResolver): the user's learniq `tenant_id` setting, else
			// the instance id. It must not be empty.
			expect(String(created[0].tenant_id ?? '')).not.toBe('')
			// Soft: the class the teacher picked lives in the instance's data
			// tenant. A group in another tenant is a split the learner side may
			// not see.
			expect
				.soft(created[0].tenant_id, 'work group tenant vs the class tenant')
				.toBe((await fx.read('cohort', cohortId)).tenant_id)

			// The class page lists it in its Work groups widget.
			await teacher.goto(`${APP}/cohorts/${cohortId}`, {
				waitUntil: 'domcontentloaded',
			})
			const widget = teacher.getByRole('group', {
				name: 'coh-work-groups',
				exact: true,
			})
			await expect
				.poll(
					async () => {
						await teacher.mouse.wheel(0, 4000)
						return widget.getByText(groupName).count()
					},
					{ timeout: 60_000 },
				)
				.toBeGreaterThan(0)
		} finally {
			await teacher.context().close()
		}
	})

	test('a learner joins a group with a free place; a full group offers nothing', async ({
		browser,
	}) => {
		const learner = await fx.user('learner', ['learners'])
		const classmate = `r5-classmate-${fx.run}`
		const cohortId = await fx.object('cohort', {
			name: `r5 join class ${fx.run}`,
			period: 'Q1',
			academicYear: '2026-2027',
			learnerIds: [learner.id, classmate],
		})
		const setName = `r5 join set ${fx.run}`
		const fullId = await fx.object('work-group', {
			cohortId,
			setName,
			name: 'Groep vol',
			maxMembers: 1,
			memberIds: [classmate],
			selfJoinUntil: nextWeek,
			lifecycle: 'open',
		})
		const freeId = await fx.object('work-group', {
			cohortId,
			setName,
			name: 'Groep vrij',
			maxMembers: 4,
			memberIds: [],
			selfJoinUntil: nextWeek,
			lifecycle: 'open',
		})

		const page = await signInAs(browser, learner)
		try {
			await page.goto(`${APP}/my-work-groups`, {
				waitUntil: 'domcontentloaded',
			})
			const set = page.locator('.my-work-groups__set', { hasText: setName })
			await expect(set).toBeVisible({ timeout: 60_000 })

			const full = set.locator('.my-work-groups__group', {
				hasText: 'Groep vol',
			})
			await expect(full.getByRole('button')).toHaveCount(0)

			const free = set.locator('.my-work-groups__group', {
				hasText: 'Groep vrij',
			})
			await free.getByRole('button', { name: 'Join', exact: true }).click()
			await expect(
				free.getByRole('button', { name: 'Leave', exact: true }),
			).toBeVisible({
				timeout: 30_000,
			})
		} finally {
			await page.context().close()
		}

		expect((await fx.read('work-group', freeId)).memberIds).toEqual([learner.id])
		expect((await fx.read('work-group', fullId)).memberIds).toEqual([classmate])
	})
	test('a learner moves to another group of the set and hands in for the whole group', async ({
		browser,
	}, testInfo) => {
		const learner = await fx.user('mover', ['learners'])
		const others = [1, 2, 3].map((n) => `r5-member${n}-${fx.run}`)
		const cohortId = await fx.object('cohort', {
			name: `r5 move class ${fx.run}`,
			period: 'Q1',
			academicYear: '2026-2027',
			learnerIds: [learner.id, ...others],
		})
		const setName = `r5 Project campagne ${fx.run}`
		const fourId = await fx.object('work-group', {
			cohortId,
			setName,
			name: 'Groep 4',
			maxMembers: 4,
			memberIds: [learner.id],
			selfJoinUntil: nextWeek,
			lifecycle: 'open',
		})
		const fiveId = await fx.object('work-group', {
			cohortId,
			setName,
			name: 'Groep 5',
			maxMembers: 4,
			memberIds: others,
			selfJoinUntil: nextWeek,
			lifecycle: 'open',
		})
		const assignmentId = await fx.object('assignment', {
			title: `r5 Campagneplan ${fx.run}`,
			maxPoints: 10,
			cohortId,
			groupSubmission: true,
			workGroupSetName: setName,
			dueAt: nextWeek,
			// A learner reads published and closed assignments only.
			lifecycle: 'published',
		})

		const page = await signInAs(browser, learner)
		try {
			await page.goto(`${APP}/my-work-groups`, {
				waitUntil: 'domcontentloaded',
			})
			const set = page.locator('.my-work-groups__set', { hasText: setName })
			await expect(set).toBeVisible({ timeout: 60_000 })
			const five = set.locator('.my-work-groups__group', {
				hasText: 'Groep 5',
			})
			await five
				.getByRole('button', { name: 'Move here', exact: true })
				.click()
			await expect(
				five.getByRole('button', { name: 'Leave', exact: true }),
			).toBeVisible({ timeout: 30_000 })

			// OpenRegister leaves an emptied list out of the object.
			expect((await fx.read('work-group', fourId)).memberIds ?? []).toEqual([])
			expect(
				[...(await fx.read('work-group', fiveId)).memberIds].sort(),
			).toEqual([...others, learner.id].sort())

			// One member hands in for the whole group.
			await page.goto(`${APP}/assignments/${assignmentId}/submit`, {
				waitUntil: 'domcontentloaded',
			})
			const work = testInfo.outputPath('campagneplan.txt')
			writeFileSync(work, `r5 campagneplan ${fx.run}\n`)
			await page
				.locator('input[type="file"]')
				.first()
				.setInputFiles(work, { timeout: 60_000 })
			await page.getByRole('button', { name: 'Hand in', exact: true }).click()
			await expect(page.getByText('Your work is handed in.')).toBeVisible({
				timeout: 60_000,
			})
		} finally {
			await page.context().close()
		}

		const submissions = await fx.find('submission', { assignmentId })
		for (const row of submissions) fx.adopt('submission', String(row.id))
		expect(submissions).toHaveLength(1)
		expect([...submissions[0].learnerIds].sort()).toEqual(
			[learner.id, ...others].sort(),
		)
	})
})
