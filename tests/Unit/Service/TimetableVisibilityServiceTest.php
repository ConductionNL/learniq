<?php

/**
 * Tests for TimetableVisibilityService.
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
 * @spec openspec/changes/timetabling-visibility-rules/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\TimetableDirectory;
use OCA\Learniq\Service\TimetableVisibilityService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Timetabling\Source\LocalSessionTimetableSource;
use OCA\Learniq\Timetabling\Source\PlanninqTimetableSource;
use OCA\Learniq\Timetabling\Source\TimetableSourceResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Each policy value for each role.
 */
class TimetableVisibilityServiceTest extends TestCase {

	/**
	 * The stored objects per schema.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private array $store = [];

	/**
	 * Groups per user.
	 *
	 * @var array<string,array<int,string>>
	 */
	private array $groups = ['jan' => ['instructors'], 'lead' => ['team-leads']];

	/**
	 * The directory the last service() call built.
	 *
	 * @var TimetableDirectory|null
	 */
	private ?TimetableDirectory $directory = null;

	/**
	 * The service over the store.
	 *
	 * @return TimetableVisibilityService
	 */
	private function service(): TimetableVisibilityService {
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
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('auto');
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(false);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturnCallback(fn (string $u, string $g): bool => in_array($g, ($this->groups[$u] ?? []), true));

		$sources = new TimetableSourceResolver($config, new LocalSessionTimetableSource($objects), new PlanninqTimetableSource($appManager, $this->createMock(IEventDispatcher::class)));
		$this->directory = new TimetableDirectory($objects, $sources);

		return new TimetableVisibilityService($this->directory, $groups, $this->createMock(IUserManager::class));
	}//end service()

	/**
	 * Learner m.yilmaz in 4 havo A (taught by jan in room r-1); 5 vwo B taught by piet in r-2.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$soon = gmdate(DATE_ATOM, (time() + 86400));
		$later = gmdate(DATE_ATOM, (time() + 90000));
		$this->store = [
			'cohort' => [
				['id' => 'c-4a', 'name' => '4 havo A', 'learnerIds' => ['m.yilmaz'], 'teacherIds' => ['jan']],
				['id' => 'c-5b', 'name' => '5 vwo B', 'learnerIds' => ['other'], 'teacherIds' => ['piet']],
			],
			'room' => [
				['id' => 'r-1', 'name' => 'Lokaal 1', 'code' => 'L1'],
				['id' => 'r-2', 'name' => 'Lokaal 2', 'code' => 'L2'],
			],
			'session' => [
				['id' => 's-1', 'cohortId' => 'c-4a', 'title' => 'Wiskunde B', 'startsAt' => $soon, 'endsAt' => $later, 'roomId' => 'r-1'],
				['id' => 's-2', 'cohortId' => 'c-5b', 'title' => 'Engels', 'startsAt' => $soon, 'endsAt' => $later, 'roomId' => 'r-2'],
			],
		];
	}//end setUp()

	/**
	 * Under the defaults a learner opens their own group only.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timetabling-visibility-rules/specs/personal-timetable/spec.md#scenario-a-learner-cannot-open-another-group
	 */
	public function testLearnerOwnGroupsOnly(): void {
		$service = $this->service();

		self::assertTrue($service->mayOpen(uid: 'm.yilmaz', kind: 'cohort', id: 'c-4a'));
		self::assertFalse($service->mayOpen(uid: 'm.yilmaz', kind: 'cohort', id: 'c-5b'));
		self::assertSame(['c-4a'], array_column($service->options(uid: 'm.yilmaz', kind: 'cohort'), 'id'));
	}//end testLearnerOwnGroupsOnly()

	/**
	 * Related means the teachers and rooms of the learner's own lessons.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timetabling-visibility-rules/specs/personal-timetable/spec.md#scenario-a-learner-looks-up-their-maths-teacher
	 */
	public function testLearnerRelatedTeachersAndRooms(): void {
		$service = $this->service();

		self::assertTrue($service->mayOpen(uid: 'm.yilmaz', kind: 'teacher', id: 'jan'));
		self::assertFalse($service->mayOpen(uid: 'm.yilmaz', kind: 'teacher', id: 'piet'));
		self::assertTrue($service->mayOpen(uid: 'm.yilmaz', kind: 'room', id: 'r-1'));
		self::assertFalse($service->mayOpen(uid: 'm.yilmaz', kind: 'room', id: 'r-2'));

		$lessons = $this->directory->lessonsOf(kind: 'teacher', id: 'jan', from: gmdate(DATE_ATOM, time()), to: gmdate(DATE_ATOM, (time() + (7 * 86400))));
		self::assertSame(['s-1'], array_column($lessons['sessions'], 'id'));
	}//end testLearnerRelatedTeachersAndRooms()

	/**
	 * A school that lets learners see all rooms and no teachers.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timetabling-visibility-rules/specs/personal-timetable/spec.md#scenario-a-school-lets-learners-see-every-room
	 */
	public function testSchoolPolicyOverridesTheDefaults(): void {
		$this->store['timetable-visibility-policy'] = [['id' => 'p-1', 'learnerSeesRooms' => 'all', 'learnerSeesTeachers' => 'none', 'instructorSeesGroups' => 'own']];
		$service = $this->service();

		self::assertSame(['r-1', 'r-2'], array_column($service->options(uid: 'm.yilmaz', kind: 'room'), 'id'));
		self::assertFalse($service->mayOpen(uid: 'm.yilmaz', kind: 'teacher', id: 'jan'));
		// A teacher limited to their own groups.
		self::assertTrue($service->mayOpen(uid: 'jan', kind: 'cohort', id: 'c-4a'));
		self::assertFalse($service->mayOpen(uid: 'jan', kind: 'cohort', id: 'c-5b'));
	}//end testSchoolPolicyOverridesTheDefaults()

	/**
	 * Teachers see all by default; team leads always see all; an unknown kind is refused.
	 *
	 * @return void
	 */
	public function testInstructorAndStaffDefaults(): void {
		$service = $this->service();

		self::assertTrue($service->mayOpen(uid: 'jan', kind: 'cohort', id: 'c-5b'));
		self::assertTrue($service->mayOpen(uid: 'jan', kind: 'teacher', id: 'piet'));
		self::assertSame('all', $service->scope(uid: 'lead', kind: 'teacher'));
		self::assertFalse($service->mayOpen(uid: 'lead', kind: 'building', id: 'x'));

		$room = $this->directory->lessonsOf(kind: 'room', id: 'r-2', from: gmdate(DATE_ATOM, time()), to: gmdate(DATE_ATOM, (time() + (7 * 86400))));
		self::assertSame(['s-2'], array_column($room['sessions'], 'id'));
	}//end testInstructorAndStaffDefaults()
}//end class
