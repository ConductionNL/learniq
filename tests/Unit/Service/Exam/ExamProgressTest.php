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
use DateTimeZone;
use OCA\Learniq\Service\Exam\ExamProgress;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\Exam\ExamProgress
 * @uses   \OCA\Learniq\Service\Portal\CourseDayLines
 */
class ExamProgressTest extends TestCase {

	/**
	 * Milan: 3 of 11 passed, NE-3F-CE on 3 November.
	 *
	 * @return void
	 */
	public function testMilan(): void {
		$components = [];
		for ($i = 1; $i <= 11; $i++) {
			$components[] = ['componentId' => 'ex-' . $i, 'label' => 'Examen ' . $i, 'weight' => 1, 'period' => 1, 'kind' => 'assessment'];
		}

		$components[] = ['componentId' => 'opdracht-1', 'label' => 'Opdracht', 'weight' => 1, 'period' => 1, 'kind' => 'assignment'];
		$plan = ['id' => 'plan-1', 'components' => $components, 'passRules' => [['componentId' => 'ex-4', 'minValue' => 7.0]]];

		$entries = [
			['componentId' => 'ex-1', 'value' => 6.0, 'lifecycle' => 'published', 'curriculumPlanId' => 'plan-1'],
			['componentId' => 'ex-2', 'value' => 5.5, 'lifecycle' => 'published'],
			['componentId' => 'ex-3', 'value' => 8, 'lifecycle' => 'published'],
			['componentId' => 'ex-3', 'value' => 9, 'lifecycle' => 'published'],
			['componentId' => 'ex-4', 'value' => 6.5, 'lifecycle' => 'published'],
			['componentId' => 'ex-5', 'value' => 9.0, 'lifecycle' => 'concept'],
			['componentId' => 'ex-6', 'value' => 9.0, 'lifecycle' => 'published', 'curriculumPlanId' => 'other-plan'],
			['componentId' => 'opdracht-1', 'value' => 9.0, 'lifecycle' => 'published'],
			['componentId' => 'ex-7', 'value' => 4.0, 'lifecycle' => 'published'],
		];
		$sittings = [
			['id' => 's-past', 'startsAt' => '2026-10-01T09:00:00+02:00', 'lifecycle' => 'planned', 'examCode' => 'OLD'],
			['id' => 's-later', 'startsAt' => '2026-11-20T09:00:00+01:00', 'lifecycle' => 'planned', 'examCode' => 'EN-B1'],
			['id' => 's-next', 'startsAt' => '2026-11-03T09:00:00+01:00', 'lifecycle' => 'planned', 'examCode' => 'NE-3F-CE'],
			['id' => 's-cancelled', 'startsAt' => '2026-10-20T09:00:00+02:00', 'lifecycle' => 'cancelled', 'examCode' => 'X'],
		];

		$result = (new ExamProgress())->derive(
			$plan,
			$entries,
			$sittings,
			new DateTimeImmutable('2026-10-05T10:00:00', new DateTimeZone('Europe/Amsterdam'))
		);

		self::assertSame(
			expected: [
				'examComponentsPassed' => 3,
				'examComponentsTotal'  => 11,
				'examProgressLabel'    => '3 van 11 examenonderdelen behaald',
				'nextExamSittingId'    => 's-next',
				'nextExamLabel'        => 'Volgende: NE-3F-CE op 3 november',
			],
			actual: $result
		);
	}//end testMilan()

	/**
	 * Without an exam plan or a coming sitting the lines are empty.
	 *
	 * @return void
	 */
	public function testNothingToSay(): void {
		$result = (new ExamProgress())->derive([], [], [], new DateTimeImmutable('2026-10-05'));

		self::assertSame(
			expected: [
				'examComponentsPassed' => null,
				'examComponentsTotal' => null,
				'examProgressLabel' => null,
				'nextExamSittingId' => null,
				'nextExamLabel' => null
			],
			actual: $result
		);
	}//end testNothingToSay()

	/**
	 * The overall pass rule applies when a component has none; a sitting without a code still reads.
	 *
	 * @return void
	 */
	public function testOverallRuleAndASittingWithoutCode(): void {
		$plan = [
			'components' => [['componentId' => 'a', 'kind' => 'assessment'], ['componentId' => 'b', 'kind' => 'assessment']],
			'passRules'  => [['componentId' => null, 'minValue' => 6.0]],
		];
		$entries = [
			['componentId' => 'a', 'value' => 5.8, 'lifecycle' => 'published'],
			['componentId' => 'b', 'value' => 6.0, 'lifecycle' => 'published']
		];

		$result = (new ExamProgress())->derive(
			$plan,
			$entries,
			[['id' => 's', 'startsAt' => '2026-10-16T09:00:00+02:00']],
			new DateTimeImmutable('2026-10-05')
		);

		self::assertSame(expected: '1 van 2 examenonderdelen behaald', actual: $result['examProgressLabel']);
		self::assertSame(expected: 'Volgende: examen op 16 oktober', actual: $result['nextExamLabel']);
	}//end testOverallRuleAndASittingWithoutCode()
}//end class
