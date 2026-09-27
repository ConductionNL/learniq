<?php

/**
 * Learniq FraudCaseInvalidationGuard unit tests.
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
 * @spec openspec/changes/exam-board-case-handling/specs/grading/spec.md#requirement-gradeentry-invalidate-is-a-guarded-terminal-transition
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Tests\Support\GuardVerdicts;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Lifecycle\FraudCaseInvalidationGuard;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the FraudCaseInvalidationGuard (GradeEntry concept → invalidated).
 */
class FraudCaseInvalidationGuardTest extends TestCase {

	use GuardVerdicts;

	/**
	 * Build a guard whose ObjectService::find() returns the given FraudCase (or null).
	 *
	 * @param array<string,mixed>|null $fraudCase FraudCase data, or null (not found).
	 *
	 * @return FraudCaseInvalidationGuard
	 */
	private function makeGuard(?array $fraudCase): FraudCaseInvalidationGuard {
		$objectService = $this->createMock(ObjectService::class);
		// OpenRegister's find() returns ?ObjectEntity, never an array.
		$objectService->method('find')->willReturn(
			$fraudCase === null ? null : OrEntityFactory::make($fraudCase, 'fraud-case')
		);

		return new FraudCaseInvalidationGuard($objectService, $this->createMock(LoggerInterface::class));
	}//end makeGuard()

	/**
	 * No fraudCaseId set → blocked (fail closed).
	 *
	 * @return void
	 */
	public function testNoFraudCaseIdBlocks(): void {
		$guard = $this->makeGuard(fraudCase: null);
		$object = ['id' => 'entry-1', 'lifecycle' => 'invalidated'];

		self::assertDenied($guard->check($object, 'invalidate', ''));

	}//end testNoFraudCaseIdBlocks()

	/**
	 * A linked FraudCase that is decided fraud-proven allows invalidate.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/exam-board-case-handling/specs/grading/spec.md#scenario-invalidate-succeeds-once-the-linked-case-is-decided-fraud-proven
	 */
	public function testDecidedFraudProvenAllowsInvalidate(): void {
		$guard = $this->makeGuard(fraudCase: ['id' => 'case-1', 'lifecycle' => 'decided', 'verdict' => 'fraud-proven']);
		$object = ['id' => 'entry-1', 'fraudCaseId' => 'case-1', 'lifecycle' => 'invalidated'];

		self::assertAllowed($guard->check($object, 'invalidate', ''));

	}//end testDecidedFraudProvenAllowsInvalidate()

	/**
	 * A FraudCase not yet decided blocks invalidate.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/exam-board-case-handling/specs/grading/spec.md#scenario-invalidate-is-blocked-without-a-fraud-proven-decision
	 */
	public function testNotYetDecidedBlocks(): void {
		foreach (['reported', 'hearing-scheduled', 'heard'] as $state) {
			$guard = $this->makeGuard(fraudCase: ['id' => 'case-1', 'lifecycle' => $state]);
			$object = ['id' => 'entry-1', 'fraudCaseId' => 'case-1', 'lifecycle' => 'invalidated'];

			self::assertDenied($guard->check($object, 'invalidate', ''), "state '{$state}' should block invalidate");
		}

	}//end testNotYetDecidedBlocks()

	/**
	 * A FraudCase decided unfounded blocks invalidate.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/exam-board-case-handling/specs/grading/spec.md#scenario-invalidate-is-blocked-without-a-fraud-proven-decision
	 */
	public function testDecidedUnfoundedBlocks(): void {
		$guard = $this->makeGuard(fraudCase: ['id' => 'case-1', 'lifecycle' => 'decided', 'verdict' => 'unfounded']);
		$object = ['id' => 'entry-1', 'fraudCaseId' => 'case-1', 'lifecycle' => 'invalidated'];

		self::assertDenied($guard->check($object, 'invalidate', ''));

	}//end testDecidedUnfoundedBlocks()

	/**
	 * An unresolvable fraudCaseId fails closed.
	 *
	 * @return void
	 */
	public function testUnresolvableFraudCaseFailsClosed(): void {
		$guard = $this->makeGuard(fraudCase: null);
		$object = ['id' => 'entry-1', 'fraudCaseId' => 'case-missing', 'lifecycle' => 'invalidated'];

		self::assertDenied($guard->check($object, 'invalidate', ''));

	}//end testUnresolvableFraudCaseFailsClosed()
}//end class
