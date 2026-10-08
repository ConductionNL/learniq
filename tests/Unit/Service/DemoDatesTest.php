<?php

/**
 * Tests for the demo dates (demo-dates-follow-the-load-week).
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Service\DemoDates;
use PHPUnit\Framework\TestCase;

/**
 * The boards' Monday lands on the Monday of the load's week, and every
 * weekday and time keeps its pattern.
 *
 * @spec openspec/changes/demo-dates-follow-the-load-week/specs/example-sets/spec.md#requirement-a-load-moves-every-date-to-the-week-it-runs-in
 */
class DemoDatesTest extends TestCase {

	/**
	 * Dutch weekday names, ISO number to name.
	 */
	private const WEEKDAYS = [1 => 'maandag', 2 => 'dinsdag', 3 => 'woensdag', 4 => 'donderdag', 5 => 'vrijdag', 6 => 'zaterdag', 7 => 'zondag'];

	/**
	 * The offset is the whole weeks from the boards' Monday to the load's Monday.
	 *
	 * @return void
	 */
	public function testTheOffsetIsWholeWeeksToTheLoadsMonday(): void {
		self::assertSame(0, (new DemoDates())->offsetFor(new DateTimeImmutable('2026-10-05T07:00:00+02:00')));
		self::assertSame(0, (new DemoDates())->offsetFor(new DateTimeImmutable('2026-10-11T23:00:00+02:00')), 'Sunday is still the boards\' week');
		self::assertSame(7, (new DemoDates())->offsetFor(new DateTimeImmutable('2026-10-12T00:30:00+02:00')));
		self::assertSame(56, (new DemoDates())->offsetFor(new DateTimeImmutable('2026-12-02T12:00:00+01:00')));
		self::assertSame(-7, (new DemoDates())->offsetFor(new DateTimeImmutable('2026-09-30T12:00:00+02:00')));
		// 23:30 UTC on Sunday 11 October is already Monday 12 October in Amsterdam.
		self::assertSame(7, (new DemoDates())->offsetFor(new DateTimeImmutable('2026-10-11T22:30:00+00:00')));
	}//end testTheOffsetIsWholeWeeksToTheLoadsMonday()

	/**
	 * Every kind of date the sets carry moves, and nothing else does.
	 *
	 * @return void
	 */
	public function testEveryKindOfDateMovesAndNothingElse(): void {
		$cases = [
			'2026-10-05'                                => '2026-10-26',
			// Summer time ends on 25 October: 08.30 stays 08.30.
			'2026-10-05T08:30:00+02:00'                 => '2026-10-26T08:30:00+01:00',
			'2026-10-05T08:30'                          => '2026-10-26T08:30',
			'2026-W41'                                  => '2026-W44',
			'Groep 7, maandag 5 oktober 2026'           => 'Groep 7, maandag 26 oktober 2026',
			'29-10-2026 19:48-19:58, Juf Esra'          => '19-11-2026 19:48-19:58, Juf Esra',
			'3, 4 en 10 november'                       => '24, 25 november en 1 december',
			'13 en 15 okt'                              => '3 en 5 nov',
			'Geldig tot 12 maart 2028'                  => 'Geldig tot 2 april 2028',
			'Bevestiging uiterlijk dinsdag 6 oktober'   => 'Bevestiging uiterlijk dinsdag 27 oktober',
			'2026-2027'                                 => '2026-2027',
			'vo-grade-entry-1471'                       => 'vo-grade-entry-1471',
			'ee020019-0000-4000-8000-000000001471'      => 'ee020019-0000-4000-8000-000000001471',
			'groep 7'                                   => 'groep 7',
		];
		foreach ($cases as $before => $after) {
			self::assertSame($after, (new DemoDates())->shiftString($before, 21), $before);
		}

		self::assertSame('5, 6 en 12 januari', (new DemoDates())->shiftString('3, 4 en 10 november', 63), 'a phrase without a year crosses into the next year');
		self::assertSame(['a' => ['2026-10-12', 3, true, null]], (new DemoDates())->shift(['a' => ['2026-10-05', 3, true, null]], 7));
		self::assertSame('2026-10-05', (new DemoDates())->shiftString('2026-10-05', 0));
	}//end testEveryKindOfDateMovesAndNothingElse()

	/**
	 * A load in another week gives the real example sets the same weekday
	 * and time pattern: every date-time keeps its weekday and wall-clock
	 * time, every lesson title names the weekday of its own start, and
	 * every hour week stays a week.
	 *
	 * @return void
	 */
	public function testALoadInAnotherWeekKeepsTheWeekdayPattern(): void {
		$zone = new DateTimeZone('Europe/Amsterdam');
		foreach (['po', 'vo', 'mbo', 'training'] as $set) {
			$objects = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/profiles/' . $set . '.json'), true)['x-openregister']['seedData']['objects'];
			foreach ([21, 63, -7] as $days) {
				$moved = (new DemoDates())->shift($objects, $days);
				foreach ($objects as $schema => $rows) {
					foreach ($rows as $index => $row) {
						foreach ($row as $field => $value) {
							if (is_string($value) === false || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value) !== 1) {
								continue;
							}

							$before = (new DateTimeImmutable($value))->setTimezone($zone);
							$after  = (new DateTimeImmutable($moved[$schema][$index][$field]))->setTimezone($zone);
							self::assertSame($before->format('N H:i'), $after->format('N H:i'), $set . ' ' . $schema . '.' . $field);
							self::assertSame($days, (int)round(($after->setTime(0, 0)->getTimestamp() - $before->setTime(0, 0)->getTimestamp()) / 86400), $set . ' ' . $schema . '.' . $field);
						}

						// A lesson titled "Groep 7, maandag 5 oktober 2026" still names the weekday of its start.
						if ($schema === 'session' && isset($row['startsAt'], $row['title']) === true
							&& preg_match('/(maandag|dinsdag|woensdag|donderdag|vrijdag) (\d{1,2}) /', (string)$moved[$schema][$index]['title'], $m) === 1
						) {
							$start = (new DateTimeImmutable($moved[$schema][$index]['startsAt']))->setTimezone($zone);
							self::assertSame(self::WEEKDAYS[(int)$start->format('N')], $m[1], $set . ' ' . $row['title']);
							self::assertSame($start->format('j'), $m[2], $set . ' ' . $row['title']);
						}
					}//end foreach
				}//end foreach
			}//end foreach
		}//end foreach
	}//end testALoadInAnotherWeekKeepsTheWeekdayPattern()

	/**
	 * The portal declarations move too: a dated list item and a news date.
	 *
	 * @return void
	 */
	public function testThePortalDeclarationsCarryDatesThatMove(): void {
		$moved = 0;
		foreach (['po', 'vo', 'mbo', 'training'] as $set) {
			$declaration = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/portals/' . $set . '.json'), true);
			$shifted     = (new DemoDates())->shift($declaration['pages'], 7);
			$moved      += (int)($shifted !== $declaration['pages']);
			foreach ($declaration['news'] as $item) {
				if (isset($item['publishedAt']) === true) {
					self::assertNotSame($item['publishedAt'], (new DemoDates())->shift($item['publishedAt'], 7), $item['title']);
				}
			}
		}

		self::assertSame(4, $moved, 'every school site has a date on a page');
	}//end testThePortalDeclarationsCarryDatesThatMove()
}//end class
