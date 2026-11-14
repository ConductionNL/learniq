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

	/**
	 * Allowed leave of the pupils asked for, matched through their profiles, reads as an
	 * approved report of the day; an approved report of the same pupil wins over it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leave-requests-decided-by-the-school/tasks.md
	 */
	public function testAllowedLeaveReadsAsAnApprovedReport(): void {
		$this->queries = [];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->queries[] = [$config['filters'], $_rbac];
				return match ($config['filters']['schema']) {
					'learner-profile' => [['id' => 'p-ann', 'ncUserId' => 'ann'], ['id' => 'p-bob', 'ncUserId' => 'bob']],
					'leave-request' => [
						['id' => 'l1', 'learnerRef' => 'p-ann', 'dateFrom' => '2026-10-01', 'dateTo' => '2026-10-02', 'kind' => 'funeral', 'reason' => 'Uitvaart', 'lifecycle' => 'allowed'],
						['id' => 'l2', 'learnerRef' => 'p-bob', 'dateFrom' => '2026-10-05', 'dateTo' => '2026-10-06', 'kind' => 'wedding', 'reason' => 'Later', 'lifecycle' => 'allowed'],
						['id' => 'l3', 'learnerRef' => 'p-bob', 'dateFrom' => '2026-10-02', 'dateTo' => '2026-10-02', 'kind' => 'wedding', 'reason' => 'Open', 'lifecycle' => 'submitted'],
						['id' => 'l4', 'learnerRef' => 'p-other', 'dateFrom' => '2026-10-02', 'dateTo' => '2026-10-02', 'kind' => 'other', 'reason' => 'Niet van ons', 'lifecycle' => 'allowed'],
					],
					default => [],
				};
			}
		);
		$reader = new RollCallReader(objectService: $objectService);

		$leave = $reader->allowedLeaveOn(learnerIds: ['ann', 'bob'], date: '2026-10-02');

		self::assertSame(
			['ann' => ['id' => 'l1', 'learnerId' => 'ann', 'lifecycle' => 'approved', 'reasonKind' => 'bereavement', 'reason' => 'Uitvaart', 'dateFrom' => '2026-10-01', 'dateTo' => '2026-10-02', 'leaveRequestId' => 'l1']],
			$leave
		);
		self::assertSame(['register' => 'learniq', 'schema' => 'leave-request', 'learnerRef' => ['p-ann', 'p-bob'], 'lifecycle' => 'allowed'], $this->queries[1][0]);
		self::assertFalse($this->queries[1][1]);
		self::assertSame([], $reader->allowedLeaveOn(learnerIds: [], date: '2026-10-02'));

		$approved = ['ann' => ['id' => 'e1', 'lifecycle' => 'approved'], 'bob' => ['id' => 'e2', 'lifecycle' => 'submitted']];
		$merged = RollCallReader::withLeave(reports: $approved, leave: ['ann' => ['id' => 'l1'], 'bob' => ['id' => 'l9'], 'cas' => ['id' => 'l8']]);
		self::assertSame(['ann' => 'e1', 'bob' => 'l9', 'cas' => 'l8'], array_map(static fn (array $row): string => $row['id'], $merged));
	}//end testAllowedLeaveReadsAsAnApprovedReport()
}//end class
