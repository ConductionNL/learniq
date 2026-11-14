<?php

/**
 * ReportSeenStamp unit tests: who counts as the first look, and what is written.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Attendance
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/absence-reports-seen-and-ended/specs/attendance/spec.md#requirement-a-teachers-first-look-at-an-absence-report-is-recorded
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Attendance;

use OCA\Learniq\Service\Attendance\ReportSeenStamp;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\Attendance\ReportSeenStamp
 */
class ReportSeenStampTest extends TestCase {

	/**
	 * The stamp writes seenBy and seenAt under the report's id, without its
	 * id fields in the body, with the server's own rights; unusable reports
	 * are left alone.
	 *
	 * @return void
	 */
	public function testOnlyAUsableOpenReportOfHerGroupIsStamped(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects(self::once())->method('saveObject')->with(
			['learnerId' => 'sami', 'lifecycle' => 'submitted', 'teacherIds' => ['esra'], 'seenBy' => 'esra', 'seenAt' => '2026-10-05T06:12:00+00:00'],
			[],
			'learniq',
			'excuse-request',
			'x-sami',
			false
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1791180720);

		$reports = [
			'sami' => ['id' => 'x-sami', '@self' => ['id' => 'x-sami'], 'learnerId' => 'sami', 'lifecycle' => 'submitted', 'teacherIds' => ['esra']],
			'lisa' => ['learnerId' => 'lisa', 'lifecycle' => 'submitted', 'teacherIds' => ['esra']],
			'tom'  => ['id' => 'x-tom', 'learnerId' => 'tom', 'lifecycle' => 'submitted', 'teacherIds' => 'esra'],
			'ella' => ['id' => 'x-ella', 'learnerId' => 'ella', 'lifecycle' => 'submitted', 'teacherIds' => ['esra'], 'seenAt' => '2026-10-04T10:00:00+00:00', 'seenBy' => 'bram'],
		];

		$after = (new ReportSeenStamp(objectService: $objects, time: $time))->seen(reports: $reports, userId: 'esra');

		self::assertSame('esra', $after['sami']['seenBy']);
		self::assertSame('2026-10-05T06:12:00+00:00', $after['sami']['seenAt']);
		self::assertSame($reports['lisa'], $after['lisa'], 'a report without an id cannot be written');
		self::assertSame($reports['tom'], $after['tom'], 'teacherIds must be a list');
		self::assertSame('bram', $after['ella']['seenBy'], 'the first look stays');
	}//end testOnlyAUsableOpenReportOfHerGroupIsStamped()

	/**
	 * Nobody signed in sees nothing.
	 *
	 * @return void
	 */
	public function testAnEmptyUserStampsNothing(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects(self::never())->method('saveObject');

		$reports = ['sami' => ['id' => 'x-sami', 'lifecycle' => 'submitted', 'teacherIds' => ['']]];
		$after = (new ReportSeenStamp(objectService: $objects, time: $this->createMock(ITimeFactory::class)))->seen(reports: $reports, userId: '');

		self::assertSame($reports, $after);
	}//end testAnEmptyUserStampsNothing()
}//end class
