/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A signed attestation is frozen: a compliance officer signs it, nobody can
 * change its evidence afterwards (an administrator included), and revoking it
 * needs a reason and changes nothing else.
 *
 * Covers:
 *   @e2e openspec/specs/compliance-audit/spec.md#scenario-a-drafted-attestation-is-corrected
 *   @e2e openspec/specs/compliance-audit/spec.md#scenario-a-compliance-officer-edits-a-signed-attestation
 *   @e2e openspec/specs/compliance-audit/spec.md#scenario-an-administrator-edits-a-signed-attestation
 *   @e2e openspec/specs/compliance-audit/spec.md#scenario-revoke-without-a-reason
 *   @e2e openspec/specs/compliance-audit/spec.md#scenario-revoke-with-a-reason
 */
import type { APIRequestContext } from '@playwright/test'
import type { TempUser } from './live-fixtures.ts'

import { expect, test } from './fixtures.ts'
import { apiAs, LiveFixtures } from './live-fixtures.ts'

const OBJECTS = '/index.php/apps/openregister/api/objects'
const COMPLETED = 'http://adlnet.gov/expapi/verbs/completed'

test.describe('a signed attestation is frozen', () => {
	const fx = new LiveFixtures()
	let officer: TempUser
	let api: APIRequestContext
	let attestationId = ''
	const learnerId = `e2e-learner-${fx.run}`

	test.beforeAll(async () => {
		await fx.ensureSigningKey()
		officer = await fx.user('officer', ['compliance-officers'])
		api = await apiAs(officer.id, officer.password)
		const [lesson] = await fx.find('lesson', {})
		const courses = await fx.find('course', {})
		expect(lesson, 'a seeded lesson').toBeTruthy()
		expect(courses.length, 'two seeded courses').toBeGreaterThan(1)

		await fx.object('xapi-statement', {
			actor: { objectType: 'Agent', account: { homePage: 'https://example.org', name: learnerId } },
			verb: { id: COMPLETED },
			object: { objectType: 'Activity', id: `https://example.org/lesson/${lesson.id}` },
			stored: new Date().toISOString(),
			verified_actor_id: learnerId,
			lessonId: lesson.id,
		})
		attestationId = await fx.object('attestation', {
			learnerId,
			lessonId: lesson.id,
			courseId: courses[0].id,
			regulationSlug: 'AVG',
			score: 80,
		})
		// A drafted attestation may still be corrected.
		const corrected = await api.patch(`${OBJECTS}/learniq/attestation/${attestationId}`, {
			data: { courseId: courses[1].id },
		})
		expect(corrected.status(), await corrected.text()).toBe(200)
	})

	test.afterAll(async () => {
		await api?.dispose()
		await fx.teardown()
	})

	// @e2e openspec/specs/compliance-audit/spec.md#scenario-a-drafted-attestation-is-corrected
	// @e2e openspec/specs/compliance-audit/spec.md#scenario-a-compliance-officer-edits-a-signed-attestation
	// @e2e openspec/specs/compliance-audit/spec.md#scenario-an-administrator-edits-a-signed-attestation
	test('after signing, neither the officer nor an administrator can change the evidence', async () => {
		const signed = await api.post(`${OBJECTS}/${attestationId}/transition`, { data: { action: 'sign' } })
		expect(signed.status(), await signed.text()).toBe(200)
		const before = await fx.read('attestation', attestationId)
		expect(before.lifecycle).toBe('signed')
		expect(before.signedAt, 'signing stamps signedAt').toBeTruthy()
		expect(before.signature, 'signing writes a signature').toBeTruthy()

		const byOfficer = await api.patch(`${OBJECTS}/learniq/attestation/${attestationId}`, { data: { score: 10 } })
		expect(byOfficer.status()).toBeGreaterThanOrEqual(400)
		const admin = await fx.api()
		const byAdmin = await admin.patch(`${OBJECTS}/learniq/attestation/${attestationId}`, { data: { learnerId: 'someone-else' } })
		expect(byAdmin.status()).toBeGreaterThanOrEqual(400)

		const after = await fx.read('attestation', attestationId)
		expect(after.score).toBe(before.score)
		expect(after.learnerId).toBe(learnerId)
		expect(after.signature).toBe(before.signature)
	})

	// @e2e openspec/specs/compliance-audit/spec.md#scenario-revoke-without-a-reason
	// @e2e openspec/specs/compliance-audit/spec.md#scenario-revoke-with-a-reason
	test('revoking needs a reason and changes nothing else', async () => {
		const before = await fx.read('attestation', attestationId)
		const bare = await api.post(`${OBJECTS}/${attestationId}/transition`, { data: { action: 'revoke' } })
		expect(bare.status()).toBeGreaterThanOrEqual(400)
		expect((await fx.read('attestation', attestationId)).lifecycle).toBe('signed')

		const reason = 'Signed for the wrong course'
		const revoked = await api.post(`${OBJECTS}/${attestationId}/transition`, {
			data: { action: 'revoke', data: { revocationReason: reason } },
		})
		expect(revoked.status(), await revoked.text()).toBe(200)
		const after = await fx.read('attestation', attestationId)
		expect(after.lifecycle).toBe('revoked')
		expect(after.revocationReason).toBe(reason)
		expect(after.signature).toBe(before.signature)
		expect(after.score).toBe(before.score)
	})
})
