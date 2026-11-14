<?php

/**
 * ProgrammeProgress: who is looked up, what is not counted, how parts are named.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Programme
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

namespace OCA\Learniq\Tests\Unit\Service\Programme;

use OCA\Learniq\Service\Programme\ProgrammeProgress;
use OCA\Learniq\Support\SerialisedRow;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Edges of a learner's programme progress over a mocked ObjectService.
 */
#[CoversClass(ProgrammeProgress::class)]
#[UsesClass(SerialisedRow::class)]
class ProgrammeProgressEdgesTest extends TestCase {

	/**
	 * Records by schema and id that find() answers.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $records = [];

	/**
	 * An ObjectService answering the given enrolments and $this->records.
	 *
	 * @param array<int, mixed> $enrolments The rows findAll() returns.
	 *
	 * @return ObjectService&MockObject
	 */
	private function objects(array $enrolments): ObjectService&MockObject {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturn($enrolments);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null): ObjectEntity {
				$row = ($this->records[(string)$schema][(string)$id] ?? null);
				if ($row === null) {
					throw new DoesNotExistException('gone');
				}

				return OrEntityFactory::make($row, (string)$schema);
			}
		);

		return $objects;
	}//end objects()

	/**
	 * An enrolment of learner u-1 in programme p-1.
	 *
	 * @param string $courseId  The course.
	 * @param string $lifecycle The lifecycle.
	 *
	 * @return array<string, mixed>
	 */
	private static function enrolment(string $courseId, string $lifecycle): array {
		return ['learnerId' => 'u-1', 'programmeId' => 'p-1', 'courseId' => $courseId, 'lifecycle' => $lifecycle];
	}//end enrolment()

	/**
	 * Without a user id nothing is looked up.
	 *
	 * @return void
	 */
	public function testWithoutAUserNothingIsLookedUp(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects($this->never())->method('findAll');

		self::assertSame([], (new ProgrammeProgress(objects: $objects))->forLearner(userId: ''));
	}//end testWithoutAUserNothingIsLookedUp()

	/**
	 * The learner's enrolments are read from the app's register, up to 1000, without RBAC.
	 *
	 * @return void
	 */
	public function testTheLearnersEnrolmentsAreAskedFor(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects($this->once())->method('findAll')
			->with(['filters' => ['register' => 'learniq', 'schema' => 'enrolment', 'learnerId' => 'u-1'], 'limit' => 1000], false)
			->willReturn([]);

		self::assertSame([], (new ProgrammeProgress(objects: $objects))->forLearner(userId: 'u-1'));
	}//end testTheLearnersEnrolmentsAreAskedFor()

	/**
	 * A withdrawn part is left out; a failed one counts as not completed.
	 *
	 * @return void
	 */
	public function testAWithdrawnPartIsLeftOutAndAFailedOneCounts(): void {
		$this->records['programme']['p-1'] = ['id' => 'p-1', 'name' => 'Basics'];
		$rows = [self::enrolment('c-1', 'completed'), self::enrolment('c-2', 'withdrawn'), self::enrolment('c-3', 'failed')];

		$summary = (new ProgrammeProgress(objects: $this->objects(enrolments: $rows)))->forLearner(userId: 'u-1')[0];

		self::assertSame('Basics', $summary['name']);
		self::assertSame(2, $summary['mandatoryTotal']);
		self::assertSame(1, $summary['mandatoryCompleted']);
		self::assertSame(50, $summary['percent']);
		self::assertFalse($summary['complete']);
		self::assertSame(['c-1', 'c-3'], array_column($summary['mandatory'], 'courseId'));
	}//end testAWithdrawnPartIsLeftOutAndAFailedOneCounts()

	/**
	 * The percentage is rounded to the nearest whole number.
	 *
	 * @return void
	 */
	public function testThePercentageIsRounded(): void {
		$rows = [self::enrolment('c-1', 'completed'), self::enrolment('c-2', 'completed'), self::enrolment('c-3', 'active')];

		$summary = (new ProgrammeProgress(objects: $this->objects(enrolments: $rows)))->forLearner(userId: 'u-1')[0];

		self::assertSame(67, $summary['percent']);
		self::assertSame('', $summary['name']);
	}//end testThePercentageIsRounded()

	/**
	 * A part is named by the course's name, else its title, else its id.
	 *
	 * @return void
	 */
	public function testAPartIsNamedByNameThenTitleThenId(): void {
		$this->records['course']['c-1'] = ['id' => 'c-1', 'name' => 'Named', 'title' => 'Titled'];
		$this->records['course']['c-2'] = ['id' => 'c-2', 'title' => 'Titled only'];
		$rows = [self::enrolment('c-1', 'active'), self::enrolment('c-2', 'active'), self::enrolment('c-3', 'active')];

		$summary = (new ProgrammeProgress(objects: $this->objects(enrolments: $rows)))->forLearner(userId: 'u-1')[0];

		self::assertSame(['Named', 'Titled only', 'c-3'], array_column($summary['mandatory'], 'courseName'));
	}//end testAPartIsNamedByNameThenTitleThenId()

	/**
	 * Another learner's row, or one without a programme, is not counted.
	 *
	 * @return void
	 */
	public function testRowsOfOthersOrWithoutAProgrammeAreSkipped(): void {
		$rows = [
			['learnerId' => 'u-2', 'programmeId' => 'p-1', 'courseId' => 'c-1', 'lifecycle' => 'completed'],
			['learnerId' => 'u-1', 'programmeId' => '', 'courseId' => 'c-1', 'lifecycle' => 'completed'],
			['learnerId' => 'u-1', 'courseId' => 'c-1', 'lifecycle' => 'completed'],
		];

		self::assertSame([], (new ProgrammeProgress(objects: $this->objects(enrolments: $rows)))->forLearner(userId: 'u-1'));
	}//end testRowsOfOthersOrWithoutAProgrammeAreSkipped()
}//end class
