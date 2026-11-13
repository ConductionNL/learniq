<?php

/**
 * Unit tests for SessionSeries' fallbacks: a session that cannot be read, an
 * anchor without a start, an unreadable time and the default eight-week window.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Timetabling
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Timetabling;

use DateTimeZone;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Timetabling\SessionSeries;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Timetabling\SessionSeries
 * @uses   \OCA\Learniq\Support\ObjectRow
 */
class SessionSeriesFallbacksTest extends TestCase {

	/**
	 * A session OpenRegister fails to read is no session, and has no series.
	 *
	 * @return void
	 */
	public function testASessionThatCannotBeReadHasNoSeries(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willThrowException(new \RuntimeException('gone'));
		$objects->expects(self::never())->method('findAll');

		$series = new SessionSeries(objectService: $objects);

		self::assertNull($series->loadSession(sessionId: 's-1'));
		self::assertNull($series->series(sessionId: 's-1'));
	}//end testASessionThatCannotBeReadHasNoSeries()

	/**
	 * A stored row without an id takes the id it was asked for; other fields are kept.
	 *
	 * @return void
	 */
	public function testARowWithoutAnIdTakesTheRequestedId(): void {
		$objects = $this->createMock(ObjectService::class);
		$asked = [];
		$objects->expects(self::once())
			->method('find')
			->willReturnCallback(
				static function (int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null) use (&$asked) {
					$asked = [$id, $register, $schema];
					return OrEntityFactory::make(['title' => 'Wiskunde'], 'session');
				}
			);

		$loaded = (new SessionSeries(objectService: $objects))->loadSession(sessionId: 's-1');
		self::assertSame('s-1', $loaded['id']);
		self::assertSame('Wiskunde', $loaded['title']);
		self::assertSame(['s-1', 'learniq', 'session'], $asked);
	}//end testARowWithoutAnIdTakesTheRequestedId()

	/**
	 * An anchor without a start has an empty series, and no other session is read.
	 *
	 * @return void
	 */
	public function testAnAnchorWithoutAStartHasAnEmptySeries(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn(OrEntityFactory::make(['id' => 's-1', 'cohortId' => 'c-1', 'startsAt' => ''], 'session'));
		$objects->expects(self::never())->method('findAll');

		self::assertSame([], (new SessionSeries(objectService: $objects))->series(sessionId: 's-1'));
	}//end testAnAnchorWithoutAStartHasAnEmptySeries()

	/**
	 * A time that cannot be parsed is null; a parsable one is moved into the zone.
	 *
	 * @return void
	 */
	public function testAnUnreadableTimeIsNull(): void {
		$series = new SessionSeries(objectService: $this->createMock(ObjectService::class));
		$tz = new DateTimeZone(SessionSeries::TIMEZONE);

		self::assertNull($series->localTime(value: 'not a moment', tz: $tz));
		self::assertNull($series->localTime(value: '', tz: $tz));
		self::assertSame('2026-09-07 11:00', $series->localTime(value: '2026-09-07T09:00:00Z', tz: $tz)->format('Y-m-d H:i'));
	}//end testAnUnreadableTimeIsNull()

	/**
	 * Without an until date the series runs eight weeks from the anchor, and only the anchor's weekly slot counts.
	 *
	 * @return void
	 */
	public function testWithoutAnUntilDateTheSeriesRunsEightWeeks(): void {
		$row = static fn (string $id, string $startsAt, string $lifecycle = 'scheduled'): array => ['id' => $id, 'title' => 'Wiskunde', 'cohortId' => 'c-1', 'startsAt' => $startsAt, 'endsAt' => '', 'lifecycle' => $lifecycle];

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn(OrEntityFactory::make($row('s-1', '2026-09-07T09:00:00Z'), 'session'));
		$objects->expects(self::once())
			->method('findAll')
			->with(['filters' => ['register' => 'learniq', 'schema' => 'session', 'cohortId' => 'c-1'], 'sort' => ['startsAt' => 'ASC']])
			->willReturn(
				[
					$row('s-9', '2026-11-09T10:00:00Z'),
					$row('s-8', '2026-11-02T10:00:00Z', 'completed'),
					$row('s-1', '2026-09-07T09:00:00Z'),
					$row('s-x', '2026-09-08T09:00:00Z'),
					$row('s-0', '2026-08-31T09:00:00Z'),
				]
			);

		$found = (new SessionSeries(objectService: $objects))->series(sessionId: 's-1');

		self::assertSame(['s-1', 's-8'], array_column($found, 'id'));
		self::assertSame([true, false], array_column($found, 'changeable'));
	}//end testWithoutAnUntilDateTheSeriesRunsEightWeeks()
}//end class
