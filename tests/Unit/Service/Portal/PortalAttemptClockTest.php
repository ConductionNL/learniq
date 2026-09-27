<?php

/**
 * Learniq PortalAttemptClock unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Portal
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
 * @spec openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Portal;

use DateTimeImmutable;
use OCA\Learniq\Service\Portal\PortalAttemptClock;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PortalAttemptClock.
 */
class PortalAttemptClockTest extends TestCase {

	/**
	 * An extra-time accommodation row.
	 *
	 * @param float|null $value The percentage.
	 * @param string|null $assessmentId The test it is for, null for all.
	 * @param string $lifecycle Its state.
	 * @param string $kind Its kind.
	 *
	 * @return array<string, mixed>
	 */
	private function accommodation(?float $value, ?string $assessmentId = null, string $lifecycle = 'active', string $kind = 'extra-time-percentage'): array {
		return ['accommodationKind' => $kind, 'value' => $value, 'assessmentId' => $assessmentId, 'lifecycle' => $lifecycle];
	}//end accommodation()

	/**
	 * 30 minutes with 25% extra time from 09:00 ends at 09:37:30.
	 *
	 * @return void
	 */
	public function testExtraTimeMovesTheDeadline(): void {
		$clock = new PortalAttemptClock();
		$deadline = $clock->deadline(
			attempt: ['startedAt' => '2026-10-01T09:00:00+02:00'],
			exam: ['timeLimitMinutes' => 30],
			extraPercentage: 25.0
		);

		self::assertSame('2026-10-01T09:37:30+02:00', $deadline?->format(DATE_ATOM));
		self::assertSame(7.5, $clock->extraMinutes(exam: ['timeLimitMinutes' => 30], extraPercentage: 25.0));
	}//end testExtraTimeMovesTheDeadline()

	/**
	 * An untimed test has no deadline and no extra minutes.
	 *
	 * @return void
	 */
	public function testAnUntimedTestHasNoDeadline(): void {
		$clock = new PortalAttemptClock();

		self::assertNull($clock->deadline(attempt: ['startedAt' => '2026-10-01T09:00:00+02:00'], exam: ['timeLimitMinutes' => null], extraPercentage: 25.0));
		self::assertNull($clock->deadline(attempt: ['startedAt' => '2026-10-01T09:00:00+02:00'], exam: [], extraPercentage: 0.0));
		self::assertNull($clock->extraMinutes(exam: ['timeLimitMinutes' => null], extraPercentage: 25.0));
		self::assertNull($clock->extraMinutes(exam: ['timeLimitMinutes' => 30], extraPercentage: 0.0));
	}//end testAnUntimedTestHasNoDeadline()

	/**
	 * Without startedAt the attempt's creation time is the start.
	 *
	 * @return void
	 */
	public function testTheCreationTimeIsTheFallbackStart(): void {
		$deadline = (new PortalAttemptClock())->deadline(
			attempt: ['@self' => ['created' => '2026-10-01T09:00:00+00:00']],
			exam: ['timeLimitMinutes' => 10],
			extraPercentage: 0.0
		);

		self::assertSame('2026-10-01T09:10:00+00:00', $deadline?->format(DATE_ATOM));
	}//end testTheCreationTimeIsTheFallbackStart()

	/**
	 * A test-specific accommodation wins over a generic one; only approved or
	 * active extra-time rows count; the largest of one kind applies; the value
	 * is clamped.
	 *
	 * @return void
	 */
	public function testTheRightAccommodationApplies(): void {
		$clock = new PortalAttemptClock();

		self::assertSame(0.0, $clock->extraTimePercentage(accommodations: [], assessmentId: 'exam-1'));
		self::assertSame(25.0, $clock->extraTimePercentage(accommodations: [$this->accommodation(25.0)], assessmentId: 'exam-1'));
		self::assertSame(
			10.0,
			$clock->extraTimePercentage(accommodations: [$this->accommodation(50.0), $this->accommodation(10.0, 'exam-1')], assessmentId: 'exam-1')
		);
		self::assertSame(
			50.0,
			$clock->extraTimePercentage(accommodations: [$this->accommodation(50.0), $this->accommodation(10.0, 'exam-2')], assessmentId: 'exam-1')
		);
		self::assertSame(
			30.0,
			$clock->extraTimePercentage(accommodations: [$this->accommodation(20.0), $this->accommodation(30.0)], assessmentId: 'exam-1')
		);
		self::assertSame(
			0.0,
			$clock->extraTimePercentage(
				accommodations: [
					$this->accommodation(25.0, null, 'requested'),
					$this->accommodation(25.0, null, 'revoked'),
					$this->accommodation(25.0, null, 'active', 'separate-room'),
					$this->accommodation(null),
				],
				assessmentId: 'exam-1'
			)
		);
		self::assertSame(300.0, $clock->extraTimePercentage(accommodations: [$this->accommodation(900.0)], assessmentId: 'exam-1'));
	}//end testTheRightAccommodationApplies()

	/**
	 * The attempt closes 30 seconds after the deadline, not before.
	 *
	 * @return void
	 */
	public function testTheAttemptClosesAfterTheGrace(): void {
		$clock = new PortalAttemptClock();
		$deadline = new DateTimeImmutable('2026-10-01T09:30:00+00:00');

		self::assertFalse($clock->isClosed(deadline: $deadline, now: new DateTimeImmutable('2026-10-01T09:30:10+00:00')));
		self::assertFalse($clock->isClosed(deadline: $deadline, now: new DateTimeImmutable('2026-10-01T09:30:30+00:00')));
		self::assertTrue($clock->isClosed(deadline: $deadline, now: new DateTimeImmutable('2026-10-01T09:30:31+00:00')));
		self::assertFalse($clock->isClosed(deadline: null, now: new DateTimeImmutable('2030-01-01T00:00:00+00:00')));
	}//end testTheAttemptClosesAfterTheGrace()
}//end class
