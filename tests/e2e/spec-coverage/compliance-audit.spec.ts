/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — compliance-audit, the wedge core.
 *
 * Covers (UI-observable surface):
 *   @e2e openspec/specs/compliance-audit/spec.md#attestation-captured-with-provenance
 *   @e2e openspec/specs/compliance-audit/spec.md#audit-pack-exported-for-a-regulation
 *   @e2e openspec/specs/compliance-audit/spec.md#verified-classroom-training-turns-coverage-green
 *
 * The signing, the append-only evidence log and the coverage arithmetic are
 * backend behaviours with PHPUnit cover, annotated `@e2e exclude` in the spec.
 *
 * This file exists because the audit pack is the thing the buyer pays for and
 * the word `audit-pack` appeared in zero e2e files. The attestation and
 * regulation surfaces appeared only in generic page sweeps and screenshot
 * runs, never in a behavioural assertion.
 *
 * The admin session comes from the global setup.
 */
import { expect, test } from '../fixtures.ts'
import {
	openAndExpectNoFatal,
	openAndExpectSchemaLoads,
	watchConsole,
} from './wedge-helpers.ts'

test.describe('compliance-audit — attestations and regulations', () => {
	// @e2e openspec/specs/compliance-audit/spec.md#attestation-captured-with-provenance
	test('the attestations index reads the Attestation schema', async ({
		loggedInPage: page,
	}) => {
		await openAndExpectSchemaLoads(
			page,
			'/compliance/attestations',
			'attestation',
		)
	})

	// @e2e openspec/specs/compliance-audit/spec.md#verified-classroom-training-turns-coverage-green
	test('the regulations index reads the Regulation schema', async ({
		loggedInPage: page,
	}) => {
		await openAndExpectSchemaLoads(page, '/compliance/regulations', 'regulation')
	})

	// @e2e openspec/specs/compliance-audit/spec.md#verified-classroom-training-turns-coverage-green
	//
	// This one failed every run against the old race-a-fixed-window helper and
	// looked like a real defect: a page painting an empty list without ever
	// fetching. It was not. It is simply the slowest of these surfaces to issue
	// its object call, so it lost the race first and lost it consistently,
	// which reads exactly like determinism. Under the collect-then-assert
	// helper it passes. Worth remembering before calling a slow surface broken.
	test('the external-training index reads its schema', async ({
		loggedInPage: page,
	}) => {
		await openAndExpectSchemaLoads(
			page,
			'/compliance/external-training',
			'external-training-record',
		)
	})
})

test.describe('compliance-audit — the audit pack', () => {
	// @e2e openspec/specs/compliance-audit/spec.md#audit-pack-exported-for-a-regulation
	test('the export wizard renders without a fatal error', async ({
		loggedInPage: page,
	}) => {
		await openAndExpectNoFatal(page, '/compliance/export')
	})

	// @e2e openspec/specs/compliance-audit/spec.md#audit-pack-exported-for-a-regulation
	test('the export wizard offers a control that starts an export', async ({
		loggedInPage: page,
	}) => {
		const errors = watchConsole(page)

		await page.goto('/index.php/apps/learniq/compliance/export', {
			waitUntil: 'domcontentloaded',
		})
		await page.waitForSelector('body', { timeout: 15_000 })

		// An auditor asks for the pack once a year. The wizard must present a
		// control that begins one, otherwise the wedge's headline deliverable
		// is a page that describes itself and does nothing.
		const startControl = page
			.getByRole('button', { name: /export|download|generate|next|start/i })
			.first()

		await expect(
			startControl,
			'the export wizard renders no control that starts an export',
		).toBeVisible({ timeout: 10_000 })

		const fatal = errors()
		expect(fatal, `export wizard raised: ${fatal.join(' | ')}`).toHaveLength(0)
	})

	// @e2e openspec/specs/compliance-audit/spec.md#audit-pack-exported-for-a-regulation
	test('the compliance overview renders without a fatal error', async ({
		loggedInPage: page,
	}) => {
		await openAndExpectNoFatal(page, '/compliance-overview')
	})
})
