<?php

/**
 * The reads behind the First today card: own groups only, a refused register, and rows with the caller's rights.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-each-role-tile-counts-what-that-role-acts-on-and-opens-the-list-it-counts
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Today;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Service\Attendance\RollCallAccess;
use OCA\Learniq\Service\Attendance\RollCallException;
use OCA\Learniq\Service\Attendance\RollCallReader;
use OCA\Learniq\Service\Today\FirstTodayReader;
use OCA\Learniq\Service\Today\TodayContext;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\Today\FirstTodayReader
 * @uses   \OCA\Learniq\Service\Attendance\RollCallException
 * @uses   \OCA\Learniq\Service\Today\TodayContext
 */
class FirstTodayReaderTest extends TestCase {

	/**
	 * A user signed in as daan.
	 *
	 * @return IUser
	 */
	private function daan(): IUser {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('daan');
		return $user;
	}//end daan()

	/**
	 * Daan on Monday 5 October 2026 at 8.40, Amsterdam.
	 *
	 * @return TodayContext
	 */
	private function monday(): TodayContext {
		return new TodayContext(user: $this->daan(), now: new DateTimeImmutable('2026-10-05T08:40:00', new DateTimeZone('Europe/Amsterdam')));
	}//end monday()

	/**
	 * The context carries now in the caller's time zone.
	 *
	 * @return void
	 */
	public function testTheContextIsNowInTheCallersZone(): void {
		$access = $this->createMock(originalClassName: RollCallAccess::class);
		$access->method('zone')->willReturn(new DateTimeZone('Europe/Amsterdam'));
		$clock = $this->createMock(originalClassName: ITimeFactory::class);
		$clock->method('getTime')->willReturn((new DateTimeImmutable('2026-10-04T22:30:00Z'))->getTimestamp());

		$context = (new FirstTodayReader(
			objectService: $this->createMock(originalClassName: ObjectService::class),
			access: $access,
			reader: $this->createMock(originalClassName: RollCallReader::class),
			time: $clock
		))
			->contextFor(user: $this->daan());

		self::assertSame(expected: '2026-10-05', actual: $context->today());
		self::assertSame(expected: 'daan', actual: $context->uid());
		self::assertSame(expected: 'Europe/Amsterdam', actual: $context->zone()->getName());
	}//end testTheContextIsNowInTheCallersZone()

	/**
	 * A teacher's own groups carry today's lessons, the marks per lesson and the parent reports.
	 *
	 * @return void
	 */
	public function testOwnGroupsCarryLessonsMarksAndReports(): void {
		$access = $this->createMock(originalClassName: RollCallAccess::class);
		$access->method('cohortsFor')->willReturn([
			['id' => 'groep-7', 'name' => 'Groep 7', 'learnerIds' => ['vera', 'sami', '']],
			['id' => 'groep-3', 'name' => 'Groep 3', 'learnerIds' => ['x']],
		]);
		$reader = $this->createMock(originalClassName: RollCallReader::class);
		$reader->method('teachersOf')->willReturnMap(
			[
				[['id' => 'groep-7', 'name' => 'Groep 7', 'learnerIds' => ['vera', 'sami', '']], ['daan']],
				[['id' => 'groep-3', 'name' => 'Groep 3', 'learnerIds' => ['x']], ['anne']],
			]
		);
		$reader->expects(self::once())->method('sessionsOn')->with('groep-7', '2026-10-05', self::anything())->willReturn([['id' => 's-1']]);
		$reader->method('recordsOf')->with('s-1')->willReturn(['vera' => [], 'sami' => []]);
		$reader->expects(self::once())->method('reportsOn')->with(['vera', 'sami'], '2026-10-05')->willReturn(['vera' => []]);

		$groups = (new FirstTodayReader(
			objectService: $this->createMock(originalClassName: ObjectService::class),
			access: $access,
			reader: $reader,
			time: $this->createMock(originalClassName: ITimeFactory::class)
		))
			->groupsToday(context: $this->monday(), own: true);

		self::assertCount(expectedCount: 1, haystack: $groups);
		self::assertSame(expected: 'Groep 7', actual: $groups[0]['cohort']['name']);
		self::assertSame(expected: ['s-1' => 2], actual: $groups[0]['records']);
		self::assertSame(expected: 1, actual: $groups[0]['reports']);
	}//end testOwnGroupsCarryLessonsMarksAndReports()

	/**
	 * Someone who may open no register gets no groups.
	 *
	 * @return void
	 */
	public function testACallerWhoMayOpenNoRegisterGetsNoGroups(): void {
		$access = $this->createMock(originalClassName: RollCallAccess::class);
		$access->method('cohortsFor')->willThrowException(new RollCallException(message: 'no', status: RollCallException::FORBIDDEN));

		$reader = new FirstTodayReader(
			objectService: $this->createMock(originalClassName: ObjectService::class),
			access: $access,
			reader: $this->createMock(originalClassName: RollCallReader::class),
			time: $this->createMock(originalClassName: ITimeFactory::class)
		);

		self::assertSame(expected: [], actual: $reader->groupsToday(context: $this->monday(), own: false));
	}//end testACallerWhoMayOpenNoRegisterGetsNoGroups()

	/**
	 * Rows are read in learniq's register with the caller's rights, entities as their serialisation.
	 *
	 * @return void
	 */
	public function testRowsAreReadWithTheCallersRights(): void {
		$entity = new class {
			/**
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'b'];
			}
		};
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->expects(self::once())->method('findAll')
			->with(['filters' => ['register' => 'learniq', 'schema' => 'leave-request', 'lifecycle' => 'submitted'], 'limit' => 500])
			->willReturn([['id' => 'a'], $entity, 'junk']);

		$reader = new FirstTodayReader(
			objectService: $objects,
			access: $this->createMock(originalClassName: RollCallAccess::class),
			reader: $this->createMock(originalClassName: RollCallReader::class),
			time: $this->createMock(originalClassName: ITimeFactory::class)
		);

		self::assertSame(
			expected: [['id' => 'a'], ['id' => 'b']],
			actual: $reader->rows(schema: 'leave-request', filters: ['lifecycle' => 'submitted'])
		);
	}//end testRowsAreReadWithTheCallersRights()
}//end class
