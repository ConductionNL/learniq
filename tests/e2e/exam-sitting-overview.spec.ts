/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Extra time and free invigilators on an exam sitting
 * (exam-sitting-overview-section, task 2.2). On the vo example set a planner
 * opens a Toetsweek 1 sitting with an accommodated learner, reads that
 * learner's end time with the extra time, asks a colleague who is free for
 * the whole sitting, and sees the places line and the free list change.
 *
 * The example set seeds the sittings (vo.py `add_public_test_week`) but no
 * invigilator availability, and its dyslexia accommodations fall on random
 * pupils. So the suite adds what the scenario needs and removes it again: an
 * approved 25 percent accommodation for a 4H1 pupil when the sitting has no
 * accommodated learner yet, and one colleague's availability for the sitting.
 *
 * The planner is in `team-leads`: the overview reads exam accommodations with
 * the caller's access, and the register lets team leads read them.
 *
 * Covers:
 *   @e2e openspec/specs/exam-schedule/spec.md#scenario-a-learner-with-25-percent-extra-time
 *   @e2e openspec/specs/exam-schedule/spec.md#scenario-ask-a-free-colleague
 */
import type { APIRequestContext } from '@playwright/test'
import type { TempUser } from './live-fixtures.ts'

import { expect, test } from './fixtures.ts'
import { apiAs, LiveFixtures, signInAs } from './live-fixtures.ts'

const OBJECTS = '/index.php/apps/openregister/api/objects'
const SITTINGS = '/index.php/apps/learniq/api/exam-sittings'
const PERIOD = 'Toetsweek 1'

interface Overview {
	accommodations: Array<{ learnerId: string; endsAt: string; extraTimePercent: number }>
	invigilators: { needed: number; confirmed: string[]; pending: string[]; open: number }
}

/**
 * The places line the section prints for an overview.
 *
 * @param overview The overview route's answer.
 * @return "1 of 3 confirmed, 1 asked, 1 open".
 */
function placesLine(overview: Overview): string {
	const places = overview.invigilators
	return `${places.confirmed.length} of ${places.needed} confirmed, ${places.pending.length} asked, ${places.open} open`
}

test.describe('extra time and free invigilators on an exam sitting', () => {
	const fx = new LiveFixtures()
	let planner: TempUser
	let colleague: TempUser
	let api: APIRequestContext
	let sittingId = ''

	/**
	 * The overview as the planner reads it.
	 *
	 * @return The overview.
	 */
	async function overview(): Promise<Overview> {
		const res = await api.get(`${SITTINGS}/${sittingId}/overview`)
		expect(res.status(), await res.text()).toBe(200)
		return res.json()
	}

	test.beforeAll(async () => {
		test.setTimeout(180_000)
		planner = await fx.user('planner', ['team-leads'])
		colleague = await fx.user('colleague', ['instructors'])
		api = await apiAs(planner.id, planner.password)

		const [period] = await fx.find('exam-period', { name: PERIOD })
		test.skip(period === undefined, `the vo example set is not loaded: no exam period "${PERIOD}"`)
		const sittings = (await fx.find('exam-sitting', { examPeriodId: String(period.id) }))
			.sort((a, b) => String(a.startsAt).localeCompare(String(b.startsAt)))
		expect(sittings.length, `the sittings of ${PERIOD}`).toBeGreaterThan(0)
		const sitting = sittings[0]
		sittingId = String(sitting.id)

		// An accommodated learner in the sitting's classes. The seed's dyslexia
		// statements are spread at random, so add one when this sitting has none.
		if ((await overview()).accommodations.length === 0) {
			const cohort = await fx.read('cohort', String(sitting.cohortIds[0]))
			const learnerId = String(cohort.learnerIds?.[0] ?? '')
			expect(learnerId, 'a pupil in the sitting\'s class').not.toBe('')
			const accommodation = await fx.object('exam-accommodation', {
				learnerId,
				submittedBy: 'admin',
				accommodationKind: 'extra-time-percentage',
				value: 25,
			})
			const admin = await fx.api()
			const approved = await admin.post(`${OBJECTS}/${accommodation}/transition`, { data: { action: 'approve' } })
			expect(approved.status(), await approved.text()).toBe(200)
		}

		// A colleague free for the whole sitting, an hour either side.
		const hour = 60 * 60 * 1000
		await fx.object('invigilator-availability', {
			invigilatorId: colleague.id,
			examPeriodId: String(period.id),
			availableFrom: new Date(new Date(sitting.startsAt).getTime() - hour).toISOString(),
			availableUntil: new Date(new Date(sitting.endsAt).getTime() + hour).toISOString(),
		})
	})

	test.afterAll(async () => {
		// The request "Ask" wrote is the planner's, made in the browser.
		const asked = await fx.find('invigilator-assignment', { examSittingId: sittingId, invigilatorId: colleague.id }).catch(() => [])
		for (const row of asked) {
			fx.adopt('invigilator-assignment', String(row.id))
		}
		await api?.dispose()
		await fx.teardown()
	})

	// @e2e openspec/specs/exam-schedule/spec.md#scenario-a-learner-with-25-percent-extra-time
	// @e2e openspec/specs/exam-schedule/spec.md#scenario-ask-a-free-colleague
	test('the planner reads the end time, asks a free colleague and sees the counts change', async ({ browser }) => {
		test.setTimeout(180_000)
		const before = await overview()
		const [row] = before.accommodations
		expect(row, 'an accommodated learner in the sitting').toBeTruthy()
		expect(before.invigilators.pending).not.toContain(colleague.id)

		const page = await signInAs(browser, planner)
		await page.goto(`/index.php/apps/learniq/exam-sittings/${sittingId}`)
		await expect(page.getByText('Extra time and invigilators').first()).toBeVisible({ timeout: 60_000 })

		// The end time with the extra time, in the browser's own clock format.
		const clock = await page.evaluate(
			(iso) => new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
			row.endsAt,
		)
		const learnerRow = page.locator('.exam-sitting-overview__row').filter({ hasText: `Ends at ${clock}` }).first()
		await expect(learnerRow).toBeVisible()
		await expect(learnerRow).toContainText(`${row.extraTimePercent}%`)

		// The places, then the colleague in the free list.
		const places = page.getByTestId('exam-sitting-places')
		await expect(places).toHaveText(placesLine(before))
		const ask = page.getByRole('button', { name: new RegExp(`^Ask .*${colleague.id}`) })
		await expect(ask).toBeVisible()
		await ask.click()

		// A pending request for her, and the section reloaded.
		await expect
			.poll(async () => (await fx.find('invigilator-assignment', { examSittingId: sittingId, invigilatorId: colleague.id })).map((r) => r.lifecycle), { timeout: 30_000 })
			.toEqual(['pending'])
		const after = await overview()
		expect(after.invigilators.pending.length).toBe(before.invigilators.pending.length + 1)
		await expect(places).toHaveText(placesLine(after))
		await expect(ask).toHaveCount(0)
		await page.context().close()
	})
})
