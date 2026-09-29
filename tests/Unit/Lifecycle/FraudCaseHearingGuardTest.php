<?php

/**
 * Learniq FraudCaseHearingGuard unit tests.
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
 * @spec openspec/specs/exam-board/spec.md#requirement-persist-exam-board-domain-objects-in-openregister
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Tests\Support\GuardVerdicts;
use OCA\Learniq\Lifecycle\FraudCaseHearingGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the FraudCaseHearingGuard (reported → hearing-scheduled).
 */
class FraudCaseHearingGuardTest extends TestCase {

	use GuardVerdicts;

	/**
	 * Build a guard with a stub logger.
	 *
	 * @return FraudCaseHearingGuard
	 */
	private function makeGuard(): FraudCaseHearingGuard {
		return new FraudCaseHearingGuard($this->createMock(LoggerInterface::class));
	}//end makeGuard()

	/**
	 * A hearingDate present allows the transition.
	 *
	 * @return void
	 */
	public function testHearingDateSetAllowsTransition(): void {
		$object = ['id' => 'case-1', 'hearingDate' => '2026-08-01T10:00:00Z', 'lifecycle' => 'hearing-scheduled'];

		self::assertAllowed($this->makeGuard()->check($object, 'scheduleHearing', ''));

	}//end testHearingDateSetAllowsTransition()

	/**
	 * A missing hearingDate blocks the transition.
	 *
	 * @return void
	 */
	public function testMissingHearingDateBlocks(): void {
		$object = ['id' => 'case-1', 'lifecycle' => 'hearing-scheduled'];

		self::assertDenied($this->makeGuard()->check($object, 'scheduleHearing', ''));

	}//end testMissingHearingDateBlocks()

	/**
	 * A blank hearingDate blocks the transition.
	 *
	 * @return void
	 */
	public function testBlankHearingDateBlocks(): void {
		$object = ['id' => 'case-1', 'hearingDate' => '   ', 'lifecycle' => 'hearing-scheduled'];

		self::assertDenied($this->makeGuard()->check($object, 'scheduleHearing', ''));

	}//end testBlankHearingDateBlocks()
}//end class
