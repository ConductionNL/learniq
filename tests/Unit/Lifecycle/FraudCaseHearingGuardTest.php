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

	/**
	 * The register's FraudCase lifecycle runs reported, hearing-scheduled
	 * (this guard on the way in) and then heard.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/exam-board/spec.md#requirement-a-fraud-case-moves-from-report-to-a-scheduled-and-held-hearing
	 */
	public function testAHearingIsScheduledAndThenHeld(): void {
		$register    = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/learniq_register.json'), true);
		$lifecycle   = $register['components']['schemas']['FraudCase']['x-openregister-lifecycle'];
		$transitions = $lifecycle['transitions'];

		self::assertSame('reported', $lifecycle['initial']);
		self::assertSame(['reported', 'hearing-scheduled'], [$transitions['scheduleHearing']['from'], $transitions['scheduleHearing']['to']]);
		self::assertSame(FraudCaseHearingGuard::class, $transitions['scheduleHearing']['requires']);
		self::assertSame(['hearingDate'], array_column($transitions['scheduleHearing']['inputs'], 'field'));
		self::assertSame(['hearing-scheduled', 'heard'], [$transitions['holdHearing']['from'], $transitions['holdHearing']['to']]);

		$scheduled = ['id' => 'case-1', 'hearingDate' => '2027-03-14T10:00:00Z', 'lifecycle' => 'hearing-scheduled'];
		self::assertAllowed($this->makeGuard()->check($scheduled, 'scheduleHearing', 'board-1'));
	}//end testAHearingIsScheduledAndThenHeld()

	/**
	 * Scheduling without a date is refused with a message that the hearing needs one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/exam-board/spec.md#requirement-a-fraud-case-moves-from-report-to-a-scheduled-and-held-hearing
	 */
	public function testAHearingWithoutADateIsRefusedInWords(): void {
		$verdict = $this->makeGuard()->check(['id' => 'case-1', 'lifecycle' => 'hearing-scheduled'], 'scheduleHearing', 'board-1');

		self::assertFalse($verdict->isAllowed());
		self::assertSame('A hearing date is needed to schedule the hearing.', $verdict->getMessage());
	}//end testAHearingWithoutADateIsRefusedInWords()
}//end class
