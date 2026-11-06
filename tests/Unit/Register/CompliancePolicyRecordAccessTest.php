<?php

/**
 * Learniq compliance policy record access-rule test.
 *
 * The school's AI locality policy, its accessibility statement, the statement's
 * known limitations and its per-criterion conformance results are written by
 * the compliance officer (admins pass OpenRegister's bypass) and read by every
 * signed-in user: the statement page is open to everyone, and every AI feature
 * reads the locality policy on the user's behalf.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Register
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/ai-locality-guarantee/spec.md#scenario-a-non-privileged-user-cannot-change-the-policy
 * @spec openspec/specs/accessibility-conformance/spec.md#scenario-domain-objects-persist-with-the-correct-lifecycles-and-rbac
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Register;

use PHPUnit\Framework\TestCase;

/**
 * Asserts the enforced access of the compliance policy records.
 */
class CompliancePolicyRecordAccessTest extends TestCase {

	/**
	 * The only group that writes these records.
	 */
	private const WRITERS = ['compliance-officers'];

	/**
	 * The records only the compliance officer writes.
	 */
	private const POLICY_RECORDS = [
		'SovereigntyPolicy',
		'AccessibilityStatement',
		'AccessibilityLimitation',
		'AccessibilityCriterionResult',
	];

	/**
	 * The shipped schemas.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function schemas(): array {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'),
			true
		);

		return $register['components']['schemas'];
	}//end schemas()

	/**
	 * Every record declares an authorization block. Without one OpenRegister
	 * falls back to the register cascade, which lets four staff groups write it.
	 *
	 * @return void
	 */
	public function testEveryPolicyRecordDeclaresAnAuthorizationBlock(): void {
		$schemas = $this->schemas();
		foreach (self::POLICY_RECORDS as $name) {
			$this->assertNotEmpty(actual: $schemas[$name]['authorization'] ?? [], message: $name);
		}
	}//end testEveryPolicyRecordDeclaresAnAuthorizationBlock()

	/**
	 * A learner, an instructor or any other signed-in user without the
	 * compliance officer role cannot create, update or delete these records.
	 *
	 * @return void
	 */
	public function testOnlyTheComplianceOfficerWrites(): void {
		$schemas = $this->schemas();
		foreach (self::POLICY_RECORDS as $name) {
			$authorization = $schemas[$name]['authorization'] ?? [];
			$this->assertSame(expected: self::WRITERS, actual: $authorization['create'] ?? null, message: $name . ' create');
			$this->assertSame(expected: self::WRITERS, actual: $authorization['update'] ?? null, message: $name . ' update');
			$this->assertArrayNotHasKey(key: 'delete', array: $authorization, message: $name . ' delete');
		}
	}//end testOnlyTheComplianceOfficerWrites()

	/**
	 * Every signed-in user reads the locality policy, the limitations and the
	 * conformance results, because the pages and services that show them run
	 * as that user.
	 *
	 * @return void
	 */
	public function testEverySignedInUserReadsThePolicyLimitationsAndResults(): void {
		$schemas = $this->schemas();
		foreach (['SovereigntyPolicy', 'AccessibilityLimitation', 'AccessibilityCriterionResult'] as $name) {
			$this->assertSame(expected: ['authenticated'], actual: $schemas[$name]['authorization']['read'] ?? null, message: $name);
		}
	}//end testEverySignedInUserReadsThePolicyLimitationsAndResults()

	/**
	 * A draft or archived statement is read by the compliance officer only;
	 * every signed-in user reads the published one.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/accessibility-conformance/spec.md#scenario-statement-page-renders-every-mandatory-field
	 */
	public function testEverySignedInUserReadsOnlyThePublishedStatement(): void {
		$this->assertSame(
			expected: [...self::WRITERS, ['group' => 'authenticated', 'match' => ['lifecycle' => ['$eq' => 'published']]]],
			actual: $this->schemas()['AccessibilityStatement']['authorization']['read'] ?? null
		);
	}//end testEverySignedInUserReadsOnlyThePublishedStatement()
}//end class
