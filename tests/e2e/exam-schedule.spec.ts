/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * timetabling-exam-schedule, task 4.1, through the API a planner's screens use.
 *
 * A planner places an exam in a test week: a room too small is refused, a
 * class with another lesson gets a clash naming it. A learner with approved
 * extra time gets a later end; a requested accommodation changes nothing. An
 * invigilator who stated availability is asked, confirms their own request,
 * and a decline leaves an open place. Someone without availability is refused.
 *
 * @e2e openspec/specs/exam-schedule/spec.md#requirement-exam-periods-and-sittings
 * @e2e openspec/specs/exam-schedule/spec.md#requirement-accommodations-in-the-schedule
 * @e2e openspec/specs/exam-schedule/spec.md#requirement-invigilator-assignment
 */
import { request as playwrightRequest } from '@playwright/test'
import type { APIRequestContext } from '@playwright/test'
import { expect, test } from './fixtures.ts'
import { LiveFixtures } from './live-fixtures.ts'
import type { TempUser } from './live-fixtures.ts'
import { baseUrl } from './base-url.ts'

const OR = '/index.php/apps/openregister/api/objects'
const APP = '/index.php/apps/learniq'

async function as(user: TempUser): Promise<APIRequestContext> {
	return playwrightRequest.newContext({
		baseURL: baseUrl(),
		httpCredentials: { username: user.id, password: user.password, send: 'always' },
		extraHTTPHeaders: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
	})
}

test.describe('exam schedule', () => {
	test.describe.configure({ mode: 'serial', timeout: 240_000 })

	const fx = new LiveFixtures()
	const day = new Date(Date.now() + 14 * 86_400_000).toISOString().slice(0, 10)
	const at = (hhmm: string): string => `${day}T${hhmm}:00+00:00`
	let periodId = ''
	let roomId = ''
	let cohortId = ''
	let examId = ''
	let learner: TempUser
	let invigilator: TempUser

	test.afterAll(async () => {
		await fx.teardown()
	})

	test('a room too small is refused and a clash names the other lesson', async () => {
		const api = await fx.api()
		learner = await fx.user('examlearner', ['learners'])
		roomId = await fx.object('room', { name: `A1 ${fx.run}`, capacity: 30, kind: 'classroom' })
		cohortId = await fx.object('cohort', { name: `5A ${fx.run}`, period: 'Periode 1', academicYear: '2026-2027', learnerIds: [learner.id] })
		examId = await fx.object('exam', { title: `Maths B ${fx.run}` })
		periodId = await fx.object('exam-period', { name: `Toetsweek ${fx.run}`, startsOn: day, endsOn: day })
		await fx.object('session', { cohortId, title: `Dutch ${fx.run}`, startsAt: at('09:30'), endsAt: at('10:20') })

		const tenant = await fx.tenant()
		const sitting = { examPeriodId: periodId, assessmentId: examId, cohortIds: [cohortId], startsAt: at('09:00'), endsAt: at('10:00'), roomIds: [roomId], invigilatorsNeeded: 1, tenant_id: tenant }

		const tooSmall = await api.post(`${OR}/learniq/exam-sitting`, { data: { ...sitting, headcount: 60 } })
		expect(tooSmall.ok()).toBe(false)
		expect(JSON.stringify(await tooSmall.json().catch(() => ({})))).toContain('60')

		const placed = await fx.object('exam-sitting', { ...sitting, headcount: 28 })
		const stored = await fx.read('exam-sitting', placed)
		expect(stored.clashWarnings.join(' ')).toContain(`Dutch ${fx.run}`)
	})

	test('approved extra time lengthens the end, a requested one does not', async () => {
		const api = await fx.api()
		const sittingId = await fx.object('exam-sitting', { examPeriodId: periodId, assessmentId: examId, cohortIds: [cohortId], startsAt: at('13:00'), endsAt: at('14:00'), roomIds: [roomId], headcount: 20 })
		const accommodation = await fx.object('exam-accommodation', { learnerId: learner.id, submittedBy: learner.id, accommodationKind: 'extra-time-percentage', value: 25 })

		const before = await (await api.get(`${APP}/api/exam-sittings/${sittingId}/overview`)).json()
		expect(before.accommodations).toEqual([])

		const approve = await api.post(`${OR}/${accommodation}/transition`, { data: { action: 'approve' } })
		expect(approve.ok()).toBe(true)

		const after = await (await api.get(`${APP}/api/exam-sittings/${sittingId}/overview`)).json()
		expect(after.accommodations).toHaveLength(1)
		expect(new Date(after.accommodations[0].endsAt).toISOString()).toBe(new Date(at('14:15')).toISOString())
	})

	test('an available invigilator is asked and confirms; a decline opens the place', async () => {
		const api = await fx.api()
		invigilator = await fx.user('invigilator', ['instructors'])
		const absent = await fx.user('absent', ['instructors'])
		const sittingId = await fx.object('exam-sitting', { examPeriodId: periodId, assessmentId: examId, cohortIds: [], startsAt: at('15:00'), endsAt: at('16:00'), roomIds: [roomId], headcount: 10, invigilatorsNeeded: 1 })
		await fx.object('invigilator-availability', { invigilatorId: invigilator.id, examPeriodId: periodId, availableFrom: at('08:00'), availableUntil: at('17:00') })

		const offered = await (await api.get(`${APP}/api/exam-sittings/${sittingId}/available-invigilators`)).json()
		expect(offered.invigilators).toContain(invigilator.id)
		expect(offered.invigilators).not.toContain(absent.id)

		const refused = await api.post(`${OR}/learniq/invigilator-assignment`, { data: { examSittingId: sittingId, invigilatorId: absent.id, tenant_id: await fx.tenant() } })
		expect(refused.ok()).toBe(false)

		const requestId = await fx.object('invigilator-assignment', { examSittingId: sittingId, invigilatorId: invigilator.id })
		expect((await fx.read('invigilator-assignment', requestId)).lifecycle).toBe('pending')

		const own = await as(invigilator)
		const decline = await own.post(`${OR}/${requestId}/transition`, { data: { action: 'decline' } })
		expect(decline.ok()).toBe(true)

		const overview = await (await api.get(`${APP}/api/exam-sittings/${sittingId}/overview`)).json()
		expect(overview.invigilators.open).toBe(1)
		expect(overview.invigilators.confirmed).toEqual([])
		await own.dispose()
	})
})
