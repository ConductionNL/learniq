<?php

/**
 * Tests for SubstitutionCandidateService and StandbyCalendar.
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/timetabling/spec.md#requirement-the-substitution-dialog-offers-standby-teachers-first
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\StandbyCalendar;
use OCA\Learniq\Service\SubstitutionCandidateService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Timetabling\Source\LocalSessionTimetableSource;
use OCA\Learniq\Timetabling\Source\PlanninqTimetableSource;
use OCA\Learniq\Timetabling\Source\TimetableSourceResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Standby first, then free, then a busy standby teacher; the absent teacher never.
 */
class SubstitutionCandidateServiceTest extends TestCase {

	/**
	 * The stored objects per schema.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private array $store = [];

	/**
	 * An object service over the store, honouring equality filters.
	 *
	 * @return ObjectService
	 */
	private function objects(): ObjectService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				$filters = $config['filters'];
				$schema = $filters['schema'];
				unset($filters['register'], $filters['schema']);
				$rows = array_filter(
					($this->store[$schema] ?? []),
					static function (array $row) use ($filters): bool {
						foreach ($filters as $key => $value) {
							if (($row[$key] ?? null) !== $value) {
								return false;
							}
						}

						return true;
					}
				);
				return OrEntityFactory::makeMany(array_values($rows), $schema);
			}
		);
		return $objects;
	}//end objects()

	/**
	 * The service with learniq's own sessions as the source.
	 *
	 * @return SubstitutionCandidateService
	 */
	private function service(): SubstitutionCandidateService {
		$objects = $this->objects();
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('auto');
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(false);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			function (string $uid): IUser {
				$user = $this->createMock(IUser::class);
				$user->method('getDisplayName')->willReturn(ucfirst($uid));
				return $user;
			}
		);

		return new SubstitutionCandidateService(
			$objects,
			new TimetableSourceResolver($config, new LocalSessionTimetableSource($objects), new PlanninqTimetableSource($appManager, $this->createMock(IEventDispatcher::class))),
			$users,
			new StandbyCalendar($objects)
		);
	}//end service()

	/**
	 * A Tuesday lesson of 4H1 at 10:15, its sick teacher, two standby teachers
	 * (one of whom teaches then) and two colleagues who work on Tuesday.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$slot = ['weekday' => 'tuesday', 'startsAt' => '10:15', 'endsAt' => '11:05', 'validFrom' => '2026-08-01', 'validUntil' => '2027-07-31'];
		$this->store = [
			'cohort' => [
				['id' => 'c-4h1', 'name' => '4H1', 'teacherIds' => ['sick']],
				['id' => 'c-5v1', 'name' => '5V1', 'teacherIds' => ['busybee']],
			],
			'session' => [
				['id' => 's-1', 'cohortId' => 'c-4h1', 'title' => 'Wiskunde B', 'startsAt' => '2026-09-29T10:15:00+02:00', 'endsAt' => '2026-09-29T11:05:00+02:00', 'lifecycle' => 'scheduled'],
				['id' => 's-2', 'cohortId' => 'c-5v1', 'title' => 'Engels', 'startsAt' => '2026-09-29T10:30:00+02:00', 'endsAt' => '2026-09-29T11:20:00+02:00', 'lifecycle' => 'scheduled'],
			],
			'standby-slot' => [
				array_merge($slot, ['id' => 'sb-1', 'teacherId' => 'eva']),
				array_merge($slot, ['id' => 'sb-2', 'teacherId' => 'busybee']),
				array_merge($slot, ['id' => 'sb-3', 'teacherId' => 'sick']),
				array_merge($slot, ['id' => 'sb-4', 'teacherId' => 'late', 'startsAt' => '13:00', 'endsAt' => '14:00']),
				array_merge($slot, ['id' => 'sb-5', 'teacherId' => 'expired', 'validUntil' => '2026-07-31']),
			],
			'staff' => [
				['ncUserId' => 'eva', 'workingDays' => ['tuesday']],
				['ncUserId' => 'free1', 'workingDays' => ['monday', 'tuesday']],
				['ncUserId' => 'offday', 'workingDays' => ['monday']],
				['ncUserId' => 'sick', 'workingDays' => ['tuesday']],
				['ncUserId' => 'busybee', 'workingDays' => ['tuesday']],
			],
		];
	}//end setUp()

	/**
	 * The standby teacher is first, a free colleague next, the busy standby teacher last.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/timetabling/spec.md#scenario-a-coordinator-covers-a-sick-teacher-s-lesson
	 */
	public function testStandbyFirstFreeNextBusyLast(): void {
		$out = $this->service()->forSession(session: $this->store['session'][0], cohort: $this->store['cohort'][0]);

		self::assertSame(['eva', 'free1', 'busybee'], array_column($out, 'userId'));
		self::assertSame('standby', $out[0]['group']);
		self::assertSame(['startsAt' => '10:15', 'endsAt' => '11:05'], $out[0]['slot']);
		self::assertSame('Eva', $out[0]['displayName']);
		self::assertSame('free', $out[1]['group']);
	}//end testStandbyFirstFreeNextBusyLast()

	/**
	 * A standby teacher with a lesson at that time is last with that reason.
	 *
	 * @return void
	 */
	public function testBusyStandbyTeacherGoesLast(): void {
		$out = $this->service()->forSession(session: $this->store['session'][0], cohort: $this->store['cohort'][0]);
		$last = end($out);

		self::assertSame('busybee', $last['userId']);
		self::assertSame('busy', $last['group']);
		self::assertSame('Has a lesson then', $last['reason']);
	}//end testBusyStandbyTeacherGoesLast()

	/**
	 * The absent teacher, a slot at another time, an expired slot and a colleague off that day are not listed.
	 *
	 * @return void
	 */
	public function testTheAbsentTeacherIsNeverListed(): void {
		$ids = array_column($this->service()->forSession(session: $this->store['session'][0], cohort: $this->store['cohort'][0]), 'userId');

		self::assertNotContains('sick', $ids);
		self::assertNotContains('late', $ids);
		self::assertNotContains('expired', $ids);
		self::assertNotContains('offday', $ids);
	}//end testTheAbsentTeacherIsNeverListed()

	/**
	 * A slot at another location does not cover the lesson; a slot without one does.
	 *
	 * @return void
	 */
	public function testLocation(): void {
		$slot = ['weekday' => 'tuesday', 'startsAt' => '10:00', 'endsAt' => '11:00', 'validFrom' => '2026-08-01', 'validUntil' => '2027-07-31'];
		$lesson = ['date' => '2026-09-29', 'weekday' => 'tuesday', 'from' => '10:15', 'to' => '11:05', 'location' => 'v-1'];
		$calendar = new StandbyCalendar($this->objects());

		self::assertTrue($calendar->covers(slot: $slot, lesson: $lesson));
		self::assertFalse($calendar->covers(slot: $slot + ['vestigingId' => 'v-2'], lesson: $lesson));
		self::assertTrue($calendar->covers(slot: ['date' => '2026-09-29'] + $slot, lesson: ['location' => ''] + $lesson));
		self::assertFalse($calendar->covers(slot: ['date' => '2026-09-30'] + $slot, lesson: $lesson));
		self::assertFalse($calendar->covers(slot: $slot, lesson: ['from' => '11:00', 'to' => '11:50'] + $lesson));
	}//end testLocation()

	/**
	 * A teacher's standby blocks in a week: a weekly slot on its weekday, a one-off on its date.
	 *
	 * @return void
	 */
	public function testStandbyBlocksOfAWeek(): void {
		$this->store['standby-slot'][] = ['id' => 'sb-6', 'teacherId' => 'eva', 'date' => '2026-10-01', 'startsAt' => '08:30', 'endsAt' => '09:20', 'validFrom' => '2026-08-01', 'validUntil' => '2027-07-31'];

		$blocks = (new StandbyCalendar($this->objects()))->blocksFor(uid: 'eva', from: '2026-09-28T00:00:00+02:00', to: '2026-10-05T00:00:00+02:00');

		self::assertSame([['2026-09-29', '10:15'], ['2026-10-01', '08:30']], array_map(static fn (array $b): array => [$b['date'], $b['startsAt']], $blocks));
	}//end testStandbyBlocksOfAWeek()
}//end class
