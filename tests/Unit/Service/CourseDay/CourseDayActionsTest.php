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
use OCA\Learniq\Service\CourseDay\CourseDayException;
use OCA\Learniq\Service\CourseDay\CourseDayStatusService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The manual move, the go/no-go decision and moving participants to another day.
 *
 * @covers \OCA\Learniq\Service\CourseDay\CourseDayStatusService
 * @uses   \OCA\Learniq\Service\CourseDay\CourseDayStatus
 * @uses   \OCA\Learniq\Service\CourseDay\CourseDayException
 * @uses   \OCA\Learniq\Support\SerialisedRow
 */
class CourseDayActionsTest extends TestCase {

	private ObjectService&MockObject $objects;

	/**
	 * Rows per schema, written back by saveObject.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * Every save as [schema, object].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $saves = [];

	private function service(): CourseDayStatusService {
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('findAll')->willReturnCallback(
			fn (array $config): array => ($this->rows[$config['filters']['schema']] ?? [])
		);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object, mixed $extend, mixed $register, string $schema, string $uuid): ObjectEntity {
				$this->saves[] = [$schema, $object];
				foreach (($this->rows[$schema] ?? []) as $i => $row) {
					if (($row['id'] ?? null) === $uuid) {
						$this->rows[$schema][$i] = $object;
					}
				}

				return $this->createMock(ObjectEntity::class);
			}
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime('2026-10-05T09:00:00+02:00'));
		return new CourseDayStatusService(objectService: $this->objects, time: $time);
	}//end service()

	/**
	 * Hybride warmtepomp: 22 October at 4 of 6 (max 12), 26 November with 9 places free,
	 * 3 September already past, 12 November full.
	 */
	private function course(): void {
		$this->rows['cohort'] = [
			['id' => 'c-22', 'name' => '22 oktober', 'courseId' => 'hybride', 'capacity' => 12, 'minParticipants' => 6, 'dayStatus' => 'open'],
			['id' => 'c-26', 'name' => '26 november', 'courseId' => 'hybride', 'capacity' => 12, 'minParticipants' => 6, 'dayStatus' => 'open'],
			['id' => 'c-03', 'name' => '3 september', 'courseId' => 'hybride', 'capacity' => 12, 'dayStatus' => 'done'],
			['id' => 'c-12', 'name' => '12 november', 'courseId' => 'hybride', 'capacity' => 1, 'dayStatus' => 'full'],
			['id' => 'f-01', 'name' => 'F-gassen', 'courseId' => 'f-gassen', 'capacity' => 12, 'dayStatus' => 'open'],
		];
		$this->rows['course-booking'] = [
			['id' => 'b-1', 'cohortId' => 'c-22', 'participantCount' => 3, 'lifecycle' => 'confirmed'],
			['id' => 'b-2', 'cohortId' => 'c-22', 'participantCount' => 2, 'lifecycle' => 'cancelled'],
			['id' => 'b-3', 'cohortId' => 'c-26', 'participantCount' => 3, 'lifecycle' => 'confirmed'],
		];
		$this->rows['enrolment'] = [
			['id' => 'e-1', 'cohortId' => 'c-22', 'bookingRef' => 'b-1', 'lifecycle' => 'active'],
			['id' => 'e-2', 'cohortId' => 'c-22', 'lifecycle' => 'pending'],
			['id' => 'e-3', 'cohortId' => 'c-22', 'lifecycle' => 'withdrawn'],
			['id' => 'e-4', 'cohortId' => 'c-12', 'lifecycle' => 'active'],
		];
		$this->rows['session'] = [
			['id' => 's-22', 'cohortId' => 'c-22', 'startsAt' => '2026-10-22T09:00:00+02:00'],
			['id' => 's-26', 'cohortId' => 'c-26', 'startsAt' => '2026-11-26T09:00:00+01:00'],
			['id' => 's-03', 'cohortId' => 'c-03', 'startsAt' => '2026-09-03T09:00:00+02:00'],
			['id' => 's-12', 'cohortId' => 'c-12', 'startsAt' => '2026-11-12T09:00:00+01:00'],
			['id' => 's-f1', 'cohortId' => 'f-01', 'startsAt' => '2026-11-01T09:00:00+01:00'],
		];
	}//end course()

	/**
	 * A stored row by schema and id.
	 *
	 * @return array<string, mixed>
	 */
	private function row(string $schema, string $id): array {
		foreach ($this->rows[$schema] as $row) {
			if ($row['id'] === $id) {
				return $row;
			}
		}

		self::fail('No ' . $schema . ' ' . $id);
	}//end row()

