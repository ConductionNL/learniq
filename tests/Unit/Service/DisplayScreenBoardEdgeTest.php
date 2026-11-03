<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Service\DisplayScreenBoard;
use OCA\Learniq\Timetabling\Source\TimetableSource;
use OCA\Learniq\Timetabling\Source\TimetableSourceResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

/**
 * The edges of a public display screen: a room that cannot be named, teacher
 * code flags that are not `false`, and rooms or references that are blank.
 *
 * @covers \OCA\Learniq\Service\DisplayScreenBoard
 * @uses   \OCA\Learniq\Service\DisplayScreenLesson
 */
class DisplayScreenBoardEdgeTest extends TestCase {

	private const ROOMS = [
		['id' => 'r-1', 'name' => 'Lokaal 1', 'code' => 'A1'],
		['id' => 'r-2', 'name' => 'Lokaal 2', 'code' => ''],
		['name' => 'Zonder id', 'code' => 'Z9'],
	];

	/**
	 * An unknown room with no location shows no room, never the room's id.
	 *
	 * @return void
	 */
	public function testUnknownRoomWithoutLocationShowsNoRoom(): void {
		$board = $this->board(rows: [$this->lesson(fields: ['roomId' => 'r-unknown'])]);

		self::assertSame('', $board->build(screen: ['id' => 'd', 'cohortIds' => ['c-1']])['lessons'][0]['room']);
	}//end testUnknownRoomWithoutLocationShowsNoRoom()

	/**
	 * Only `false` hides teacher codes: 0 and '' are not `false`.
	 *
	 * @param mixed $flag The screen's `showTeacherCodes`.
	 *
	 * @return void
	 *
	 * @dataProvider showTeacherCodesThatAreNotFalse
	 */
	public function testOnlyFalseHidesTeacherCodes(mixed $flag): void {
		$board = $this->board(rows: [$this->lesson(fields: ['teacherReference' => 'JAN'])]);
		$screen = ['id' => 'd', 'cohortIds' => ['c-1'], 'showTeacherCodes' => $flag];

		self::assertSame('JAN', $board->build(screen: $screen)['lessons'][0]['teacherCode']);
	}//end testOnlyFalseHidesTeacherCodes()

	/**
	 * Flag values that keep the codes on.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public static function showTeacherCodesThatAreNotFalse(): array {
		return ['zero' => [0], 'blank' => ['']];
	}//end showTeacherCodesThatAreNotFalse()

	/**
	 * A blank room reference does not match a room with a blank code.
	 *
	 * @return void
	 */
	public function testBlankReferenceDoesNotMatchABlankRoomCode(): void {
		$rows = [
			$this->lesson(fields: ['roomId' => 'r-other', 'roomReference' => '']),
			$this->lesson(fields: ['roomId' => 'r-other', 'roomReference' => 'A1', 'subject' => 'by code']),
		];

		$lessons = $this->board(rows: $rows)->build(screen: ['id' => 'd', 'cohortIds' => ['c-1'], 'roomIds' => ['r-1', 'r-2']])['lessons'];

		self::assertSame(['by code'], array_column($lessons, 'subject'));
	}//end testBlankReferenceDoesNotMatchABlankRoomCode()

	/**
	 * A room without an id is no room of the screen, so a lesson that names no room stays off it.
	 *
	 * @return void
	 */
	public function testRoomWithoutAnIdDoesNotAdmitALessonWithoutARoom(): void {
		$rows = [$this->lesson(fields: ['subject' => 'no room']), $this->lesson(fields: ['roomId' => 'r-1', 'subject' => 'in room'])];

		$lessons = $this->board(rows: $rows)->build(screen: ['id' => 'd', 'cohortIds' => ['c-1'], 'roomIds' => ['r-1']])['lessons'];

		self::assertSame(['in room'], array_column($lessons, 'subject'));
	}//end testRoomWithoutAnIdDoesNotAdmitALessonWithoutARoom()

	/**
	 * A lesson today in the screen's window.
	 *
	 * @param array<string,mixed> $fields The lesson's own fields.
	 *
	 * @return array<string,mixed>
	 */
	private function lesson(array $fields): array {
		$start = new DateTimeImmutable('today 10:00', new DateTimeZone('Europe/Amsterdam'));

		return array_merge(['cohortId' => 'c-1', 'startsAt' => $start->format(DATE_ATOM), 'subject' => 'Wiskunde'], $fields);
	}//end lesson()

	/**
	 * The board over one group, the fixed rooms and the given lessons.
	 *
	 * @param array<int,array<string,mixed>> $rows The lessons the source answers.
	 *
	 * @return DisplayScreenBoard
	 */
	private function board(array $rows): DisplayScreenBoard {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static fn (array $config): array => $config['filters']['schema'] === 'room' ? self::ROOMS : [['id' => 'c-1', 'name' => '4H1']]
		);
		$source = $this->createMock(TimetableSource::class);
		$source->method('sessionsForCohorts')->willReturn($rows);
		$sources = $this->createMock(TimetableSourceResolver::class);
		$sources->method('current')->willReturn($source);
		$cache = $this->createMock(ICache::class);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		return new DisplayScreenBoard($objects, $sources, $factory);
	}//end board()
}//end class
