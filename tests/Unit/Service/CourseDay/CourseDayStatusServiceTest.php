<?php

/**
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\CourseDay;

use DateTime;
use OCA\Learniq\Service\CourseDay\CourseDayStatusService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Re-deriving a stored course day.
 *
 * @covers \OCA\Learniq\Service\CourseDay\CourseDayStatusService
 * @uses   \OCA\Learniq\Service\CourseDay\CourseDayStatus
 * @uses   \OCA\Learniq\Support\SerialisedRow
 */
class CourseDayStatusServiceTest extends TestCase {

	private ObjectService&MockObject $objects;

	/**
	 * Rows per schema, as findAll answers them.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * The service over the rows, on Monday 5 October 2026.
	 *
	 * @return CourseDayStatusService
	 */
	private function service(): CourseDayStatusService {
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('findAll')->willReturnCallback(
			fn (array $config): array => ($this->rows[$config['filters']['schema']] ?? [])
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-10-05T09:00:00+02:00'));
		return new CourseDayStatusService(objectService: $this->objects, time: $time);
	}//end service()

	/**
	 * Hybride warmtepomp on 22 October: one booking of 3 and one loose enrolment.
	 *
	 * @param int $extra Extra loose enrolments.
	 *
	 * @return void
	 */
	private function day(int $extra=0): void {
		$this->rows['cohort'] = [['id' => 'c-22', 'courseId' => 'hybride', 'capacity' => 12, 'minParticipants' => 6, 'dayStatus' => 'open']];
		$this->rows['course-booking'] = [['id' => 'b-1', 'cohortId' => 'c-22', 'participantCount' => 3, 'lifecycle' => 'confirmed']];
		$enrolments = [
			['id' => 'e-1', 'cohortId' => 'c-22', 'bookingRef' => 'b-1', 'lifecycle' => 'active'],
			['id' => 'e-2', 'cohortId' => 'c-22', 'lifecycle' => 'pending'],
			['id' => 'e-3', 'cohortId' => 'c-22', 'lifecycle' => 'withdrawn'],
		];
		for ($i = 0; $i < $extra; $i++) {
			$enrolments[] = ['id' => 'x-' . $i, 'cohortId' => 'c-22', 'lifecycle' => 'pending'];
		}

		$this->rows['enrolment'] = $enrolments;
		$this->rows['session'] = [['id' => 's-1', 'cohortId' => 'c-22', 'startsAt' => '2026-10-22T09:00:00+02:00']];
	}//end day()

	/**
	 * Four of six stays open and is not written again.
	 *
	 * @return void
	 */
	public function testFourOfSixStaysOpenWithoutAWrite(): void {
		$this->day();
		$service = $this->service();
		$this->objects->expects(self::never())->method('saveObject');

		self::assertSame('open', $service->restamp(cohortId: 'c-22'));
	}//end testFourOfSixStaysOpenWithoutAWrite()

	/**
	 * The sixth place moves the day to goes-ahead, written once with the rest of the cohort kept.
	 *
	 * @return void
	 */
	public function testTheSixthPlaceIsWritten(): void {
		$this->day(extra: 2);
		$service = $this->service();
		$this->objects->expects(self::once())
			->method('saveObject')
			->with(
				['id' => 'c-22', 'courseId' => 'hybride', 'capacity' => 12, 'minParticipants' => 6, 'dayStatus' => 'goes-ahead'],
				self::anything(),
				'learniq',
				'cohort',
				'c-22'
			);

		self::assertSame('goes-ahead', $service->restamp(cohortId: 'c-22'));
	}//end testTheSixthPlaceIsWritten()

	/**
	 * A cohort that is no course day is left alone.
	 *
	 * @return void
	 */
	public function testACohortWithoutACourseIsLeftAlone(): void {
		$this->rows['cohort'] = [['id' => 'klas-3', 'kind' => 'teaching']];
		$service = $this->service();
		$this->objects->expects(self::never())->method('saveObject');

		self::assertNull($service->restamp(cohortId: 'klas-3'));
	}//end testACohortWithoutACourseIsLeftAlone()

	/**
	 * Places: each placed enrolment once, a booking for the larger of its places and its enrolments.
	 *
	 * @return void
	 */
	public function testPlacesCountBookingsAndEnrolmentsOnce(): void {
		$bookings = [
			['id' => 'b-1', 'participantCount' => 3, 'lifecycle' => 'confirmed'],
			['id' => 'b-2', 'participantCount' => 1, 'lifecycle' => 'received'],
			['id' => 'b-3', 'participantCount' => 5, 'lifecycle' => 'cancelled'],
		];
		$enrolments = [
			['bookingRef' => 'b-2', 'lifecycle' => 'active'],
			['bookingRef' => 'b-2', 'lifecycle' => 'pending'],
			['bookingRef' => 'b-9', 'lifecycle' => 'active'],
			['lifecycle' => 'completed'],
			['lifecycle' => 'failed'],
		];

		self::assertSame(3 + 2 + 1 + 1, CourseDayStatusService::participants(bookings: $bookings, enrolments: $enrolments));
	}//end testPlacesCountBookingsAndEnrolmentsOnce()
}//end class
