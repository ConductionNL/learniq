<?php

/**
 * Characterisation tests for AttendanceWindowAggregator::buildAttendanceSummary().
 *
 * Pins the query, the window filter, every status counter, the unrecognised
 * status that counts toward the total only, and the attendance percentage.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\AttendanceWindowAggregator;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the per-learner attendance summary.
 */
class AttendanceWindowAggregatorSummaryTest extends TestCase {

	/**
	 * Recorded findAll configs.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $queries = [];

	/**
	 * The aggregator over the given attendance rows.
	 *
	 * @param array<int,mixed> $records Rows findAll returns.
	 *
	 * @return AttendanceWindowAggregator
	 */
	private function aggregator(array $records): AttendanceWindowAggregator {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($records): array {
				$this->queries[] = $config;
				return $records;
			}
		);

		return new AttendanceWindowAggregator($objects);
	}//end aggregator()

	/**
	 * No sessions: the empty summary and no query.
	 *
	 * @return void
	 */
	public function testNoSessionsIsTheEmptySummary(): void {
		self::assertSame(
			['presentCount' => 0, 'absentUnexcusedCount' => 0, 'absentExcusedCount' => 0, 'lateCount' => 0, 'leftEarlyCount' => 0, 'attendancePercent' => null],
			$this->aggregator([['sessionId' => 's1', 'status' => 'present']])->buildAttendanceSummary('lea', [])
		);
		self::assertSame([], $this->queries);
	}//end testNoSessionsIsTheEmptySummary()

	/**
	 * Only records of window sessions count; each status has its counter; an unknown one counts toward the total.
	 *
	 * @return void
	 */
	public function testCountsAndPercentage(): void {
		$entity = new class implements \JsonSerializable {
			/**
			 * Serialised record.
			 *
			 * @return array<string,mixed>
			 */
			public function jsonSerialize(): array {
				return ['sessionId' => 's2', 'status' => 'late'];
			}
		};
		$records = [
			['sessionId' => 's1', 'status' => 'present'],
			['sessionId' => 's1', 'status' => 'present'],
			$entity,
			['sessionId' => 's2', 'status' => 'left-early'],
			['sessionId' => 's3', 'status' => 'absent-unexcused'],
			['sessionId' => 's3', 'status' => 'absent-excused'],
			['sessionId' => 's3', 'status' => 'mystery'],
			['sessionId' => 's3'],
			['sessionId' => 'outside', 'status' => 'present'],
			['status' => 'present'],
			['sessionId' => '', 'status' => 'present'],
		];

		$summary = $this->aggregator($records)->buildAttendanceSummary('lea', ['s1', 's2', 's3']);

		self::assertSame(
			['presentCount' => 2, 'absentUnexcusedCount' => 1, 'absentExcusedCount' => 1, 'lateCount' => 1, 'leftEarlyCount' => 1, 'attendancePercent' => 50.0],
			$summary
		);
		self::assertSame(
			[['filters' => ['register' => 'learniq', 'schema' => 'attendance-record', 'learnerId' => 'lea'], 'limit' => 5000]],
			$this->queries
		);
	}//end testCountsAndPercentage()

	/**
	 * The percentage rounds to two decimals; no record in the window leaves it null.
	 *
	 * @return void
	 */
	public function testRoundingAndNoRecordInWindow(): void {
		$records = [
			['sessionId' => 's1', 'status' => 'present'],
			['sessionId' => 's1', 'status' => 'absent-unexcused'],
			['sessionId' => 's1', 'status' => 'absent-unexcused'],
		];
		self::assertSame(33.33, $this->aggregator($records)->buildAttendanceSummary('lea', ['s1'])['attendancePercent']);
		self::assertNull($this->aggregator($records)->buildAttendanceSummary('lea', ['s9'])['attendancePercent']);
	}//end testRoundingAndNoRecordInWindow()
}//end class
