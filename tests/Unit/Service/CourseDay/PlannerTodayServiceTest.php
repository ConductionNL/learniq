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
 * @spec openspec/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\CourseDay;

use DateTime;
use OCA\Learniq\Kernel\Contracts\CourseDayStatusServiceInterface;
use OCA\Learniq\Service\CourseDay\PlannerTodayService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * What the planner's Today reads on Monday 5 October.
 *
 * @covers \OCA\Learniq\Service\CourseDay\PlannerTodayService
 * @uses   \OCA\Learniq\Service\CourseDay\PlannerToday
 * @uses   \OCA\Learniq\Service\CourseDay\CourseDayStatusService
 * @uses   \OCA\Learniq\Support\SerialisedRow
 */
class PlannerTodayServiceTest extends TestCase {

	/**
	 * Rows per schema.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * The alternatives asked for, by cohort id.
	 *
	 * @var list<string>
	 */
	private array $askedAlternatives = [];

	protected function setUp(): void {
		$this->rows = [
			'cohort' => [
				// 22 October: 4 of minimum 6, decide by Thursday 8 October.
				['id' => 'c-22', 'name' => 'Hybride 22 okt', 'courseId' => 'hybride', 'dayDate' => '2026-10-22', 'minParticipants' => 6, 'capacity' => 12, 'goDecisionBy' => '2026-10-08', 'teacherIds' => ['henk'], 'dayStatus' => 'open'],
				// 26 November: the next day of that course, outside the occupancy period.
				['id' => 'c-26', 'name' => 'Hybride 26 nov', 'courseId' => 'hybride', 'dayDate' => '2026-11-26', 'minParticipants' => 6, 'capacity' => 12, 'goDecisionBy' => '2026-11-12', 'dayStatus' => 'open'],
				// 15 October: already decided, in the period.
				['id' => 'c-15', 'name' => 'F-gassen 15 okt', 'courseId' => 'fgas', 'dayDate' => '2026-10-15', 'minParticipants' => 4, 'capacity' => 10, 'goDecisionBy' => '2026-10-06', 'goDecision' => 'go', 'teacherIds' => ['ans'], 'dayStatus' => 'goes-ahead'],
				// A day that is past and a teaching group without a course.
				['id' => 'c-old', 'name' => 'Old', 'courseId' => 'hybride', 'dayDate' => '2026-09-30', 'minParticipants' => 6, 'goDecisionBy' => '2026-09-20'],
				['id' => 'g-1', 'name' => 'Group 1a', 'teacherIds' => ['x']],
			],
			'course' => [
				['id' => 'hybride', 'name' => 'Hybride warmtepomp'],
				['id' => 'fgas', 'name' => 'F-gassen: herhaling en examen'],
			],
			'course-booking' => [
				['id' => 'b-1', 'cohortId' => 'c-22', 'participantCount' => 3, 'lifecycle' => 'confirmed'],
				['id' => 'b-2', 'cohortId' => 'c-15', 'participantCount' => 2, 'lifecycle' => 'received'],
				['id' => 'b-3', 'cohortId' => 'c-26', 'participantCount' => 3, 'lifecycle' => 'received'],
				['id' => 'b-4', 'cohortId' => 'c-old', 'participantCount' => 1, 'lifecycle' => 'received'],
				['id' => 'b-5', 'cohortId' => 'c-15', 'participantCount' => 9, 'lifecycle' => 'cancelled'],
			],
			'enrolment' => [
				['id' => 'e-1', 'cohortId' => 'c-22', 'lifecycle' => 'active'],
				['id' => 'e-2', 'cohortId' => 'c-15', 'lifecycle' => 'pending'],
				['id' => 'e-3', 'cohortId' => 'c-15', 'lifecycle' => 'pending', 'bookingRef' => 'b-2'],
				['id' => 'e-4', 'cohortId' => 'c-15', 'lifecycle' => 'withdrawn'],
			],
			'session' => [
				['id' => 's-1', 'cohortId' => 'c-22', 'startsAt' => '2026-10-22T09:00:00+02:00', 'roomId' => 'lokaal-2'],
				['id' => 's-2', 'cohortId' => 'c-15', 'startsAt' => '2026-10-15T09:00:00+02:00', 'location' => 'Praktijkhal', 'substituteTeacherId' => 'piet'],
				['id' => 's-3', 'cohortId' => 'c-15', 'startsAt' => '2026-10-15T13:00:00+02:00', 'location' => 'Praktijkhal'],
			],
		];
	}//end setUp()

