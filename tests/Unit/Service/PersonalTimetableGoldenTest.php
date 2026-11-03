<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\LessonNoteReader;
use OCA\Learniq\Service\PersonalTimetableService;
use OCA\Learniq\Service\TimetableProjector;
use OCA\Learniq\Tests\Support\CapturingLogger;
use OCA\Learniq\Tests\Support\GoldenFile;
use OCA\Learniq\Timetabling\Source\TimetableSource;
use OCA\Learniq\Timetabling\Source\TimetableSourceResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * Pins forUser() end to end through the real projector and note reader
 * against golden/PersonalTimetable.json: the lessons of each caller, the
 * notes they read, where they may add one, and every user the group
 * manager and the source were asked about. The group manager says yes only
 * for the user it is asked about.
 *
 * @covers \OCA\Learniq\Service\PersonalTimetableService
 * @covers \OCA\Learniq\Service\TimetableProjector
 * @uses   \OCA\Learniq\Service\LessonNoteReader
 */
class PersonalTimetableGoldenTest extends TestCase {
	use GoldenFile;

	private const GOLDEN = __DIR__ . '/golden/PersonalTimetable.json';

	/**
	 * Who was asked what, in order.
	 *
	 * @var list<mixed>
	 */
	private array $asked = [];

	/**
	 * Every caller gets the timetable that was recorded.
	 *
	 * @return void
	 */
	public function testForUserMatchesGolden(): void {
		$logger = new CapturingLogger();
		$service = new PersonalTimetableService($this->objects(), new TimetableProjector(logger: $logger, noteReader: new LessonNoteReader($this->objects(), $this->groupManager(), $logger)), $this->resolver(), $logger);

		$actual = [];
		foreach (['anna', 'jan', 'piet', 'sub', 'lead', 'nobody'] as $uid) {
			$this->asked = [];
			$timetable = $service->forUser(uid: $uid, windowFrom: '2026-11-02T00:00:00+00:00', windowTo: '2026-11-09T00:00:00+00:00');
			// todaysChanges() reads the clock; it is not what this golden pins.
			unset($timetable['changes']);
			$actual['timetable of ' . $uid] = ['timetable' => $timetable, 'asked' => $this->asked];
		}

		$this->assertMatchesGolden(file: self::GOLDEN, actual: $actual);
	}//end testForUserMatchesGolden()

	/**
	 * Cohorts, enrolments, rooms and notes.
	 *
	 * @return ObjectService
	 */
	private function objects(): ObjectService {
		$store = [
			'cohort' => [
				['id' => 'c-1', 'name' => '4H1', 'teacherIds' => ['jan'], 'learnerIds' => ['anna']],
				['id' => 'c-2', 'name' => '5V2', 'teacherIds' => ['piet'], 'learnerIds' => ['bert', 'lead']],
			],
			'enrolment' => [
				['learnerId' => 'anna', 'cohortId' => 'c-2', 'lifecycle' => 'active'],
				['learnerId' => 'anna', 'courseId' => 'k-9', 'lifecycle' => 'active'],
				['learnerId' => 'jan', 'cohortId' => 'c-2', 'lifecycle' => 'withdrawn'],
			],
			'room' => [['id' => 'r-1', 'name' => 'Lokaal 1', 'code' => 'A1']],
			'lesson-note' => [
				['id' => 'n-1', 'cohortId' => 'c-1', 'sessionId' => 's-1', 'text' => 'Boek mee'],
				['id' => 'n-2', 'cohortId' => 'c-1', 'sessionId' => 's-1', 'audience' => 'cover', 'text' => 'Toets in la'],
				['id' => 'n-3', 'cohortId' => 'c-2', 'sessionId' => 's-3', 'audience' => 'cover', 'text' => 'Invaller'],
			],
		];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config) use ($store): array {
				$filters = $config['filters'];
				$rows = ($store[$filters['schema']] ?? []);
				unset($filters['register'], $filters['schema']);
				return array_values(array_filter($rows, static fn (array $row): bool => array_intersect_key($row, $filters) == $filters));
			}
		);

		return $objects;
	}//end objects()

	/**
	 * A group manager that says yes only for the user named in the question.
	 *
	 * @return IGroupManager
	 */
	private function groupManager(): IGroupManager {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(
			function (string $uid): bool {
				$this->asked[] = ['isAdmin' => $uid];
				return false;
			}
		);
		$groups->method('isInGroup')->willReturnCallback(
			function (string $uid, string $group): bool {
				$this->asked[] = ['isInGroup' => [$uid, $group]];
				return $uid . ':' . $group === 'lead:team-leads';
			}
		);

		return $groups;
	}//end groupManager()

	/**
	 * A source with lessons for cohorts c-1 and c-2, a cover lesson for 'sub' and a course lesson.
	 *
	 * @return TimetableSourceResolver
	 */
	private function resolver(): TimetableSourceResolver {
		$lessons = [
			'c-1' => [
				['id' => 's-1', 'cohortId' => 'c-1', 'title' => 'Wiskunde', 'startsAt' => '2026-11-03T09:00:00+00:00', 'endsAt' => '2026-11-03T09:50:00+00:00', 'roomId' => 'r-1', 'teacherUserId' => 'jan'],
				['id' => 's-2', 'cohortId' => 'c-1', 'title' => 'Buiten venster', 'startsAt' => '2026-11-12T09:00:00+00:00', 'endsAt' => '2026-11-12T09:50:00+00:00'],
			],
			'c-2' => [
				['id' => 's-3', 'cohortId' => 'c-2', 'title' => 'Engels', 'startsAt' => '2026-11-04T10:00:00+00:00', 'endsAt' => '2026-11-04T10:50:00+00:00', 'substituteTeacherId' => 'sub', 'lifecycle' => 'changed'],
			],
		];
		$source = $this->createMock(TimetableSource::class);
		$source->method('name')->willReturn('fake');
		$source->method('sessionsForCohorts')->willReturnCallback(
			function (array $cohortIds, ?string $from, ?string $to) use ($lessons): array {
				$this->asked[] = ['sessionsForCohorts' => [$cohortIds, $from, $to]];
				return array_merge(...array_map(static fn (string $id): array => ($lessons[$id] ?? []), array_values($cohortIds)), ...[[]]);
			}
		);
		$source->method('sessionsForTeacher')->willReturnCallback(
			function (string $userId, ?string $from, ?string $to) use ($lessons): array {
				$this->asked[] = ['sessionsForTeacher' => $userId];
				if ($userId === 'sub') {
					return [$lessons['c-2'][0] + ['cover' => true]];
				}

				return [];
			}
		);
		$source->method('sessionsForCourses')->willReturnCallback(
			function (array $courseIds, ?string $from, ?string $to): array {
				$this->asked[] = ['sessionsForCourses' => $courseIds];
				return [['id' => 's-9', 'courseId' => 'k-9', 'title' => 'Keuzevak', 'startsAt' => '2026-11-05T13:00:00+00:00', 'endsAt' => '2026-11-05T13:50:00+00:00']];
			}
		);
		$resolver = $this->createMock(TimetableSourceResolver::class);
		$resolver->method('current')->willReturn($source);

		return $resolver;
	}//end resolver()
}//end class
