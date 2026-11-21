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

use DateTimeZone;
use OCA\Learniq\Service\Attendance\RollCallAccess;
use OCA\Learniq\Service\Attendance\RollCallException;
use OCA\Learniq\Service\Attendance\RollCallReader;
use OCA\Learniq\Service\Today\FirstTodayReader;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\Today\FirstTodayReader
 * @uses   \OCA\Learniq\Service\Attendance\RollCallException
 */
class FirstTodayReaderTest extends TestCase {

	/**
	 * A user signed in as daan.
	 *
	 * @return IUser
	 */
	private function daan(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('daan');
		return $user;
	}//end daan()

	/**
	 * A teacher's own groups carry today's lessons, the marks per lesson and the parent reports.
	 *
	 * @return void
	 */
	public function testOwnGroupsCarryLessonsMarksAndReports(): void {
		$access = $this->createMock(RollCallAccess::class);
		$access->method('cohortsFor')->willReturn([
			['id' => 'groep-7', 'name' => 'Groep 7', 'learnerIds' => ['vera', 'sami', '']],
			['id' => 'groep-3', 'name' => 'Groep 3', 'learnerIds' => ['x']],
		]);
		$reader = $this->createMock(RollCallReader::class);
		$reader->method('teachersOf')->willReturnCallback(static fn (array $cohort): array => $cohort['id'] === 'groep-7' ? ['daan'] : ['anne']);
		$reader->expects(self::once())->method('sessionsOn')->with('groep-7', '2026-10-05', self::anything())->willReturn([['id' => 's-1']]);
		$reader->method('recordsOf')->with('s-1')->willReturn(['vera' => [], 'sami' => []]);
		$reader->expects(self::once())->method('reportsOn')->with(['vera', 'sami'], '2026-10-05')->willReturn(['vera' => []]);

		$groups = (new FirstTodayReader(objectService: $this->createMock(ObjectService::class), access: $access, reader: $reader))
			->groupsToday(user: $this->daan(), own: true, today: '2026-10-05', zone: new DateTimeZone('Europe/Amsterdam'));

		self::assertCount(1, $groups);
		self::assertSame('Groep 7', $groups[0]['cohort']['name']);
		self::assertSame(['s-1' => 2], $groups[0]['records']);
		self::assertSame(1, $groups[0]['reports']);
	}//end testOwnGroupsCarryLessonsMarksAndReports()

	/**
	 * Someone who may open no register gets no groups.
	 *
	 * @return void
	 */
	public function testACallerWhoMayOpenNoRegisterGetsNoGroups(): void {
		$access = $this->createMock(RollCallAccess::class);
		$access->method('cohortsFor')->willThrowException(new RollCallException('no', RollCallException::FORBIDDEN));

		$reader = new FirstTodayReader(objectService: $this->createMock(ObjectService::class), access: $access, reader: $this->createMock(RollCallReader::class));

		self::assertSame([], $reader->groupsToday(user: $this->daan(), own: false, today: '2026-10-05', zone: new DateTimeZone('UTC')));
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
		$objects = $this->createMock(ObjectService::class);
		$objects->expects(self::once())->method('findAll')
			->with(['filters' => ['register' => 'learniq', 'schema' => 'leave-request', 'lifecycle' => 'submitted'], 'limit' => 500])
			->willReturn([['id' => 'a'], $entity, 'junk']);

		$reader = new FirstTodayReader(objectService: $objects, access: $this->createMock(RollCallAccess::class), reader: $this->createMock(RollCallReader::class));

		self::assertSame([['id' => 'a'], ['id' => 'b']], $reader->rows(schema: 'leave-request', filters: ['lifecycle' => 'submitted']));
	}//end testRowsAreReadWithTheCallersRights()
}//end class