	public function testAManualMoveIsLoggedAndOutlivesARestampWithoutChange(): void {
		$this->course();
		$service = $this->service();

		self::assertSame('goes-ahead', $service->move(cohortId: 'c-22', status: 'goes-ahead', userId: 'sophie'));
		$day = $this->row(schema: 'cohort', id: 'c-22');
		self::assertSame('goes-ahead', $day['dayStatus']);
		self::assertSame('sophie', $day['dayStatusMove']['movedBy']);
		self::assertSame('open', $day['dayStatusMove']['from']);
		self::assertSame(
			[['kind' => 'manual', 'from' => 'open', 'to' => 'goes-ahead', 'by' => 'sophie', 'at' => '2026-10-05T09:00:00+02:00']],
			$day['dayStatusLog']
		);

		$saves = count($this->saves);
		self::assertSame('goes-ahead', $service->restamp(cohortId: 'c-22'));
		self::assertCount($saves, $this->saves, 'A restamp without a change in the facts writes nothing.');
	}//end testAManualMoveIsLoggedAndOutlivesARestampWithoutChange()

	public function testTheNextChangeEndsTheManualMove(): void {
		$this->course();
		$service = $this->service();
		$service->move(cohortId: 'c-22', status: 'goes-ahead', userId: 'sophie');

		// One participant withdraws: the facts changed, the derived status takes over.
		$this->rows['enrolment'][1]['lifecycle'] = 'withdrawn';
		self::assertSame('open', $service->restamp(cohortId: 'c-22'));

		$day = $this->row(schema: 'cohort', id: 'c-22');
		self::assertSame('open', $day['dayStatus']);
		self::assertNull($day['dayStatusMove']);
		self::assertSame(['manual', 'derived'], array_column($day['dayStatusLog'], 'kind'));
		self::assertSame('open', $day['dayStatusLog'][1]['to']);
	}//end testTheNextChangeEndsTheManualMove()

	public function testAnUnknownStatusIsRefused(): void {
		$this->course();
		$service = $this->service();

		try {
			$service->move(cohortId: 'c-22', status: 'maybe', userId: 'sophie');
			self::fail('An unknown status must be refused.');
		} catch (CourseDayException $exception) {
			self::assertSame(400, $exception->getStatus());
		}

		self::assertSame([], $this->saves);
	}//end testAnUnknownStatusIsRefused()

	public function testACohortWithoutACourseIsNotFound(): void {
		$this->rows['cohort'] = [['id' => 'klas-3', 'kind' => 'teaching']];
		$service = $this->service();

		$this->expectException(CourseDayException::class);
		$this->expectExceptionMessage('Course day not found.');
		$service->decide(cohortId: 'klas-3', decision: 'go', userId: 'sophie');
	}//end testACohortWithoutACourseIsNotFound()

	public function testAGoDecisionMovesTheDayAheadAndIsLogged(): void {
		$this->course();
		$service = $this->service();

		self::assertSame('goes-ahead', $service->decide(cohortId: 'c-22', decision: 'go', userId: 'sophie'));
		$day = $this->row(schema: 'cohort', id: 'c-22');
		self::assertSame('go', $day['goDecision']);
		self::assertSame(['decision', 'derived'], array_column($day['dayStatusLog'], 'kind'));
		self::assertSame('go', $day['dayStatusLog'][0]['to']);
	}//end testAGoDecisionMovesTheDayAheadAndIsLogged()

	public function testANoGoDecisionCancelsTheDay(): void {
		$this->course();
		$service = $this->service();

		self::assertSame('cancelled', $service->decide(cohortId: 'c-22', decision: 'no-go', userId: 'sophie'));
		self::assertSame('cancelled', $this->row(schema: 'cohort', id: 'c-22')['dayStatus']);
	}//end testANoGoDecisionCancelsTheDay()

	public function testAnUnknownDecisionIsRefused(): void {
		$this->course();
		$service = $this->service();

		$this->expectException(CourseDayException::class);
		$service->decide(cohortId: 'c-22', decision: 'perhaps', userId: 'sophie');
	}//end testAnUnknownDecisionIsRefused()

