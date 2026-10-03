<?php

/**
 * ContactHoursService: owed from the hour plan, given from held lessons,
 * attended from the register, and the shortfalls.
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
 *
 * @spec openspec/specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\ContactHoursReader;
use OCA\Learniq\Service\ContactHoursService;
use OCA\Learniq\Service\HourPlanActivityService;
use OCA\Learniq\Timetabling\ContactHoursCalendar;
use OCA\Learniq\Timetabling\Source\TimetableSource;
use OCA\Learniq\Timetabling\Source\TimetableSourceResolver;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * MV2A: sixty hours of English owed, three lessons cancelled, one learner at seventy percent of Marketing.
 */
class ContactHoursServiceTest extends TestCase {

	/**
	 * The service over MV2A.
	 *
	 * @param bool $withPlan Whether MV2A has an active hour plan.
	 *
	 * @return ContactHoursService
	 */
	private function service(bool $withPlan=true): ContactHoursService {
		$reader = $this->createMock(ContactHoursReader::class);
		$reader->method('cohorts')->willReturn([['id' => 'mv2a', 'name' => 'MV2A', 'academicYear' => '2026-2027', 'learnerIds' => ['r.visser', 's.bos']]]);
		$reader->method('courseNames')->willReturn(['eng' => 'Engels', 'mkt' => 'Marketing']);
		$reader->method('reportPeriods')->willReturn([
			['periodCode' => '1', 'startDate' => '2026-09-01', 'endDate' => '2026-11-06', 'holidays' => [['name' => 'Herfstvakantie', 'startDate' => '2026-10-19', 'endDate' => '2026-10-23']]],
			['periodCode' => '2', 'startDate' => '2026-11-09', 'endDate' => '2027-01-29', 'holidays' => []],
			['periodCode' => '3', 'startDate' => '2027-02-01', 'endDate' => '2027-07-09', 'holidays' => []],
		]);

		$sessions = [];
		for ($i = 0; $i < 60; $i++) {
			$day = date('Y-m-d', strtotime('2026-09-07 +'.$i.' days'));
			$sessions[] = ['id' => 'eng-'.$i, 'cohortId' => 'mv2a', 'courseId' => 'eng', 'startsAt' => $day.'T09:00:00+02:00', 'endsAt' => $day.'T10:00:00+02:00', 'lifecycle' => ($i < 3) ? 'cancelled' : 'completed'];
		}

		for ($i = 0; $i < 40; $i++) {
			$day = date('Y-m-d', strtotime('2026-09-07 +'.$i.' days'));
			$sessions[] = ['id' => 'mkt-'.$i, 'cohortId' => 'mv2a', 'subject' => 'Marketing', 'startsAt' => $day.'T11:00:00+02:00', 'endsAt' => $day.'T12:00:00+02:00', 'lifecycle' => 'completed'];
		}

		// A lesson outside the window does not count.
		$sessions[] = ['id' => 'eng-late', 'cohortId' => 'mv2a', 'courseId' => 'eng', 'startsAt' => '2027-03-01T09:00:00+01:00', 'endsAt' => '2027-03-01T10:00:00+01:00'];

		$records = [];
		for ($i = 0; $i < 40; $i++) {
			$records[] = ['sessionId' => 'mkt-'.$i, 'cohortId' => 'mv2a', 'learnerId' => 'r.visser', 'status' => ($i < 28) ? 'present' : 'absent-unexcused', 'lesuren' => 1];
			$records[] = ['sessionId' => 'mkt-'.$i, 'cohortId' => 'mv2a', 'learnerId' => 's.bos', 'status' => 'present', 'lesuren' => 1];
		}

		$reader->method('attendance')->willReturn($records);

		$plans = $this->createMock(HourPlanActivityService::class);
		$plans->method('forYear')->willReturn([
			'activities' => $withPlan === true ? [
				['cohortId' => 'mv2a', 'courseId' => 'eng', 'periodCode' => '1', 'contactHours' => 30],
				['cohortId' => 'mv2a', 'courseId' => 'eng', 'periodCode' => '2', 'contactHours' => 30],
				['cohortId' => 'mv2a', 'courseId' => 'eng', 'periodCode' => '3', 'contactHours' => 30],
				['cohortId' => 'mv2a', 'courseId' => 'mkt', 'periodCode' => '1', 'contactHours' => 40],
			] : [],
			'cohortsWithoutPlan' => [],
		]);

		$source = $this->createMock(TimetableSource::class);
		$source->method('sessionsForCohorts')->willReturn($sessions);
		$sources = $this->createMock(TimetableSourceResolver::class);
		$sources->method('current')->willReturn($source);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturn(10);

		return new ContactHoursService($reader, $plans, $sources, new ContactHoursCalendar(), $config);
	}//end service()

	/**
	 * Three cancelled lessons leave English three hours short.
	 *
	 * @return void
	 */
	public function testCancelledLessonsAreNotGiven(): void {
		$cohort = $this->service()->forPeriod('2026-09-01', '2027-01-29')['cohorts'][0];
		$english = $cohort['courses'][0];

		self::assertSame('Engels', $english['courseName']);
		self::assertSame([60.0, 57.0, -3.0, true], [$english['owed'], $english['given'], $english['difference'], $english['short']]);
		self::assertTrue($cohort['hasHourPlan']);
	}//end testCancelledLessonsAreNotGiven()

	/**
	 * A planninq lesson without a course id is matched by its subject; a learner at 70 percent is marked.
	 *
	 * @return void
	 */
	public function testALearnerAtSeventyPercentIsMarked(): void {
		$cohort = $this->service()->forPeriod('2026-09-01', '2027-01-29')['cohorts'][0];
		$marketing = $cohort['courses'][1];
		self::assertSame([40.0, 40.0, false], [$marketing['owed'], $marketing['given'], $marketing['short']]);

		$visser = $cohort['learners'][0];
		$mkt = array_values(array_filter($visser['courses'], static fn (array $row): bool => $row['courseId'] === 'mkt'))[0];
		self::assertSame([28.0, 40.0, true], [$mkt['attended'], $mkt['given'], $mkt['below']]);
		self::assertTrue($visser['below']);
		self::assertFalse(array_values(array_filter($cohort['learners'][1]['courses'], static fn (array $row): bool => $row['courseId'] === 'mkt'))[0]['below']);
	}//end testALearnerAtSeventyPercentIsMarked()

	/**
	 * Without an hour plan, owed is missing, not zero.
	 *
	 * @return void
	 */
	public function testNoHourPlanMeansOwedIsMissing(): void {
		$cohort = $this->service(false)->forPeriod('2026-09-01', '2027-01-29')['cohorts'][0];

		self::assertFalse($cohort['hasHourPlan']);
		self::assertNull($cohort['courses'][0]['owed']);
		self::assertNull($cohort['totals']['owed']);
		self::assertFalse($cohort['courses'][0]['short']);
	}//end testNoHourPlanMeansOwedIsMissing()

	/**
	 * A period outside the window owes nothing in it.
	 *
	 * @return void
	 */
	public function testPeriodProration(): void {
		$english = $this->service()->forPeriod('2026-09-01', '2026-11-06')['cohorts'][0]['courses'][0];

		self::assertSame(30.0, $english['owed']);
	}//end testPeriodProration()
}//end class
