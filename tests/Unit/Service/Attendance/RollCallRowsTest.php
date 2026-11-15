<?php

/**
 * RollCallRows unit tests: row ids and timestamps as the register reads them.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Attendance
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Attendance;

use OCA\Learniq\Service\Attendance\RollCallRows;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The shared row helpers of the roll call services, through a bare user of the trait.
 */
#[CoversClass(RollCallRows::class)]
class RollCallRowsTest extends TestCase {

	/**
	 * An object using the trait.
	 *
	 * @return object
	 */
	private static function rows(): object {
		return new class {
			use RollCallRows;
		};
	}//end rows()

	/**
	 * The id is `id`, else `uuid`, else `@self.id`, else empty.
	 *
	 * @return void
	 */
	public function testTheIdFallsBackThroughUuidAndSelf(): void {
		$rows = self::rows();

		self::assertSame('a', $rows->idOf(row: ['id' => 'a', 'uuid' => 'b', '@self' => ['id' => 'c']]));
		self::assertSame('b', $rows->idOf(row: ['uuid' => 'b', '@self' => ['id' => 'c']]));
		self::assertSame('c', $rows->idOf(row: ['@self' => ['id' => 'c']]));
		self::assertSame('42', $rows->idOf(row: ['id' => 42]));
		self::assertSame('', $rows->idOf(row: []));
	}//end testTheIdFallsBackThroughUuidAndSelf()

	/**
	 * A date-time string becomes a moment in its own offset.
	 *
	 * @return void
	 */
	public function testADateTimeBecomesAMoment(): void {
		$moment = self::rows()->moment(value: '2026-11-13T08:30:00+01:00');

		self::assertNotNull($moment);
		self::assertSame('2026-11-13T07:30:00+00:00', $moment->setTimezone(new \DateTimeZone('UTC'))->format('c'));
	}//end testADateTimeBecomesAMoment()

	/**
	 * A bare date, a non-string or an invalid date-time is no moment.
	 *
	 * @return void
	 */
	public function testAnythingButADateTimeIsNoMoment(): void {
		$rows = self::rows();

		self::assertNull($rows->moment(value: '2026-11-13'));
		self::assertNull($rows->moment(value: 'today'));
		self::assertNull($rows->moment(value: 1794612600));
		self::assertNull($rows->moment(value: null));
		self::assertNull($rows->moment(value: '2026-11-13Tnonsense'));
	}//end testAnythingButADateTimeIsNoMoment()
}//end class
