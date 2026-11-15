<?php

/**
 * Tests for HourDayLines.
 *
 * The hours of a day are worked out on the server, never taken from the
 * student: these tests send a deliberately wrong `hours` and read the one
 * the times give.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
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
 * @spec openspec/changes/bpv-hours-per-day/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-holds-its-days
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\HourDayLines;
use PHPUnit\Framework\TestCase;

/**
 * The day lines of a week: hours per day, their sum, and what is refused.
 */
class HourDayLinesTest extends TestCase {

	/**
	 * A day line of week 41 of 2026.
	 *
	 * @param array<string, mixed> $extra Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private static function day(array $extra = []): array {
		return array_merge(
			['date' => '2026-10-05', 'startTime' => '08:00', 'endTime' => '16:30', 'breakMinutes' => 30, 'hours' => 99],
			$extra
		);
	}//end day()

	/**
	 * Monday 08.00 to 16.30 with 30 minutes break reads 8 hours, whatever
	 * the client said, and the week's total is the sum of its days.
	 *
	 * @return void
	 */
	public function testADayIsTheTimeBetweenStartAndEndLessTheBreak(): void {
		$lines = (new HourDayLines())->normalise(
			days: [self::day(), self::day(extra: ['date' => '2026-10-06', 'startTime' => '09:00', 'endTime' => '12:15', 'breakMinutes' => 0])],
			isoWeek: '2026-W41'
		);

		self::assertNull($lines['refusal']);
		self::assertSame(8.0, $lines['days'][0]['hours']);
		self::assertSame(3.25, $lines['days'][1]['hours']);
		self::assertSame(11.25, $lines['total']);
	}//end testADayIsTheTimeBetweenStartAndEndLessTheBreak()

	/**
	 * The lines come back in date order and keep what the student wrote,
	 * each work process once.
	 *
	 * @return void
	 */
	public function testTheLinesAreSortedAndKeepTheirText(): void {
		$lines = (new HourDayLines())->normalise(
			days: [
				self::day(extra: ['date' => '2026-10-07', 'description' => ' Tegels gezet ', 'werkprocesCodes' => ['B1-K1-W2', 'B1-K1-W2', '', 7], 'photoRef' => 'files/1.jpg']),
				self::day(extra: ['breakMinutes' => null]),
			],
			isoWeek: '2026-W41'
		);

		self::assertSame(['2026-10-05', '2026-10-07'], array_column($lines['days'], 'date'));
		self::assertSame(0, $lines['days'][0]['breakMinutes']);
		self::assertSame(8.5, $lines['days'][0]['hours']);
		self::assertArrayNotHasKey('description', $lines['days'][0]);
		self::assertSame('Tegels gezet', $lines['days'][1]['description']);
		self::assertSame(['B1-K1-W2'], $lines['days'][1]['werkprocesCodes']);
		self::assertSame('files/1.jpg', $lines['days'][1]['photoRef']);
	}//end testTheLinesAreSortedAndKeepTheirText()

	/**
	 * Each way a line can be wrong is refused with its own reason.
	 *
	 * @return void
	 */
	public function testEveryWrongLineIsRefusedWithItsReason(): void {
		$cases = [
			HourDayLines::DATE_OUTSIDE_WEEK => [[self::day(extra: ['date' => '2026-10-12'])], '2026-W41'],
			HourDayLines::TIMES_INVALID => [[self::day(extra: ['endTime' => '08:00'])], '2026-W41'],
			HourDayLines::BREAK_TOO_LONG => [[self::day(extra: ['breakMinutes' => 510])], '2026-W41'],
			HourDayLines::DUPLICATE_DAY => [[self::day(), self::day()], '2026-W41'],
			HourDayLines::LINE_INVALID => [['monday'], '2026-W41'],
		];
		foreach ($cases as $reason => [$days, $week]) {
			$lines = (new HourDayLines())->normalise(days: $days, isoWeek: $week);
			self::assertSame($reason, $lines['refusal'], $reason);
			self::assertSame([], $lines['days']);
			self::assertSame(0.0, $lines['total']);
		}

		$timeCases = [['startTime' => '8:00'], ['endTime' => '24:00'], ['startTime' => null]];
		foreach ($timeCases as $extra) {
			self::assertSame(HourDayLines::TIMES_INVALID, (new HourDayLines())->normalise(days: [self::day(extra: $extra)], isoWeek: '2026-W41')['refusal']);
		}

		self::assertSame(HourDayLines::BREAK_TOO_LONG, (new HourDayLines())->normalise(days: [self::day(extra: ['breakMinutes' => -5])], isoWeek: '2026-W41')['refusal']);
		self::assertSame(HourDayLines::BREAK_TOO_LONG, (new HourDayLines())->normalise(days: [self::day(extra: ['breakMinutes' => '30'])], isoWeek: '2026-W41')['refusal']);
	}//end testEveryWrongLineIsRefusedWithItsReason()

	/**
	 * A week that is not a readable ISO week holds no valid date, so no line fits.
	 *
	 * @return void
	 */
	public function testAnUnreadableWeekFitsNoDay(): void {
		foreach (['2026-41', '2026-W54', '2026-W00', '2025-W53'] as $week) {
			self::assertSame(HourDayLines::DATE_OUTSIDE_WEEK, (new HourDayLines())->normalise(days: [self::day()], isoWeek: $week)['refusal'], $week);
		}

		// Week 53 exists in 2026: Monday 28 December.
		self::assertNull((new HourDayLines())->normalise(days: [self::day(extra: ['date' => '2026-12-28'])], isoWeek: '2026-W53')['refusal']);
	}//end testAnUnreadableWeekFitsNoDay()

	/**
	 * The question of a return lands on the line of the day it names, and
	 * no other line changes; a day the week does not hold answers null.
	 *
	 * @return void
	 */
	public function testAQuestionLandsOnTheDayItNames(): void {
		$days = [self::day(), self::day(extra: ['date' => '2026-10-06']), 'garbage'];

		$lines = HourDayLines::withQuestion(days: $days, date: '2026-10-06', question: 'Tandarts om 14.00?');

		self::assertNotNull($lines);
		self::assertCount(2, $lines);
		self::assertArrayNotHasKey('returnedQuestion', $lines[0]);
		self::assertSame('Tandarts om 14.00?', $lines[1]['returnedQuestion']);
		self::assertNull(HourDayLines::withQuestion(days: $days, date: '2026-10-09', question: 'x'));
	}//end testAQuestionLandsOnTheDayItNames()
}//end class
