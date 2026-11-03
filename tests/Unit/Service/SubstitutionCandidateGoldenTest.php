<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\StandbyCalendar;
use OCA\Learniq\Service\SubstitutionCandidateService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Timetabling\Source\TimetableSource;
use OCA\Learniq\Timetabling\Source\TimetableSourceResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins forSession() for lessons that reach every branch of the candidate
 * list against golden/SubstitutionCandidates.json, case by case: the list,
 * the timetable window asked for, and the schemas read. With
 * RECORD_GOLDEN=1 the file is written instead of compared.
 *
 * @covers \OCA\Learniq\Service\SubstitutionCandidateService
 * @uses   \OCA\Learniq\Service\StandbyCalendar
 */
class SubstitutionCandidateGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/SubstitutionCandidates.json';

	/**
	 * The timetable windows asked for in the current case.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $asked = [];

	/**
	 * Every case lists the recorded candidates.
	 *
	 * @return void
	 */
	public function testCandidatesMatchGolden(): void {
		$cohort = ['id' => 'c-1', 'locationId' => 'v-1', 'teacherIds' => ['absent', 7]];
		$session = ['id' => 'ses-1', 'startsAt' => '2026-09-29T09:00:00+02:00', 'endsAt' => '2026-09-29T09:50:00+02:00'];
		$cases = [
			'tuesday lesson' => [$session, $cohort, false],
			'uuid instead of id' => [['uuid' => 'ses-1'] + array_diff_key($session, ['id' => true]), $cohort, false],
			'no id keeps every lesson' => [array_diff_key($session, ['id' => true]), $cohort, false],
			'timetable down' => [$session, $cohort, true],
			'other location' => [$session, ['locationId' => 'v-2'] + $cohort, false],
			'no location' => [$session, ['locationId' => ''] + $cohort, false],
			'nobody absent' => [$session, ['teacherIds' => []] + $cohort, false],
			'late lesson' => [['startsAt' => '2026-09-29T14:00:00+02:00', 'endsAt' => '2026-09-29T14:50:00+02:00'] + $session, $cohort, false],
			'wednesday lesson' => [['startsAt' => '2026-09-30T09:00:00+02:00', 'endsAt' => '2026-09-30T09:50:00+02:00'] + $session, $cohort, false],
			'ends before it starts' => [['endsAt' => '2026-09-29T08:00:00+02:00'] + $session, $cohort, false],
			'ends when it starts' => [['endsAt' => '2026-09-29T09:00:00+02:00'] + $session, $cohort, false],
			'no start' => [['startsAt' => ''] + $session, $cohort, false],
			'unreadable end' => [['endsAt' => 'garbage'] + $session, $cohort, false],
		];

		$actual = [];
		foreach ($cases as $name => [$lesson, $group, $down]) {
			$this->asked = [];
			$candidates = $this->service(timetableDown: $down)->forSession(session: $lesson, cohort: $group);
			$actual[$name] = ['candidates' => $candidates, 'asked' => $this->asked];
		}

		$actual = json_decode((string)json_encode($actual, JSON_UNESCAPED_SLASHES), true);
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		self::assertSame(expected: array_keys($golden), actual: array_keys($actual));
		foreach ($golden as $name => $expected) {
			self::assertSame(expected: $expected, actual: $actual[$name], message: $name);
		}
	}//end testCandidatesMatchGolden()

	/**
	 * The service over a fixed register, a fixed timetable and users that mostly exist.
	 *
	 * @param bool $timetableDown Whether the timetable throws.
	 *
	 * @return SubstitutionCandidateService
	 */
	private function service(bool $timetableDown): SubstitutionCandidateService {
		$rows = [
			'standby-slot' => [
				['teacherId' => 'stand', 'startsAt' => '08:30', 'endsAt' => '12:00', 'validFrom' => '2026-09-01', 'validUntil' => '2026-12-31', 'weekday' => 'tuesday'],
				['teacherId' => 'stand', 'startsAt' => '09:30', 'endsAt' => '10:30', 'validFrom' => '2026-09-01', 'validUntil' => '2026-12-31', 'weekday' => 'tuesday'],
				['teacherId' => 'busy-stand', 'startsAt' => '08:00', 'endsAt' => '11:00', 'validFrom' => '2026-09-01', 'validUntil' => '2026-12-31', 'weekday' => 'tuesday'],
				['teacherId' => 'absent', 'startsAt' => '08:00', 'endsAt' => '11:00', 'validFrom' => '2026-09-01', 'validUntil' => '2026-12-31', 'weekday' => 'tuesday'],
				['teacherId' => '', 'startsAt' => '08:00', 'endsAt' => '11:00', 'validFrom' => '2026-09-01', 'validUntil' => '2026-12-31', 'weekday' => 'tuesday'],
				['teacherId' => 'far', 'startsAt' => '08:00', 'endsAt' => '11:00', 'validFrom' => '2026-09-01', 'validUntil' => '2026-12-31', 'weekday' => 'tuesday', 'vestigingId' => 'v-9'],
				['teacherId' => 'once', 'startsAt' => '08:00', 'endsAt' => '16:00', 'validFrom' => '2026-09-01', 'validUntil' => '2026-12-31', 'date' => '2026-09-30'],
				['teacherId' => 'afternoon', 'startsAt' => '13:00', 'endsAt' => '16:00', 'validFrom' => '2026-09-01', 'validUntil' => '2026-12-31', 'weekday' => 'tuesday'],
			],
			'staff' => [
				['ncUserId' => 'zed', 'workingDays' => ['tuesday', 'wednesday']],
				['ncUserId' => 'amy', 'workingDays' => ['tuesday']],
				['ncUserId' => 'wed', 'workingDays' => ['wednesday']],
				['ncUserId' => 'stand', 'workingDays' => ['tuesday']],
				['ncUserId' => 'busy-stand', 'workingDays' => ['tuesday']],
				['ncUserId' => 'busy', 'workingDays' => ['tuesday']],
				['ncUserId' => 'absent', 'workingDays' => ['tuesday']],
				['ncUserId' => '', 'workingDays' => ['tuesday']],
				['ncUserId' => 'ghost', 'workingDays' => ['tuesday', 'wednesday']],
				['ncUserId' => 'sub', 'workingDays' => ['tuesday']],
				['ncUserId' => 'planner', 'workingDays' => ['tuesday']],
				['ncUserId' => 'cancelled', 'workingDays' => ['tuesday']],
				['ncUserId' => 'later', 'workingDays' => ['tuesday']],
				['ncUserId' => 'self', 'workingDays' => ['tuesday']],
				['ncUserId' => 'odd', 'workingDays' => ['tuesday']],
			],
			'cohort' => [
				['id' => 'c-1', 'teacherIds' => ['absent']],
				['id' => 'c-2', 'teacherIds' => ['busy', 'busy-stand']],
				['uuid' => 'c-3', 'teacherIds' => ['cancelled']],
				['id' => 'c-4', 'teacherIds' => ['later']],
				['id' => 'c-5', 'teacherIds' => ['self']],
				['id' => 'c-6', 'teacherIds' => ['odd']],
				['teacherIds' => ['nobody']],
			],
		];
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config) use ($rows): array {
				$schema = $config['filters']['schema'];
				return OrEntityFactory::makeMany(rows: ($rows[$schema] ?? []), schema: $schema);
			}
		);

		$lessons = [
			['id' => 'l-2', 'cohortId' => 'c-2', 'startsAt' => '2026-09-29T09:30:00+02:00', 'endsAt' => '2026-09-29T10:20:00+02:00', 'substituteTeacherId' => 'sub'],
			['id' => 'l-3', 'cohortId' => 'c-3', 'startsAt' => '2026-09-29T09:00:00+02:00', 'endsAt' => '2026-09-29T09:50:00+02:00', 'lifecycle' => 'cancelled'],
			['id' => 'l-4', 'cohortId' => 'c-4', 'startsAt' => '2026-09-29T09:50:00+02:00', 'endsAt' => '2026-09-29T10:40:00+02:00'],
			['id' => 'ses-1', 'cohortId' => 'c-5', 'startsAt' => '2026-09-29T09:00:00+02:00', 'endsAt' => '2026-09-29T09:50:00+02:00'],
			['id' => 'l-6', 'cohortId' => 'c-6', 'startsAt' => '', 'endsAt' => '2026-09-29T10:00:00+02:00'],
			['id' => 'l-7', 'cohortId' => 'c-x', 'startsAt' => '2026-09-29T08:30:00+02:00', 'endsAt' => '2026-09-29T09:10:00+02:00', 'teacherUserId' => 'planner'],
			['id' => 'l-8', 'startsAt' => '2026-09-29T08:30:00+02:00', 'endsAt' => '2026-09-29T09:10:00+02:00'],
		];
		$source = $this->createMock(originalClassName: TimetableSource::class);
		$source->method('sessionsForCohorts')->willReturnCallback(
			function (array $cohortIds, ?string $from, ?string $to) use ($lessons, $timetableDown): array {
				$this->asked[] = [$cohortIds, $from, $to];
				if ($timetableDown === true) {
					throw new RuntimeException('down');
				}

				return $lessons;
			}
		);
		$sources = $this->createMock(originalClassName: TimetableSourceResolver::class);
		$sources->method('current')->willReturn($source);

		$users = $this->createMock(originalClassName: IUserManager::class);
		$users->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				if ($uid === 'ghost') {
					return null;
				}

				$user = $this->createMock(originalClassName: IUser::class);
				$user->method('getDisplayName')->willReturn(strtoupper($uid));
				return $user;
			}
		);

		return new SubstitutionCandidateService(
			objectService: $objects,
			sources: $sources,
			userManager: $users,
			calendar: new StandbyCalendar($objects)
		);
	}//end service()
}//end class
