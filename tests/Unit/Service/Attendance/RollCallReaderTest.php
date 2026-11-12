<?php

/**
 * Tests for RollCallReader's saved marks and absence reports.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Attendance
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Attendance;

use OCA\Learniq\Service\Attendance\RollCallReader;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Pins which stored rows the roll call reads back, over a store that ignores the filters.
 */
class RollCallReaderTest extends TestCase {

	/**
	 * The queries the reader sent, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $queries = [];

	/**
	 * A reader whose store answers every query with the given rows.
	 *
	 * @param array<int, mixed> $rows The rows.
	 *
	 * @return RollCallReader
	 */
	private function reader(array $rows): RollCallReader {
		$this->queries = [];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use ($rows): array {
				$this->queries[] = [$config, $_rbac, $_multitenancy];
				return $rows;
			}
		);

		return new RollCallReader(objectService: $objectService);
	}//end reader()

	/**
	 * The marks of the lesson asked for, by pupil, the last one winning; rows
	 * of another lesson or without a pupil are skipped, and entities and rows
	 * without an id are read as rows with one.
	 *
	 * @return void
	 */
	public function testTheMarksOfOneLessonByPupil(): void {
		$reader = $this->reader(
			[
				['id' => 'r1', 'sessionId' => 's-1', 'learnerId' => 'ann', 'status' => 'present'],
				['id' => 'r2', 'sessionId' => 's-2', 'learnerId' => 'bob', 'status' => 'late'],
				['id' => 'r3', 'sessionId' => 's-1', 'learnerId' => '', 'status' => 'late'],
				OrEntityFactory::make(['id' => 'r4', 'sessionId' => 's-1', 'learnerId' => 'cas', 'status' => 'late'], 'attendance-record'),
				['id' => 'r5', 'sessionId' => 's-1', 'learnerId' => 'ann', 'status' => 'absent-excused'],
				'not a row',
			]
		);

		$records = $reader->recordsOf(sessionId: 's-1');

		self::assertSame(['ann', 'cas'], array_keys($records));
		self::assertSame(['r5', 'absent-excused'], [$records['ann']['id'], $records['ann']['status']]);
		self::assertSame('r4', $records['cas']['id']);
		self::assertSame(
			[[['filters' => ['register' => 'learniq', 'schema' => 'attendance-record', 'sessionId' => 's-1'], 'limit' => 1000], false, false]],
			$this->queries
		);
		self::assertSame([], $reader->recordsOf(sessionId: null));
		self::assertCount(1, $this->queries);
	}//end testTheMarksOfOneLessonByPupil()

	/**
	 * The report of each pupil asked for that covers the day: an approved one
	 * wins over an open one, whatever comes first; reports outside the day,
	 * of other pupils or in another state are skipped.
	 *
	 * @return void
	 */
	public function testTheReportsThatCoverTheDay(): void {
		$reader = $this->reader(
			[
				['id' => 'e1', 'learnerId' => 'ann', 'dateFrom' => '2026-10-01', 'dateTo' => '2026-10-03', 'lifecycle' => 'submitted'],
				['id' => 'e2', 'learnerId' => 'ann', 'dateFrom' => '2026-10-02T00:00:00Z', 'dateTo' => '2026-10-02T23:59:59Z', 'lifecycle' => 'approved'],
				['id' => 'e3', 'learnerId' => 'ann', 'dateFrom' => '2026-10-01', 'dateTo' => '2026-10-05', 'lifecycle' => 'submitted'],
				['id' => 'e4', 'learnerId' => 'bob', 'dateFrom' => '2026-10-03', 'dateTo' => '2026-10-04', 'lifecycle' => 'approved'],
				['id' => 'e5', 'learnerId' => 'bob', 'dateFrom' => '2026-10-01', 'dateTo' => '2026-10-02', 'lifecycle' => 'rejected'],
				['id' => 'e6', 'learnerId' => 'bob', 'dateFrom' => '2026-10-01', 'dateTo' => '2026-10-02', 'lifecycle' => 'submitted'],
				['id' => 'e7', 'learnerId' => 'bob', 'dateFrom' => '2026-10-02', 'dateTo' => '2026-10-02', 'lifecycle' => 'submitted'],
				['id' => 'e8', 'learnerId' => 'cas', 'dateFrom' => '2026-10-01', 'dateTo' => '2026-10-09', 'lifecycle' => 'approved'],
				['id' => 'e9', 'learnerId' => 'dirk', 'dateFrom' => '', 'dateTo' => '2026-10-09', 'lifecycle' => 'approved'],
			]
		);

		$reports = $reader->reportsOn(learnerIds: ['ann', 'bob', 'dirk'], date: '2026-10-02');

		self::assertSame(['ann' => 'e2', 'bob' => 'e7'], array_map(static fn (array $row): string => $row['id'], $reports));
		self::assertSame(
			[[['filters' => ['register' => 'learniq', 'schema' => 'excuse-request', 'learnerId' => ['ann', 'bob', 'dirk'], 'lifecycle' => ['approved', 'submitted']], 'limit' => 1000], false, false]],
			$this->queries
		);
		self::assertSame([], $reader->reportsOn(learnerIds: [], date: '2026-10-02'));
		self::assertCount(1, $this->queries);
	}//end testTheReportsThatCoverTheDay()
}//end class
