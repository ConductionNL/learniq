<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\DemoDates;
use OCA\Learniq\Tests\Support\GoldenFile;
use PHPUnit\Framework\TestCase;

/**
 * Pins shiftString() and shift() against golden/DemoDates.json for every text
 * shape it moves (dates, local and zoned times, ISO weeks, Dutch prose with
 * day lists, ranges, short and full month names, years) over several shifts.
 *
 * @covers \OCA\Learniq\Service\DemoDates
 */
class DemoDatesGoldenTest extends TestCase {
	use GoldenFile;

	private const GOLDEN = __DIR__ . '/golden/DemoDates.json';

	private const TEXTS = [
		'2026-10-05',
		'2026-02-30',
		'2026-10-05T08:30',
		'2026-10-05T08:30:15',
		'2026-10-05T08:30:15.250',
		'2026-10-05T08:30:00Z',
		'2026-10-05T08:30:00+02:00',
		'2026-10-05T08:30:00+0200',
		'2026-03-27T23:30:00-05:00',
		'2026-10-25T02:30',
		'2026-13-45T08:30',
		'2026-W41',
		'2026-W53',
		'Groep 7, maandag 5 oktober 2026',
		'29-10-2026 19:48-19:58, Juf Esra',
		'31-02-2026 en 1-1-2027',
		'3, 4 en 10 november',
		'30 september - 2 oktober',
		'28 sept t/m vrijdag 2 okt 2026',
		'Geldig tot 12 maart 2028',
		'12 Maart en 31 december 2026',
		'31 april',
		'31 december 2026 tot en met 2 januari 2027',
		'1 Jan, 2 feb en 3 mrt',
		'Mei: 4 mei',
		'2026-2027',
		'ee020019-0000-4000-8000-000000001471',
		'versie 3',
		'geen datum',
		'10-15 oktober',
		'5 OKTOBER',
	];

	/**
	 * Every text moves as recorded, for each shift.
	 *
	 * @return void
	 */
	public function testShiftMatchesGolden(): void {
		$dates = new DemoDates();
		$actual = [];
		foreach ([0, 7, 21, 63, -7, -280, 364] as $days) {
			foreach (self::TEXTS as $text) {
				$actual[$days . ' days: ' . $text] = $dates->shiftString(text: $text, days: $days);
			}

			$actual[$days . ' days: nested'] = $dates->shift(value: ['a' => ['2026-10-05', 3, true, null, ['b' => '5 oktober']], 'c' => 1.5], days: $days);
		}

		$this->assertMatchesGolden(file: self::GOLDEN, actual: $actual);
	}//end testShiftMatchesGolden()
}//end class
