<?php

/**
 * Learniq FraudCaseDecisionGuard unit tests.
 *
 * Covers the verdict+rationale precondition, the additional capped-sanction
 * precondition when verdict=fraud-proven, and the decidedAt/appealDeadline
 * (decidedAt + 42 days) stamp on success.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
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
 * @spec openspec/specs/exam-board/spec.md#requirement-fraudcase-decisions-require-a-verdict-rationale-and-when-fraud-is-proven-a-capped-sanction
 * @spec openspec/specs/exam-board/spec.md#requirement-a-decided-fraudcase-stamps-a-42-day-appeal-deadline
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\FraudCaseDecisionGuard;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the FraudCaseDecisionGuard (heard → decided).
 */
class FraudCaseDecisionGuardTest extends TestCase {

	/**
	 * Build a guard with a stub logger.
	 *
	 * @return FraudCaseDecisionGuard
	 */
	private function makeGuard(): FraudCaseDecisionGuard {
		return new FraudCaseDecisionGuard($this->createMock(LoggerInterface::class));
	}//end makeGuard()

	/**
	 * OpenRegister's registry refuses a guard that does not implement its interface.
	 *
	 * @return void
	 */
	public function testImplementsTheOpenRegisterGuardInterface(): void {
		self::assertInstanceOf(LifecycleGuardInterface::class, $this->makeGuard());

	}//end testImplementsTheOpenRegisterGuardInterface()

	/**
	 * Missing verdict and/or decisionRationale blocks decide.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/exam-board/spec.md#scenario-decide-blocked-without-a-verdict-and-rationale
	 */
	public function testMissingVerdictOrRationaleBlocks(): void {
		$object = ['id' => 'case-1', 'lifecycle' => 'decided'];
		self::assertFalse($this->makeGuard()->check($object, 'decide', 'board-1')->isAllowed());

		$object = ['id' => 'case-1', 'lifecycle' => 'decided', 'verdict' => 'unfounded'];
		self::assertFalse($this->makeGuard()->check($object, 'decide', 'board-1')->isAllowed());

		$object = ['id' => 'case-1', 'lifecycle' => 'decided', 'decisionRationale' => 'No evidence found.'];
		self::assertFalse($this->makeGuard()->check($object, 'decide', 'board-1')->isAllowed());

	}//end testMissingVerdictOrRationaleBlocks()

	/**
	 * verdict=unfounded with a rationale succeeds, no sanction fields required.
	 * decidedAt/appealDeadline are FraudCaseAppealDeadlineAction's write (learniq#983).
	 *
	 * @return void
	 */
	public function testUnfoundedVerdictWithRationaleSucceeds(): void {
		$object = [
			'id' => 'case-1',
			'lifecycle' => 'decided',
			'verdict' => 'unfounded',
			'decisionRationale' => 'No evidence found.',
		];

		self::assertTrue($this->makeGuard()->check($object, 'decide', 'board-1')->isAllowed());

	}//end testUnfoundedVerdictWithRationaleSucceeds()

	/**
	 * verdict=fraud-proven without sanction fields blocks decide.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/exam-board/spec.md#scenario-a-fraud-proven-verdict-requires-a-capped-sanction
	 */
	public function testFraudProvenWithoutSanctionBlocks(): void {
		$object = [
			'id' => 'case-1',
			'lifecycle' => 'decided',
			'verdict' => 'fraud-proven',
			'decisionRationale' => 'Plagiarism confirmed via Turnitin report.',
		];

		self::assertFalse($this->makeGuard()->check($object, 'decide', 'board-1')->isAllowed());

	}//end testFraudProvenWithoutSanctionBlocks()

	/**
	 * verdict=fraud-proven with a sanctionDurationMonths above the 12-month cap blocks decide.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/exam-board/spec.md#scenario-a-fraud-proven-verdict-requires-a-capped-sanction
	 */
	public function testFraudProvenWithSanctionDurationOverCapBlocks(): void {
		$object = [
			'id' => 'case-1',
			'lifecycle' => 'decided',
			'verdict' => 'fraud-proven',
			'decisionRationale' => 'Plagiarism confirmed.',
			'sanctionType' => 'suspension',
			'sanctionDurationMonths' => 13,
			'sanctionScope' => 'course',
		];

		self::assertFalse($this->makeGuard()->check($object, 'decide', 'board-1')->isAllowed());

	}//end testFraudProvenWithSanctionDurationOverCapBlocks()

	/**
	 * verdict=fraud-proven with a complete, valid sanction succeeds. The appeal
	 * deadline stamp is covered in tests/Unit/Lifecycle/Action/FraudCaseAppealDeadlineActionTest.php.
	 *
	 * @return void
	 */
	public function testFraudProvenWithValidSanctionSucceeds(): void {
		$object = [
			'id' => 'case-1',
			'lifecycle' => 'decided',
			'verdict' => 'fraud-proven',
			'decisionRationale' => 'Plagiarism confirmed via Turnitin report.',
			'sanctionType' => 'suspension',
			'sanctionDurationMonths' => 6,
			'sanctionScope' => 'course',
		];

		self::assertTrue($this->makeGuard()->check($object, 'decide', 'board-1')->isAllowed());

	}//end testFraudProvenWithValidSanctionSucceeds()

	/**
	 * A sanctionDurationMonths of exactly 12 (the cap boundary) is allowed.
	 *
	 * @return void
	 */
	public function testSanctionDurationAtCapBoundaryAllowed(): void {
		$object = [
			'id' => 'case-1',
			'lifecycle' => 'decided',
			'verdict' => 'fraud-proven',
			'decisionRationale' => 'Repeated, severe plagiarism.',
			'sanctionType' => 'exclusion',
			'sanctionDurationMonths' => 12,
			'sanctionScope' => 'programme',
		];

		self::assertTrue($this->makeGuard()->check($object, 'decide', 'board-1')->isAllowed());

	}//end testSanctionDurationAtCapBoundaryAllowed()
}//end class
