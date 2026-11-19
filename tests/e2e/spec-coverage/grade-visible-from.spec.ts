/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A learner reads their own grade only once it is released: published or
 * revised, with `visibleFrom` passed or not set. Staff read it before.
 *
 * Covers:
 *   @e2e openspec/specs/grading/spec.md#scenario-a-grade-held-back-until-tomorrow
 *   @e2e openspec/specs/grading/spec.md#scenario-a-grade-whose-moment-has-passed
 *   @e2e openspec/specs/grading/spec.md#scenario-a-concept-grade
 *   @e2e openspec/specs/grading/spec.md#scenario-a-teacher-reads-before-the-moment
 *
 * The grades sit in a period no ReportPeriod governs, so publishing is not
 * held by a report-period lock.
 */
import type { APIRequestContext } from '@playwright/test'
import type { TempUser } from '../live-fixtures.ts'

import { expect, test } from '../fixtures.ts'
import { apiAs, LiveFixtures } from '../live-fixtures.ts'

const OBJECTS = '/index.php/apps/openregister/api/objects'
const DAY = 24 * 60 * 60 * 1000

/**
 * The component ids of the grade entries a caller can list.
 *
 * @param api The caller.
 * @return The component ids.
 */
async function listedComponents(api: APIRequestContext): Promise<string[]> {
	const res = await api.get(`${OBJECTS}/learniq/grade-entry?_limit=200`)
	expect(res.status(), await res.text()).toBe(200)
	return ((await res.json()).results ?? []).map(
		(g: Record<string, unknown>) => String(g.componentId),
	)
}

test.describe('a learner reads a grade once it is released', () => {
	const fx = new LiveFixtures()
	let learner: TempUser
	let teacher: TempUser
	const ids: Record<string, string> = {}
	const component = (name: string) => `e2e-${name}-${fx.run}`

	test.beforeAll(async () => {
		learner = await fx.user('learner', ['learners'])
		teacher = await fx.user('teacher', ['instructors'])
		const [plan] = await fx.find('curriculum-plan', {})
		const [scale] = await fx.find('grade-scale', {})
		expect(plan, 'a seeded curriculum plan').toBeTruthy()
		expect(scale, 'a seeded grade scale').toBeTruthy()

		const api = await fx.api()
		const grades: Array<[string, string | null, boolean]> = [
			['tomorrow', new Date(Date.now() + DAY).toISOString(), true],
			['yesterday', new Date(Date.now() - DAY).toISOString(), true],
			['concept', null, false],
		]
		for (const [name, visibleFrom, publish] of grades) {
			const id = await fx.object('grade-entry', {
				learnerId: learner.id,
				curriculumPlanId: plan.id,
				gradeScaleId: scale.id,
				componentId: component(name),
				period: `E2E-${fx.run}`,
				value: 7,
				weight: 1,
				...(visibleFrom ? { visibleFrom } : {}),
			})
			if (publish) {
				const res = await api.post(`${OBJECTS}/${id}/transition`, {
					data: { action: 'publish' },
				})
				expect(res.status(), await res.text()).toBe(200)
			}
			ids[name] = id
		}
	})

	test.afterAll(async () => {
		await fx.teardown()
	})

	// @e2e openspec/specs/grading/spec.md#scenario-a-grade-held-back-until-tomorrow
	// @e2e openspec/specs/grading/spec.md#scenario-a-grade-whose-moment-has-passed
	// @e2e openspec/specs/grading/spec.md#scenario-a-concept-grade
	test('the learner lists only the released grade', async () => {
		const api = await apiAs(learner.id, learner.password)
		const listed = await listedComponents(api)
		expect(listed).toContain(component('yesterday'))
		expect(listed).not.toContain(component('tomorrow'))
		expect(listed).not.toContain(component('concept'))

		const byId = await api.get(`${OBJECTS}/learniq/grade-entry/${ids.tomorrow}`)
		const body = byId.ok() ? await byId.json() : null
		expect(body?.value, 'reading the held-back grade by id returns no grade').toBeUndefined()
		await api.dispose()
	})

	// @e2e openspec/specs/grading/spec.md#scenario-a-teacher-reads-before-the-moment
	test('an instructor reads the grade before its moment', async () => {
		const api = await apiAs(teacher.id, teacher.password)
		const res = await api.get(`${OBJECTS}/learniq/grade-entry/${ids.tomorrow}`)
		expect(res.status(), await res.text()).toBe(200)
		expect((await res.json()).componentId).toBe(component('tomorrow'))
		await api.dispose()
	})
})
