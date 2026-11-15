<?php

/**
 * LocalSessionTimetableSource scope tests: what each read asks for and what it lets through.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Timetabling\Source
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

namespace OCA\Learniq\Tests\Unit\Timetabling\Source;

use OCA\Learniq\Support\ObjectRow;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Timetabling\Source\LocalSessionTimetableSource;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The local session source over a mocked ObjectService.
 */
#[CoversClass(LocalSessionTimetableSource::class)]
#[UsesClass(ObjectRow::class)]
class LocalSessionTimetableSourceScopeTest extends TestCase {

	/**
	 * The findAll configs the source sent.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $asked = [];

	/**
	 * A source whose ObjectService answers every query with the given rows.
	 *
	 * @param list<mixed> $rows The rows.
	 *
	 * @return LocalSessionTimetableSource
	 */
	private function source(array $rows): LocalSessionTimetableSource {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config, bool $_rbac = true) use ($rows): array {
				$this->asked[] = $config;

				return $rows;
			}
		);

		return new LocalSessionTimetableSource(objectService: $objects);
	}//end source()

	/**
	 * The source is named after the app.
	 *
	 * @return void
	 */
	public function testTheSourceIsNamedLearniq(): void {
		self::assertSame('learniq', $this->source(rows: [])->name());
	}//end testTheSourceIsNamedLearniq()

	/**
	 * A repeated cohort id is read once, sorted by start, and every row is tagged with the source.
	 *
	 * @return void
	 */
	public function testARepeatedCohortIsReadOnce(): void {
		$rows = $this->source(rows: [['id' => 's-1', 'cohortId' => 'c-1']])->sessionsForCohorts(cohortIds: ['c-1', 'c-1', ''], from: '2026-01-01', to: '2026-12-31');

		self::assertSame([['id' => 's-1', 'cohortId' => 'c-1', 'source' => 'learniq']], $rows);
		self::assertSame(
			[['filters' => ['register' => 'learniq', 'schema' => 'session', 'cohortId' => 'c-1'], 'sort' => ['startsAt' => 'ASC']]],
			$this->asked
		);
	}//end testARepeatedCohortIsReadOnce()

	/**
	 * A teacher's read asks for the lessons they cover, marks them as cover, and drops anyone else's.
	 *
	 * @return void
	 */
	public function testATeacherGetsOnlyTheLessonsTheyCover(): void {
		$rows = $this->source(rows: OrEntityFactory::makeMany(
			[
				['id' => 's-1', 'substituteTeacherId' => 'eva'],
				['id' => 's-2', 'substituteTeacherId' => 'jan'],
				['id' => 's-3'],
			],
			'session'
		))->sessionsForTeacher(userId: 'eva', from: null, to: null);

		self::assertSame(['s-1'], array_column($rows, 'id'));
		self::assertTrue($rows[0]['cover']);
		self::assertSame('learniq', $rows[0]['source']);
		self::assertSame(['register' => 'learniq', 'schema' => 'session', 'substituteTeacherId' => 'eva'], $this->asked[0]['filters']);
	}//end testATeacherGetsOnlyTheLessonsTheyCover()

	/**
	 * Without a user id no lessons are read.
	 *
	 * @return void
	 */
	public function testWithoutAUserNothingIsRead(): void {
		self::assertSame([], $this->source(rows: [['id' => 's-1', 'substituteTeacherId' => '']])->sessionsForTeacher(userId: '', from: null, to: null));
		self::assertSame([], $this->asked);
	}//end testWithoutAUserNothingIsRead()

	/**
	 * A course read is per course, once per id, and drops another course's lessons.
	 *
	 * @return void
	 */
	public function testACourseReadIsPerCourseAndKeepsOnlyItsLessons(): void {
		$rows = $this->source(rows: [['id' => 's-1', 'courseId' => 'k-1'], ['id' => 's-2', 'courseId' => 'k-2'], 'not a row'])
			->sessionsForCourses(courseIds: ['k-1', 'k-1'], from: null, to: null);

		self::assertSame([['id' => 's-1', 'courseId' => 'k-1', 'source' => 'learniq']], $rows);
		self::assertCount(1, $this->asked);
		self::assertSame(['register' => 'learniq', 'schema' => 'session', 'courseId' => 'k-1'], $this->asked[0]['filters']);
	}//end testACourseReadIsPerCourseAndKeepsOnlyItsLessons()
}//end class
