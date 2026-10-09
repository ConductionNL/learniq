<?php

/**
 * PastCourseDaysJob test.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/past-course-days-drop-off/specs/portal-contribution/spec.md#requirement-a-course-day-that-has-passed-is-no-longer-coming
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\BackgroundJob\PastCourseDaysJob;
use OCA\Learniq\Service\Portal\EmployerBookingProjection;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Tom's F-gassen day was yesterday: the booking and his course day stop
 * being coming, and a booking still to come is left alone.
 *
 * @spec openspec/changes/past-course-days-drop-off/specs/portal-contribution/spec.md#requirement-a-course-day-that-has-passed-is-no-longer-coming
 */
class PastCourseDaysJobTest extends TestCase {

	private RegisterFaithfulStore $store;

	/**
	 * Two confirmed bookings: one on Thursday 8 October, one on Tuesday 20 October.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows = [
			'course' => [['id' => 'course-fgas', 'name' => 'F-gassen: herhaling en examen', 'tags' => []]],
			'cohort' => [
				['id' => 'ed-past', 'name' => 'F-gassen, 8 oktober', 'courseId' => 'course-fgas', 'lifecycle' => 'planned', 'teacherIds' => []],
				['id' => 'ed-next', 'name' => 'F-gassen, 20 oktober', 'courseId' => 'course-fgas', 'lifecycle' => 'planned', 'teacherIds' => []],
			],
			'session' => [
				['id' => 's-past', 'cohortId' => 'ed-past', 'startsAt' => '2026-10-08T08:30:00+02:00', 'endsAt' => '2026-10-08T16:30:00+02:00'],
				['id' => 's-next', 'cohortId' => 'ed-next', 'startsAt' => '2026-10-20T08:30:00+02:00', 'endsAt' => '2026-10-20T16:30:00+02:00'],
			],
			'learner-profile' => [['id' => 'lp-tom', 'givenName' => 'Tom', 'familyName' => 'Verbeek', 'birthDate' => '1988-06-12']],
			'enrolment' => [
				['id' => 'en-past', 'learnerRef' => 'lp-tom', 'bookingRef' => 'b-past', 'lifecycle' => 'active', 'upcoming' => true, 'firstDay' => '2026-10-08'],
				['id' => 'en-next', 'learnerRef' => 'lp-tom', 'bookingRef' => 'b-next', 'lifecycle' => 'active', 'upcoming' => true, 'firstDay' => '2026-10-20'],
			],
			'course-booking' => [
				['id' => 'b-past', 'cohortId' => 'ed-past', 'courseId' => 'course-fgas', 'participantCount' => 1, 'lifecycle' => 'confirmed', 'upcoming' => true, 'firstDay' => '2026-10-08'],
				['id' => 'b-next', 'cohortId' => 'ed-next', 'courseId' => 'course-fgas', 'participantCount' => 1, 'lifecycle' => 'confirmed', 'upcoming' => true, 'firstDay' => '2026-10-20'],
			],
			'credential' => [],
		];
	}//end setUp()

	/**
	 * On Friday 9 October the past booking and its course day stop being
	 * coming; the booking of the 20th is not touched.
	 *
	 * @return void
	 */
	public function testYesterdaysCourseDayIsNoLongerComing(): void {
		$this->runAt('2026-10-09 07:00');

		$bookings = array_column($this->store->rows['course-booking'], null, 'id');
		$enrolments = array_column($this->store->rows['enrolment'], null, 'id');
		self::assertFalse($bookings['b-past']['upcoming']);
		self::assertFalse($enrolments['en-past']['upcoming']);
		self::assertTrue($bookings['b-next']['upcoming']);
		self::assertTrue($enrolments['en-next']['upcoming']);
		self::assertNotContains('b-next', array_column($this->store->saves, 'uuid'), 'a coming booking is not re-derived');
	}//end testYesterdaysCourseDayIsNoLongerComing()

	/**
	 * On the course day itself it is still the next one.
	 *
	 * @return void
	 */
	public function testOnTheDayItselfItIsStillComing(): void {
		$this->runAt('2026-10-08 18:00');

		self::assertTrue(array_column($this->store->rows['course-booking'], null, 'id')['b-past']['upcoming']);
	}//end testOnTheDayItselfItIsStillComing()

	/**
	 * Run the job at a moment in Amsterdam.
	 *
	 * @param string $moment The local time.
	 *
	 * @return void
	 */
	private function runAt(string $moment): void {
		$now = new DateTimeImmutable($moment, new DateTimeZone('Europe/Amsterdam'));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn($now);
		$time->method('getTime')->willReturn($now->getTimestamp());

		$store = $this->store;
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			static fn (array $config=[], bool $_rbac=true, bool $_multitenancy=true): array => $store->findAll($config, false, false)
		);
		$objectService->method('saveObject')->willReturnCallback(
			static fn (array $object, ?array $extend=[], $register=null, $schema=null, ?string $uuid=null): object => $store->save((string)$schema, $object, $uuid, false)
		);

		$projection = new EmployerBookingProjection($objectService, $this->createMock(IUserManager::class), $time);
		$job = new PastCourseDaysJob($time, $projection, new NullLogger());
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runAt()
}//end class
