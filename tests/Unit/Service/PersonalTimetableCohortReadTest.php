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
use OCA\Learniq\Timetabling\Source\TimetableSource;
use OCA\Learniq\Timetabling\Source\TimetableSourceResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * A learner listed in a cohort's learnerIds reaches its lessons, although
 * OpenRegister would not let her read the Cohort row itself.
 *
 * Live on 25 Nov 2026 such a learner's /api/timetable/mine answered no
 * sessions: Cohort's read rule lets an authenticated user in only through
 * the cohort's ncGroupId, which no example set or import sets.
 *
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
 *
 * @covers \OCA\Learniq\Service\PersonalTimetableService
 * @uses   \OCA\Learniq\Service\TimetableProjector
 * @uses   \OCA\Learniq\Service\LessonNoteReader
 * @uses   \OCA\Learniq\Support\ObjectRow
 */
class PersonalTimetableCohortReadTest extends TestCase {

	/**
	 * The access flags each schema was read with.
	 *
	 * @var array<string, list<array{rbac: bool, multitenancy: bool}>>
	 */
	private array $reads = [];

	/**
	 * The cohort is found by membership, read without RBAC and with multitenancy; her own lesson comes back.
	 *
	 * @return void
	 */
	public function testALearnerInLearnerIdsGetsHerCohortsLessons(): void {
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$schema = $config['filters']['schema'];
				$this->reads[$schema][] = ['rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				if ($schema !== 'cohort') {
					return [];
				}

				// OpenRegister answers the RBAC-scoped read as it does live: no row for her.
				if ($_rbac === true) {
					return [];
				}

				return [
					['id' => 'c-mine', 'learnerIds' => ['learner-1'], 'teacherIds' => []],
					['id' => 'c-other', 'learnerIds' => ['learner-2'], 'teacherIds' => []],
				];
			}
		);

		$source = $this->createMock(originalClassName: TimetableSource::class);
		$source->method('name')->willReturn(value: 'learniq');
		$source->method('sessionsForTeacher')->willReturn(value: []);
		$lesson = [
			'id' => 's-1',
			'cohortId' => 'c-mine',
			'title' => 'Probe lesson',
			'startsAt' => '2026-11-25T08:00:00+00:00',
			'endsAt' => '2026-11-25T09:00:00+00:00',
		];
		$source->expects($this->once())->method('sessionsForCohorts')
			->with($this->identicalTo(value: ['c-mine']))
			->willReturn(value: [$lesson]);
		$resolver = $this->createMock(originalClassName: TimetableSourceResolver::class);
		$resolver->method('current')->willReturn(value: $source);

		$logger = new CapturingLogger();
		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$service = new PersonalTimetableService(
			objectService: $objects,
			projector: new TimetableProjector(
				logger: $logger,
				noteReader: new LessonNoteReader(objectService: $objects, groupManager: $groups, logger: $logger)
			),
			sources: $resolver,
			logger: $logger
		);

		$timetable = $service->forUser(uid: 'learner-1', windowFrom: '2026-11-23T00:00:00+00:00', windowTo: '2026-11-30T00:00:00+00:00');

		$this->assertSame(expected: ['Probe lesson'], actual: array_column(array: $timetable['sessions'], column_key: 'title'));
		$this->assertSame(expected: [['rbac' => false, 'multitenancy' => true]], actual: $this->reads['cohort']);
		// Her enrolments are still read as her.
		$this->assertSame(expected: true, actual: $this->reads['enrolment'][0]['rbac']);
	}//end testALearnerInLearnerIdsGetsHerCohortsLessons()
}//end class
