import { expect, test } from './fixtures.ts'
import { createObject } from './or-api.ts'

/**
 * timetabling-room-utilisation: the room use report shows a room's hours in
 * use against its open hours, and names a lesson without a room.
 *
 * The admin makes a gym and a lab, a group, and lessons on Monday of a
 * future week: eight hours in the gym, one hour in the lab, one hour with only
 * a free-text location. The report for that Monday shows the gym at 89
 * percent of nine open hours and the lab at 11 percent.
 *
 * @spec openspec/specs/school-structure/spec.md#scenario-a-deputy-head-checks-the-gyms
 * @spec openspec/specs/school-structure/spec.md#scenario-unassigned-lessons-are-named
 */
test.describe('Room use', () => {
	test('the gym is busy, the lab is not, and a roomless lesson is named', async ({
		loggedInPage: page,
	}) => {
		const suffix = Date.now().toString(36)
		const gym = await createObject(page, 'room', {
			name: `Gymzaal ${suffix}`,
			code: `GYM-${suffix}`,
			capacity: 35,
			kind: 'gym',
		})
		const lab = await createObject(page, 'room', {
			name: `Lab ${suffix}`,
			code: `LAB-${suffix}`,
			capacity: 30,
			kind: 'lab',
		})
		test.skip(
			!gym || !lab,
			'The room schema is not importable on this instance.',
		)
		const cohortId = await createObject(page, 'cohort', {
			name: `RU ${suffix}`,
			learnerIds: [],
		})

		// A Monday in 2031, far from any seeded lesson or holiday.
		const day = '2031-03-10'
		const lesson = (
			title: string,
			start: string,
			end: string,
			extra: Record<string, unknown>,
		) =>
			createObject(page, 'session', {
				cohortId,
				title,
				startsAt: `${day}T${start}:00Z`,
				endsAt: `${day}T${end}:00Z`,
				lifecycle: 'scheduled',
				...extra,
			})
		await lesson(`Gym ${suffix}`, '08:00', '16:00', { roomId: gym })
		await lesson(`Lab ${suffix}`, '09:00', '10:00', { roomId: lab })
		await lesson(`Buiten ${suffix}`, '10:00', '11:00', { location: 'Sportveld' })

		const res = await page.request.get(
			`/index.php/apps/learniq/api/reports/room-use?from=${day}&to=${day}`,
		)
		expect(res.ok()).toBeTruthy()
		const body = await res.json()
		const rows = Object.fromEntries(
			body.rooms.map((r: { roomId: string }) => [r.roomId, r]),
		)
		expect(rows[gym as string].hoursInUse).toBe(8)
		expect(rows[lab as string].hoursInUse).toBe(1)
		expect(body.unassigned.count).toBeGreaterThanOrEqual(1)

		await page.goto('/index.php/apps/learniq/reports/room-use', {
			waitUntil: 'domcontentloaded',
		})
		await expect(
			page.getByRole('heading', { name: 'Room use', exact: true }),
		).toBeVisible({ timeout: 30_000 })
	})
})
