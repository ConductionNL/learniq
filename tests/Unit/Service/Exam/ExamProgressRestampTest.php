<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/changes/exam-committee-today-and-keuzedelen/specs/enrolment/spec.md#requirement-a-students-exam-progress-reads-in-words
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Exam;

use DateTimeImmutable;
use OCA\Learniq\Service\Exam\ExamProgressRestamp;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Learniq\Service\Exam\ExamProgressRestamp
 * @uses   \OCA\Learniq\Service\Exam\ExamProgress
 * @uses   \OCA\Learniq\Service\Portal\CourseDayLines
 */
class ExamProgressRestampTest extends TestCase {

	/**
	 * The saved profiles.
	 *
	 * @var list<array{object: array<string, mixed>, uuid: string|null}>
	 */
	private array $saved = [];

	/**
	 * The profile gets the lines from the programme's plan, the results and the cohort's sittings.
	 *
	 * @return void
	 */
	public function testTheProfileGetsTheLines(): void {
		$restamp = $this->restamp(['learner-profile' => [['id' => 'lp-1', 'ncUserId' => 'milan', 'givenName' => 'Milan']]]);

		self::assertTrue($restamp->restamp('milan', new DateTimeImmutable('2026-10-05T10:00:00+02:00')));

		self::assertCount(1, $this->saved);
		self::assertSame('lp-1', $this->saved[0]['uuid']);
		self::assertSame('Milan', $this->saved[0]['object']['givenName']);
		self::assertArrayNotHasKey('id', $this->saved[0]['object']);
		self::assertSame('1 van 2 examenonderdelen behaald', $this->saved[0]['object']['examProgressLabel']);
		self::assertSame('Volgende: NE-3F-CE op 3 november', $this->saved[0]['object']['nextExamLabel']);
		self::assertSame('s-1', $this->saved[0]['object']['nextExamSittingId']);
	}//end testTheProfileGetsTheLines()

	/**
	 * Unchanged lines are not written again; an unknown student is not written.
	 *
	 * @return void
	 */
	public function testNoWriteWithoutAChange(): void {
		$current = [
			'id' => 'lp-1', 'ncUserId' => 'milan', 'examComponentsPassed' => 1, 'examComponentsTotal' => 2,
			'examProgressLabel' => '1 van 2 examenonderdelen behaald', 'nextExamSittingId' => 's-1',
			'nextExamLabel' => 'Volgende: NE-3F-CE op 3 november',
		];
		$restamp = $this->restamp(['learner-profile' => [$current]]);

		self::assertFalse($restamp->restamp('milan', new DateTimeImmutable('2026-10-05T10:00:00+02:00')));
		self::assertFalse($restamp->restamp('someone', new DateTimeImmutable('2026-10-05T10:00:00+02:00')));
		self::assertFalse($restamp->restamp(''));
		self::assertSame([], $this->saved);
	}//end testNoWriteWithoutAChange()

	/**
	 * The restamp over an in-memory register.
	 *
	 * @param array<string, list<array<string, mixed>>> $overrides Rows per schema.
	 *
	 * @return ExamProgressRestamp
	 */
	private function restamp(array $overrides): ExamProgressRestamp {
		$store = $overrides + [
			'enrolment'       => [
				['id' => 'en-1', 'learnerId' => 'milan', 'programmeId' => 'prog-1', 'cohortId' => 'mt3a', 'lifecycle' => 'active'],
				['id' => 'en-2', 'learnerId' => 'milan', 'cohortId' => 'old', 'lifecycle' => 'withdrawn'],
			],
			'programme'       => [['id' => 'prog-1', 'curriculumPlanId' => 'plan-1']],
			'curriculum-plan' => [['id' => 'plan-1', 'components' => [['componentId' => 'a', 'kind' => 'assessment'], ['componentId' => 'b', 'kind' => 'assessment']]]],
			'grade-entry'     => [['id' => 'ge-1', 'learnerId' => 'milan', 'componentId' => 'a', 'value' => 7, 'lifecycle' => 'published']],
			'exam-sitting'    => [
				['id' => 's-1', 'cohortIds' => ['mt3a'], 'assessmentId' => 'as-1', 'startsAt' => '2026-11-03T09:00:00+01:00', 'lifecycle' => 'planned'],
				['id' => 's-2', 'cohortIds' => ['old'], 'assessmentId' => 'as-1', 'startsAt' => '2026-10-10T09:00:00+02:00', 'lifecycle' => 'planned'],
			],
			'assessment'      => [['id' => 'as-1', 'title' => 'NE-3F-CE']],
		];

		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use ($store): array {
				$schema = (string) $config['filters']['schema'];
				return array_map(static fn (array $row): ObjectEntity => OrEntityFactory::make($row, $schema), $store[$schema] ?? []);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options = new ObjectSaveOptions()): ObjectEntity {
				self::assertSame('learner-profile', $options->target->schema);
				$this->saved[] = ['object' => (array) $object, 'uuid' => $options->uuid];
				return OrEntityFactory::make((array) $object, 'learner-profile');
			}
		);

		return new ExamProgressRestamp($objects, new NullLogger());
	}//end restamp()
}//end class
