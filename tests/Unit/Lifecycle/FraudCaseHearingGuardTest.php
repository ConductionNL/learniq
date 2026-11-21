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
		$json        = (string)file_get_contents(filename: __DIR__.'/../../../lib/Settings/learniq_register.json');
		$register    = json_decode(json: $json, associative: true);
		$lifecycle   = $register['components']['schemas']['FraudCase']['x-openregister-lifecycle'];
		$schedule    = $lifecycle['transitions']['scheduleHearing'];
		$hold        = $lifecycle['transitions']['holdHearing'];
		$scheduledAt = '2027-03-14T10:00:00Z';

		self::assertSame(expected: 'reported', actual: $lifecycle['initial']);
		self::assertSame(expected: ['reported', 'hearing-scheduled'], actual: [$schedule['from'], $schedule['to']]);
		self::assertSame(expected: FraudCaseHearingGuard::class, actual: $schedule['requires']);
		self::assertSame(expected: ['hearingDate'], actual: array_column(array: $schedule['inputs'], column_key: 'field'));
		self::assertSame(expected: ['hearing-scheduled', 'heard'], actual: [$hold['from'], $hold['to']]);

		$scheduled = ['id' => 'case-1', 'hearingDate' => $scheduledAt, 'lifecycle' => 'hearing-scheduled'];
		self::assertAllowed(verdict: $this->makeGuard()->check(object: $scheduled, action: 'scheduleHearing', userId: 'board-1'));
	}//end testAHearingIsScheduledAndThenHeld()

	/**
	 * Scheduling without a date is refused with a message that the hearing needs one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/exam-board/spec.md#requirement-a-fraud-case-moves-from-report-to-a-scheduled-and-held-hearing
	 */
	public function testAHearingWithoutADateIsRefusedInWords(): void {
		$object  = ['id' => 'case-1', 'lifecycle' => 'hearing-scheduled'];
		$verdict = $this->makeGuard()->check(object: $object, action: 'scheduleHearing', userId: 'board-1');

		self::assertFalse(condition: $verdict->isAllowed());
		self::assertSame(expected: 'A hearing date is needed to schedule the hearing.', actual: $verdict->getMessage());
	}//end testAHearingWithoutADateIsRefusedInWords()
}//end class