	public function testTheAlternativesAreComingDaysOfTheCourseWithPlaces(): void {
		$this->course();
		$service = $this->service();

		self::assertSame(
			[['id' => 'c-26', 'name' => '26 november', 'date' => '2026-11-26', 'free' => 9]],
			$service->alternatives(cohortId: 'c-22')
		);
	}//end testTheAlternativesAreComingDaysOfTheCourseWithPlaces()

	public function testTheParticipantsOfACancelledDayMoveToTheChosenDay(): void {
		$this->course();
		$service = $this->service();
		$service->decide(cohortId: 'c-22', decision: 'no-go', userId: 'sophie');

		self::assertSame(
			['bookings' => 1, 'enrolments' => 2, 'targetId' => 'c-26'],
			$service->moveParticipants(cohortId: 'c-22', targetId: 'c-26', userId: 'sophie')
		);
		self::assertSame('c-26', $this->row(schema: 'course-booking', id: 'b-1')['cohortId']);
		self::assertSame('c-22', $this->row(schema: 'course-booking', id: 'b-2')['cohortId'], 'A cancelled booking stays.');
		self::assertSame('c-26', $this->row(schema: 'enrolment', id: 'e-1')['cohortId']);
		self::assertSame('c-26', $this->row(schema: 'enrolment', id: 'e-2')['cohortId']);
		self::assertSame('c-22', $this->row(schema: 'enrolment', id: 'e-3')['cohortId'], 'A withdrawn enrolment stays.');
		// 3 + 4 moved = 7 of minimum 6.
		self::assertSame('goes-ahead', $this->row(schema: 'cohort', id: 'c-26')['dayStatus']);
		self::assertSame(['decision', 'derived', 'participants-moved'], array_column($this->row(schema: 'cohort', id: 'c-22')['dayStatusLog'], 'kind'));
	}//end testTheParticipantsOfACancelledDayMoveToTheChosenDay()

	public function testParticipantsOfADayThatGoesAheadAreNotMoved(): void {
		$this->course();
		$service = $this->service();

		try {
			$service->moveParticipants(cohortId: 'c-22', targetId: 'c-26', userId: 'sophie');
			self::fail('A day that is not cancelled keeps its participants.');
		} catch (CourseDayException $exception) {
			self::assertSame(409, $exception->getStatus());
		}

		self::assertSame([], $this->saves);
	}//end testParticipantsOfADayThatGoesAheadAreNotMoved()

	public function testADayOfAnotherCourseOrAFullDayIsNoTarget(): void {
		$this->course();
		$service = $this->service();
		$service->decide(cohortId: 'c-22', decision: 'no-go', userId: 'sophie');
		$saves = count($this->saves);

		foreach (['f-01', 'c-12', 'c-03'] as $target) {
			try {
				$service->moveParticipants(cohortId: 'c-22', targetId: $target, userId: 'sophie');
				self::fail($target . ' must be refused.');
			} catch (CourseDayException $exception) {
				self::assertSame(409, $exception->getStatus());
			}
		}

		self::assertCount($saves, $this->saves);
	}//end testADayOfAnotherCourseOrAFullDayIsNoTarget()

	public function testATargetWithTooFewPlacesIsRefused(): void {
		$this->course();
		$this->rows['cohort'][1]['capacity'] = 5;
		$service = $this->service();
		$service->decide(cohortId: 'c-22', decision: 'no-go', userId: 'sophie');

		$this->expectException(CourseDayException::class);
		$this->expectExceptionMessage('too few places');
		$service->moveParticipants(cohortId: 'c-22', targetId: 'c-26', userId: 'sophie');
	}//end testATargetWithTooFewPlacesIsRefused()

	public function testTheFingerprintIgnoresTheDateUntilTheDayPasses(): void {
		$facts = ['participants' => 4, 'capacity' => 12, 'minimum' => 6, 'decision' => null, 'dayDate' => '2026-10-22', 'today' => '2026-10-05', 'openResults' => true];

		self::assertSame(
			CourseDayStatusService::fingerprint(facts: $facts),
			CourseDayStatusService::fingerprint(facts: array_merge($facts, ['today' => '2026-10-21']))
		);
		self::assertNotSame(
			CourseDayStatusService::fingerprint(facts: $facts),
			CourseDayStatusService::fingerprint(facts: array_merge($facts, ['today' => '2026-10-23']))
		);
		self::assertNotSame(
			CourseDayStatusService::fingerprint(facts: $facts),
			CourseDayStatusService::fingerprint(facts: array_merge($facts, ['participants' => 5]))
		);
	}//end testTheFingerprintIgnoresTheDateUntilTheDayPasses()
}//end class
