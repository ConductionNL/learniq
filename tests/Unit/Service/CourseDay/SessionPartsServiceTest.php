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
 * @spec openspec/changes/training-provider-course-days/specs/attendance/spec.md#requirement-a-trainer-registers-attendance-per-part-of-the-day
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\CourseDay;

use DateTime;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\CourseDay\CourseDayException;
use OCA\Learniq\Service\CourseDay\CourseDayStatusService;
use OCA\Learniq\Service\CourseDay\SessionPartsService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Who reads a session's attendance per part, and what they read.
 *
 * @covers \OCA\Learniq\Service\CourseDay\SessionPartsService
 * @uses   \OCA\Learniq\Service\CourseDay\SessionPartAttendance
 * @uses   \OCA\Learniq\Service\CourseDay\CourseDayException
 * @uses   \OCA\Learniq\Support\SerialisedRow
 */
class SessionPartsServiceTest extends TestCase {

	/**
	 * Rows per schema.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $rows = [];

	private ActionAuthService&MockObject $auth;

	private function service(int $places=9): SessionPartsService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			fn (array $config): array => ($this->rows[$config['filters']['schema']] ?? [])
		);
		$days = $this->createMock(CourseDayStatusService::class);
		$days->method('placesTakenOn')->with('c-5')->willReturn($places);
		$this->auth = $this->createMock(ActionAuthService::class);
		$time       = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-10-05T13:05:00+02:00'));

		$this->rows = [
			'session' => [[
				'id' => 's-1',
				'cohortId' => 'c-5',
				'parts' => [
					['key' => 'morning', 'startsAt' => '2026-10-05T09:00:00+02:00'],
					['key' => 'afternoon', 'startsAt' => '2026-10-05T13:00:00+02:00'],
				],
			]],
			'cohort' => [['id' => 'c-5', 'courseId' => 'storingen', 'teacherIds' => ['henk']]],
			'attendance-record' => [
				['sessionId' => 's-1', 'learnerId' => 'p-1', 'part' => 'morning', 'status' => 'present'],
				['sessionId' => 's-1', 'learnerId' => 'mehmet', 'part' => 'morning', 'status' => 'late', 'arrivedAt' => '10:15'],
				['sessionId' => 's-2', 'learnerId' => 'p-7', 'part' => 'afternoon', 'status' => 'present'],
			],
		];

		return new SessionPartsService(objectService: $objects, days: $days, actionAuth: $this->auth, time: $time);
	}//end service()

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	public function testTheTrainerOfTheDayReadsTheAfternoonAsDue(): void {
		$service = $this->service();
		$this->auth->expects($this->never())->method('requireAction');

		$result = $service->forSession(sessionId: 's-1', user: $this->user(uid: 'henk'));

		self::assertSame('afternoon', $result['due']);
		self::assertSame('c-5', $result['cohortId']);
		self::assertSame(2, $result['parts'][0]['there']);
		self::assertSame(9, $result['parts'][0]['of']);
		self::assertSame(0, $result['parts'][1]['there'], 'Another session\'s record is not counted.');
	}//end testTheTrainerOfTheDayReadsTheAfternoonAsDue()

	public function testAPlannerReadsAnyDay(): void {
		$service = $this->service();
		$this->auth->expects($this->once())->method('requireAction')->with($this->anything(), 'course-day.plan');

		self::assertSame('afternoon', $service->forSession(sessionId: 's-1', user: $this->user(uid: 'sophie'))['due']);
	}//end testAPlannerReadsAnyDay()

	public function testAnotherTrainerIsRefused(): void {
		$service = $this->service();
		$this->auth->method('requireAction')->willThrowException(new OCSForbiddenException('Not allowed.'));

		try {
			$service->forSession(sessionId: 's-1', user: $this->user(uid: 'kees'));
			self::fail('A trainer of another day must be refused.');
		} catch (CourseDayException $exception) {
			self::assertSame(403, $exception->getStatus());
		}
	}//end testAnotherTrainerIsRefused()

	public function testAnUnknownSessionOrAClassLessonIsNotFound(): void {
		$service = $this->service();
		$this->rows['cohort'][0]['courseId'] = '';

		foreach (['s-1', 's-404'] as $id) {
			try {
				$service->forSession(sessionId: $id, user: $this->user(uid: 'henk'));
				self::fail($id . ' must not be found.');
			} catch (CourseDayException $exception) {
				self::assertSame(404, $exception->getStatus());
			}
		}
	}//end testAnUnknownSessionOrAClassLessonIsNotFound()
}//end class