	private function service(): PlannerTodayService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				$rows = ($this->rows[$config['filters']['schema']] ?? []);
				if (isset($config['ids']) === true) {
					return array_values(array_filter($rows, static fn (array $row): bool => in_array($row['id'], $config['ids'], true)));
				}

				return $rows;
			}
		);
		$days = $this->createMock(CourseDayStatusServiceInterface::class);
		$days->method('alternatives')->willReturnCallback(
			function (string $cohortId): array {
				$this->askedAlternatives[] = $cohortId;
				return [['id' => 'c-26', 'name' => 'Hybride 26 nov', 'date' => '2026-11-26', 'free' => 9]];
			}
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-10-05T08:30:00+02:00'));

		return new PlannerTodayService(objectService: $objects, days: $days, time: $time);
	}//end service()

	public function testFourOfSixAsksForADecisionAndNamesTheNextDayWithPlaces(): void {
		$today = $this->service()->today();

		self::assertSame('2026-10-05', $today['today']);
		self::assertSame(
			[[
				'id' => 'c-22',
				'name' => 'Hybride 22 okt',
				'courseName' => 'Hybride warmtepomp',
				'date' => '2026-10-22',
				'participants' => 4,
				'minimum' => 6,
				'decideBy' => '2026-10-08',
				'workingDaysLeft' => 3,
				'next' => ['id' => 'c-26', 'name' => 'Hybride 26 nov', 'date' => '2026-11-26', 'free' => 9],
			]],
			$today['decisions']
		);
		// Only the day that asks for a decision looks for another day.
		self::assertSame(['c-22'], $this->askedAlternatives);
	}//end testFourOfSixAsksForADecisionAndNamesTheNextDayWithPlaces()

	public function testTheSixthParticipantTakesTheDayOffTheDecisions(): void {
		$this->rows['enrolment'][] = ['id' => 'e-9', 'cohortId' => 'c-22', 'lifecycle' => 'active'];
		$this->rows['enrolment'][] = ['id' => 'e-10', 'cohortId' => 'c-22', 'lifecycle' => 'pending'];

		self::assertSame([], $this->service()->today()['decisions']);
	}//end testTheSixthParticipantTakesTheDayOffTheDecisions()

	public function testBookingsAndLooseEnrolmentsOfComingDaysWaitForConfirmation(): void {
		// b-2 and b-3 are received; b-4 is on a past day. e-2 is pending without a booking; e-3 is on b-2.
		self::assertSame(['bookings' => 2, 'enrolments' => 1], $this->service()->today()['toConfirm']);
	}//end testBookingsAndLooseEnrolmentsOfComingDaysWaitForConfirmation()

	public function testTheOccupancyListsTheDaysUpToTheFridayTwoWeeksOnWithTrainersAndRooms(): void {
		$occupancy = $this->service()->today()['occupancy'];

		self::assertSame('2026-10-23', $occupancy['until']);
		self::assertSame(['c-15', 'c-22'], array_column($occupancy['days'], 'id'));
		self::assertSame(
			[
				'id' => 'c-15',
				'name' => 'F-gassen 15 okt',
				'courseName' => 'F-gassen: herhaling en examen',
				'date' => '2026-10-15',
				// The cancelled booking holds no place; the pending enrolment on b-2 is in b-2's two.
				'participants' => 3,
				'capacity' => 10,
				'minimum' => 4,
				'status' => 'goes-ahead',
				'trainers' => ['ans', 'piet'],
				'rooms' => ['Praktijkhal'],
			],
			$occupancy['days'][0]
		);
		self::assertSame(['henk'], $occupancy['days'][1]['trainers']);
		self::assertSame(['lokaal-2'], $occupancy['days'][1]['rooms']);
	}//end testTheOccupancyListsTheDaysUpToTheFridayTwoWeeksOnWithTrainersAndRooms()

	public function testAnUndatedDayTakesItsDateFromItsSessions(): void {
		unset($this->rows['cohort'][0]['dayDate']);

		$today = $this->service()->today();

		self::assertSame('2026-10-22', $today['decisions'][0]['date']);
		self::assertContains('c-22', array_column($today['occupancy']['days'], 'id'));
	}//end testAnUndatedDayTakesItsDateFromItsSessions()
}//end class
