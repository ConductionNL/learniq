/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Certificates taken over from the previous system: an hr user uploads the
 * same 20 rows twice; the first commit creates 20 migrated credentials and
 * the second creates none, because every row was migrated before. A
 * migrated course certificate that lapses signs its learner up for the refresher.
 *
 * Covers:
 *   @e2e openspec/specs/certification/spec.md#scenario-the-same-file-twice
 *   @e2e openspec/specs/certification/spec.md#scenario-a-clean-file
 *   @e2e openspec/specs/certification/spec.md#scenario-a-migrated-certificate-lapses
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, test } from './fixtures.ts'
import { apiAs, LiveFixtures } from './live-fixtures.ts'

const MIGRATE = '/index.php/apps/learniq/api/credentials/migrate'
const DAY = 24 * 60 * 60 * 1000

/**
 * A date as YYYY-MM-DD, some days from now.
 *
 * @param days Days from today; negative is in the past.
 * @return The date.
 */
function day(days: number): string {
	return new Date(Date.now() + days * DAY).toISOString().slice(0, 10)
}

test.describe('certificates taken over from the previous system', () => {
	const fx = new LiveFixtures()
	let api: APIRequestContext
	let rows: Array<Record<string, string>> = []

	test.beforeAll(async () => {
		const hr = await fx.user('hr', ['hr'])
		api = await apiAs(hr.id, hr.password)
		const learners = await fx.find('learner-profile', {})
		expect(learners.length, 'seeded learner profiles').toBeGreaterThan(0)
		rows = Array.from({ length: 20 }, (_, n) => ({
			learner: learners[n % learners.length].id,
			// The first row is for a course; the others for the AVG regulation.
			...(n === 0 ? { course: 'DEMO-AVG' } : { regulation: 'AVG' }),
			legacyNumber: `OLD-${fx.run}-${n + 1}`,
			legacyIssuer: 'Vorig LMS',
			issuedAt: day(-300),
			// One certificate ends within the month, the others in a year.
			expiresAt: n === 0 ? day(20) : day(365),
		}))
	})

	test.afterAll(async () => {
		await api?.dispose()
		await fx.teardown()
	})

	// @e2e openspec/specs/certification/spec.md#scenario-a-clean-file
	// @e2e openspec/specs/certification/spec.md#scenario-the-same-file-twice
	// @e2e openspec/specs/certification/spec.md#scenario-a-migrated-certificate-lapses
	test('the first upload creates every certificate and the second creates none', async () => {
		const dry = await api.post(MIGRATE, { data: { rows, dryRun: true } })
		expect(dry.status(), await dry.text()).toBe(200)
		expect((await dry.json()).summary).toMatchObject({ ready: 20, created: 0, total: 20 })

		const first = await api.post(MIGRATE, { data: { rows, dryRun: false } })
		expect(first.status(), await first.text()).toBe(200)
		const firstBody = await first.json()
		expect(firstBody.summary).toMatchObject({ created: 20, total: 20 })
		expect(firstBody.runId).toBeTruthy()

		const migrated = await fx.find('credential', { migrationRunId: firstBody.runId })
		expect(migrated).toHaveLength(20)
		expect(migrated.every((c) => c.source === 'migrated')).toBe(true)

		const lapsing = migrated.find((c) => c.legacyNumber === rows[0].legacyNumber)
		expect(lapsing?.courseId, 'the course row names its course').toBeTruthy()
		const admin = await fx.api()
		const expired = await admin.post(`/index.php/apps/openregister/api/objects/${lapsing.id}/transition`, {
			data: { action: 'expire' },
		})
		expect(expired.status(), await expired.text()).toBe(200)
		await expect.poll(
			async () => (await fx.read('credential', lapsing.id)).renewalEnrolmentId ?? '',
			{ timeout: 30_000 },
		).not.toBe('')
		const renewal = await fx.read('enrolment', (await fx.read('credential', lapsing.id)).renewalEnrolmentId)
		expect(renewal.source).toBe('credential-renewal')
		expect(renewal.courseId).toBe(lapsing.courseId)
		fx.adopt('enrolment', renewal.id)

		const second = await api.post(MIGRATE, { data: { rows, dryRun: false } })
		expect(second.status(), await second.text()).toBe(200)
		const secondBody = await second.json()
		expect(secondBody.summary.created).toBe(0)
		expect(secondBody.rows.every((r: Record<string, string>) => r.status === 'duplicate')).toBe(true)
	})
})
