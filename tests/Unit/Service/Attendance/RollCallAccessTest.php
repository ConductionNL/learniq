<?php

/**
 * RollCallAccess unit tests: who may take which register, and when it is locked.
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

use DateTimeZone;
use OCA\Learniq\Service\Attendance\RollCallAccess;
use OCA\Learniq\Service\Attendance\RollCallException;
use OCA\Learniq\Service\Attendance\RollCallReader;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Register access over mocked groups, reader, zone and clock.
 */
#[CoversClass(RollCallAccess::class)]
#[UsesClass(RollCallException::class)]
class RollCallAccessTest extends TestCase {

	private RollCallReader&MockObject $reader;

	private IGroupManager&MockObject $groups;

	private IDateTimeZone&MockObject $zone;

	private ITimeFactory&MockObject $time;

	/**
	 * Group memberships by user id.
	 *
	 * @var array<string, list<string>>
	 */
	private array $memberships = [
		'admin' => [],
		'coord' => ['coordinators'],
		'manager' => ['administration-managers'],
		'teach' => ['instructors'],
		'learner' => ['students'],
	];

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->reader = $this->createMock(RollCallReader::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');
		$this->groups->method('isInGroup')->willReturnCallback(fn (string $uid, string $group): bool => in_array($group, ($this->memberships[$uid] ?? []), true));
		$this->zone = $this->createMock(IDateTimeZone::class);
		$this->time = $this->createMock(ITimeFactory::class);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return RollCallAccess
	 */
	private function access(): RollCallAccess {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new RollCallAccess(reader: $this->reader, groupManager: $this->groups, timeZone: $this->zone, time: $this->time, l10n: $l10n);
	}//end access()

	/**
	 * A user with the given id.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end user()

	/**
	 * Admins, coordinators and administration managers see every current cohort.
	 *
	 * @return void
	 */
	public function testSchoolWideUsersSeeEveryCohort(): void {
		$cohorts = [['id' => 'g-1'], ['id' => 'g-2']];
		$this->reader->method('currentCohorts')->willReturn($cohorts);
		$this->reader->expects($this->never())->method('teachersOf');

		foreach (['admin', 'coord', 'manager'] as $uid) {
			self::assertSame($cohorts, $this->access()->cohortsFor(user: $this->user(uid: $uid)));
		}
	}//end testSchoolWideUsersSeeEveryCohort()

	/**
	 * A teacher sees only the cohorts they teach, as a list.
	 *
	 * @return void
	 */
	public function testATeacherSeesOnlyTheirCohorts(): void {
		$this->reader->method('currentCohorts')->willReturn([['id' => 'g-1'], ['id' => 'g-2'], ['id' => 'g-3']]);
		$this->reader->method('teachersOf')->willReturnCallback(
			static fn (array $cohort): array => ($cohort['id'] === 'g-1' ? ['other'] : ['teach'])
		);

		self::assertSame([['id' => 'g-2'], ['id' => 'g-3']], $this->access()->cohortsFor(user: $this->user(uid: 'teach')));
	}//end testATeacherSeesOnlyTheirCohorts()

	/**
	 * Anyone else is refused with 403 before any cohort is read.
	 *
	 * @return void
	 */
	public function testAnyoneElseIsRefused(): void {
		$this->reader->expects($this->never())->method('currentCohorts');

		try {
			$this->access()->cohortsFor(user: $this->user(uid: 'learner'));
			self::fail('A learner must not get cohorts.');
		} catch (RollCallException $exception) {
			self::assertSame(RollCallException::FORBIDDEN, $exception->getStatus());
			self::assertSame('Only teachers and coordinators can take the register.', $exception->getMessage());
		}
	}//end testAnyoneElseIsRefused()

	/**
	 * Without a choice the first cohort is taken; a chosen one is found by id, uuid or @self id.
	 *
	 * @return void
	 */
	public function testTheChosenCohortIsFoundById(): void {
		$cohorts = [['id' => 'g-1'], ['uuid' => 'g-2'], ['@self' => ['id' => 'g-3']]];
		$access = $this->access();

		self::assertSame(['id' => 'g-1'], $access->chosenCohort(cohorts: $cohorts, cohortId: null));
		self::assertSame(['id' => 'g-1'], $access->chosenCohort(cohorts: $cohorts, cohortId: ''));
		self::assertSame(['uuid' => 'g-2'], $access->chosenCohort(cohorts: $cohorts, cohortId: 'g-2'));
		self::assertSame(['@self' => ['id' => 'g-3']], $access->chosenCohort(cohorts: $cohorts, cohortId: 'g-3'));
	}//end testTheChosenCohortIsFoundById()

	/**
	 * A cohort outside the allowed ones, or none at all, is refused with 403.
	 *
	 * @return void
	 */
	public function testACohortOutsideTheAllowedOnesIsRefused(): void {
		foreach ([[[['id' => 'g-1']], 'g-9'], [[], null]] as [$cohorts, $chosen]) {
			try {
				$this->access()->chosenCohort(cohorts: $cohorts, cohortId: $chosen);
				self::fail('The cohort must be refused.');
			} catch (RollCallException $exception) {
				self::assertSame(RollCallException::FORBIDDEN, $exception->getStatus());
				self::assertSame('You cannot take the register of this group.', $exception->getMessage());
			}
		}
	}//end testACohortOutsideTheAllowedOnesIsRefused()

	/**
	 * A future date is locked for everyone; today is open for everyone.
	 *
	 * @return void
	 */
	public function testFutureIsLockedAndTodayIsOpen(): void {
		$saved = [['markedVia' => 'teacher']];
		$access = $this->access();

		self::assertSame(RollCallAccess::LOCKED_FUTURE, $access->lockReason(user: $this->user(uid: 'admin'), date: '2026-11-14', today: '2026-11-13', records: []));
		self::assertSame('', $access->lockReason(user: $this->user(uid: 'teach'), date: '2026-11-13', today: '2026-11-13', records: $saved));
	}//end testFutureIsLockedAndTodayIsOpen()

	/**
	 * A past register a teacher saved is locked for teachers, not for school-wide users;
	 * self check-ins alone do not lock it.
	 *
	 * @return void
	 */
	public function testAPastSavedRegisterIsLockedForTeachersOnly(): void {
		$access = $this->access();
		$teacher = $this->user(uid: 'teach');

		self::assertSame(RollCallAccess::LOCKED_PAST_SAVED, $access->lockReason(user: $teacher, date: '2026-11-12', today: '2026-11-13', records: [['markedVia' => 'self-check-in'], []]));
		self::assertSame('', $access->lockReason(user: $teacher, date: '2026-11-12', today: '2026-11-13', records: [['markedVia' => 'self-check-in']]));
		self::assertSame('', $access->lockReason(user: $teacher, date: '2026-11-12', today: '2026-11-13', records: []));
		self::assertSame('', $access->lockReason(user: $this->user(uid: 'coord'), date: '2026-11-12', today: '2026-11-13', records: [['markedVia' => 'teacher']]));
	}//end testAPastSavedRegisterIsLockedForTeachersOnly()

	/**
	 * Today is the clock's date in the user's own zone.
	 *
	 * @return void
	 */
	public function testTodayIsTheDateInTheUsersZone(): void {
		$amsterdam = new DateTimeZone('Europe/Amsterdam');
		$this->zone->expects($this->once())->method('getTimeZone')->with(false, 'teach')->willReturn($amsterdam);
		// 2026-11-13 23:30 UTC is already 14 November in Amsterdam.
		$this->time->method('getTime')->willReturn(1794612600);
		$access = $this->access();

		$zone = $access->zone(user: $this->user(uid: 'teach'));

		self::assertSame($amsterdam, $zone);
		self::assertSame('2026-11-14', $access->today(zone: $zone));
		self::assertSame('2026-11-13', $access->today(zone: new DateTimeZone('UTC')));
	}//end testTodayIsTheDateInTheUsersZone()
}//end class
