/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * assignments-double-marking, Tasks 4 and 5: the teacher in charge allocates
 * two markers to a hand-in, each marker hands in their own mark, and once both
 * are in, the teacher in charge sets the final grade from the average.
 *
 * Actors: admin as the teacher in charge (reads every mark), and two
 * temporary markers in learniq's `instructors` group. The learner is only a
 * name on the hand-in: no one signs in as them.
 *
 * @e2e openspec/changes/assignments-double-marking/specs/assignments/spec.md#requirement-the-teacher-in-charge-allocates-markers
 * @e2e openspec/changes/assignments-double-marking/specs/assignments/spec.md#requirement-each-marker-scores-in-their-own-submissionmark
 * @e2e openspec/changes/assignments-double-marking/specs/assignments/spec.md#requirement-one-person-sets-the-final-grade-once-every-mark-is-in
 */
import type { Browser } from '@playwright/test'
import type { TempUser } from './live-fixtures.ts'

import { expect, test } from './fixtures.ts'
import { LiveFixtures, signInAs } from './live-fixtures.ts'

const APP = '/index.php/apps/learniq'

/**
 * Sign in as a marker, propose a grade and hand the mark in.
 *
 * @param browser The browser.
 * @param marker The marker.
 * @param markUrl The hand-in's marking page.
 * @param grade The proposed grade.
 */
async function handInMark(
	browser: Browser,
	marker: TempUser,
	markUrl: string,
	grade: number,
): Promise<void> {
	const page = await signInAs(browser, marker)
	try {
		await page.goto(markUrl, { waitUntil: 'domcontentloaded' })
		await page.locator('#manual-grade').fill(String(grade), { timeout: 60_000 })
		await page.getByRole('button', { name: 'Hand in my mark' }).click()
		await expect(
			page
				.getByRole('status')
				.filter({ hasText: 'Your mark is handed in.' })
				.first(),
		).toBeVisible({ timeout: 30_000 })
	} finally {
		await page.context().close()
	}
}

test.describe('double marking', () => {
	test.describe.configure({ timeout: 300_000 })

	const fx = new LiveFixtures()

	test.afterAll(async () => {
		await fx.teardown()
	})

	test('two markers mark a hand-in and the teacher in charge sets the agreed grade', async ({
		loggedInPage: teacher,
		browser,
	}) => {
		const first = await fx.user('marker1', ['instructors'])
		const second = await fx.user('marker2', ['instructors'])
		const title = `r5 double marking ${fx.run}`
		const assignmentId = await fx.object('assignment', {
			title,
			maxPoints: 10,
			markersPerSubmission: 2,
			finalGradeRule: 'average',
		})
		const submissionId = await fx.object('submission', {
			assignmentId,
			learnerIds: [`r5-learner-${fx.run}`],
			lifecycle: 'submitted',
		})

		// The teacher in charge allocates both markers to every hand-in.
		await teacher.goto(`${APP}/assignments/${assignmentId}/markers`, {
			waitUntil: 'domcontentloaded',
		})
		const markersBox = teacher.getByRole('combobox', { name: 'Markers' })
		for (const marker of [first, second]) {
			await markersBox.click({ timeout: 60_000 })
			await markersBox.pressSequentially(marker.id)
			// vue-select's options carry no accessible name Playwright can match.
			await teacher
				.locator('.vs__dropdown-option', { hasText: marker.id })
				.first()
				.click({ timeout: 30_000 })
		}
		await teacher.getByRole('button', { name: 'Allocate', exact: true }).click()
		await expect(teacher.getByText('2 marks allocated.')).toBeVisible({
			timeout: 30_000,
		})

		const marks = await fx.find('submission-mark', { submissionId })
		for (const m of marks) fx.adopt('submission-mark', String(m.id))
		expect(marks.map((m) => m.markerId).sort()).toEqual(
			[first.id, second.id].sort(),
		)

		// Each marker hands in their own mark.
		const markUrl = `${APP}/assignments/${assignmentId}/submissions/${submissionId}/mark`
		await handInMark(browser, first, markUrl, 6)
		await handInMark(browser, second, markUrl, 8)

		// Both marks side by side, the final grade proposed from their average.
		await teacher.goto(markUrl, { waitUntil: 'domcontentloaded' })
		const table = teacher.locator('.mark-submission-view__marks-table')
		await expect(table.locator('tbody tr')).toHaveCount(2, { timeout: 60_000 })
		await expect(teacher.locator('#final-grade')).toHaveValue('7')
		await teacher
			.getByRole('button', { name: 'Save & return to learner' })
			.click()
		await expect(
			teacher.getByRole('heading', { name: 'Submission returned to learner' }),
		).toBeVisible({ timeout: 30_000 })

		const saved = await fx.read('submission', submissionId)
		expect(saved.finalGradeSetBy).toBe('admin')
		expect(saved.finalGradeRuleApplied).toBe('average')
		// saveAndReturn() writes the final grade as the submission's proposedGrade.
		expect(Number(saved.proposedGrade)).toBe(7)
	})
})
