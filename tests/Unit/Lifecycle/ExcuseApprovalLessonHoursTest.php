<?php

/**
 * Unit tests for lesson hours narrowing the attendance write of an approved
 * absence report.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/absence-reports-seen-and-ended/specs/attendance/spec.md#requirement-a-report-may-cover-lesson-hours-and-an-illness-may-stay-open-until-the-pupil-is-better
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\ExcuseApprovalHandler;
use OCA\Learniq\Service\Attendance\LessonHours;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * "Noor leaves after the fourth hour": only hours 5, 6 and 7 are excused.
 *
 * @covers \OCA\Learniq\Lifecycle\ExcuseApprovalHandler
 * @covers \OCA\Learniq\Service\Attendance\LessonHours
 */
class ExcuseApprovalLessonHoursTest extends TestCase {

	/**
	 * The ids of the records the approval wrote.
	 *
	 * @var list<string>
	 */
	private array $flipped = [];

	/**
	 * The session reads, by cohort.
	 *
	 * @var list<string>
	 */
	private array $sessionReads = [];

	/**
	 * Groep 8's seven lessons on Monday 5 October, given out of order, and one on Tuesday.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function sessions(): array {
		$rows = [];
		foreach ([7, 1, 5, 3, 2, 6, 4] as $hour) {
			$rows[] = ['id' => 'mon-' . $hour, 'cohortId' => 'groep-8', 'startsAt' => sprintf('2026-10-05T%02d:30:00+02:00', (7 + $hour))];
		}

		$rows[] = ['id' => 'tue-1', 'cohortId' => 'groep-8', 'startsAt' => '2026-10-06T08:30:00+02:00'];
		$rows[] = OrEntityFactory::make(['cohortId' => 'groep-8', 'startsAt' => 'tomorrow'], 'session', 'learniq', 'odd');

		return $rows;
	}//end sessions()

	/**
	 * Noor's unexcused marks of Monday, one per lesson, plus one without a session.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function records(): array {
		$records = [];
		foreach (range(1, 7) as $hour) {
			$records[] = ['id' => 'ar-' . $hour, 'sessionId' => 'mon-' . $hour, 'cohortId' => 'groep-8', 'markedAt' => sprintf('2026-10-05T%02d:35:00+02:00', (7 + $hour)), 'status' => 'absent-unexcused'];
		}

		$records[] = ['id' => 'ar-x', 'cohortId' => 'groep-8', 'markedAt' => '2026-10-05T15:00:00+02:00', 'status' => 'absent-unexcused'];

		return $records;
	}//end records()

	/**
	 * Approve a request over Noor's marks and groep 8's sessions.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return void
	 */
	private function approve(array $request): void {
		$store = $this->createMock(ObjectService::class);
		$store->method('findAll')->willReturnCallback(
			function (array $config): array {
				if (($config['filters']['schema'] ?? '') === 'session') {
					$this->sessionReads[] = (string)$config['filters']['cohortId'];
					return self::sessions();
				}

				return self::records();
			}
		);
		$store->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null): ObjectEntity {
				$this->flipped[] = (string)$object['id'];
				self::assertSame(['absent-excused', 'er-noor'], [$object['status'], $object['excuseRequestId']]);
				return OrEntityFactory::make((array)$object, (string)$schema);
			}
		);
		$schemas = $this->createMock(ListenerSchemaResolver::class);
		$schemas->method('eventRegister')->willReturn('learniq');
		$schemas->method('eventSchema')->willReturn('excuse-request');
		$object = $this->createMock(ObjectEntity::class);
		$object->method('jsonSerialize')->willReturn($request);
		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn($object);
		$event->method('getTo')->willReturn('approved');

		(new ExcuseApprovalHandler($store, $this->createMock(LoggerInterface::class), $schemas))->handle($event);
	}//end approve()

	/**
	 * Erik reports Noor for hours 5 to 7: only those three lessons read absent with permission.
	 *
	 * @return void
	 */
	public function testNoorLeavesAfterTheFourthHour(): void {
		$this->approve(['id' => 'er-noor', 'learnerId' => 'noor', 'dateFrom' => '2026-10-05', 'dateTo' => '2026-10-05', 'fromLessonHour' => 5, 'toLessonHour' => 7, 'reasonKind' => 'medical-appointment']);

		self::assertSame(['ar-5', 'ar-6', 'ar-7'], $this->flipped);
		self::assertSame(['groep-8'], $this->sessionReads, 'the group sessions are read once');
	}//end testNoorLeavesAfterTheFourthHour()

	/**
	 * Only a first hour runs to the end of the day; only a last hour from its start.
	 *
	 * @return void
	 */
	public function testAnOpenEndedRangeRunsToTheEdgeOfTheDay(): void {
		$this->approve(['id' => 'er-noor', 'learnerId' => 'noor', 'dateFrom' => '2026-10-05', 'dateTo' => '2026-10-05', 'fromLessonHour' => '6']);
		self::assertSame(['ar-6', 'ar-7'], $this->flipped);

		$this->flipped = [];
		$this->approve(['id' => 'er-noor', 'learnerId' => 'noor', 'dateFrom' => '2026-10-05', 'dateTo' => '2026-10-05', 'toLessonHour' => 2]);
		self::assertSame(['ar-1', 'ar-2'], $this->flipped);
	}//end testAnOpenEndedRangeRunsToTheEdgeOfTheDay()

	/**
	 * Without lesson hours the whole day follows, and no session is read.
	 *
	 * @return void
	 */
	public function testAWholeDayReportReadsNoSessions(): void {
		$this->approve(['id' => 'er-noor', 'learnerId' => 'noor', 'dateFrom' => '2026-10-05', 'dateTo' => '2026-10-05', 'fromLessonHour' => 0, 'toLessonHour' => 'x']);

		self::assertSame(['ar-1', 'ar-2', 'ar-3', 'ar-4', 'ar-5', 'ar-6', 'ar-7', 'ar-x'], $this->flipped);
		self::assertSame([], $this->sessionReads);
	}//end testAWholeDayReportReadsNoSessions()

	/**
	 * A session's hour is its place among its group's sessions of that day; one that cannot be placed is in no hour.
	 *
	 * @return void
	 */
	public function testTheHourOfASession(): void {
		$hours = new LessonHours();
		$sessions = self::sessions();

		self::assertSame(1, $hours->hourOf(sessionId: 'mon-1', sessions: $sessions));
		self::assertSame(7, $hours->hourOf(sessionId: 'mon-7', sessions: $sessions));
		self::assertSame(1, $hours->hourOf(sessionId: 'tue-1', sessions: $sessions));
		self::assertNull($hours->hourOf(sessionId: 'odd', sessions: $sessions));
		self::assertNull($hours->hourOf(sessionId: 'gone', sessions: $sessions));
		self::assertFalse($hours->covers(hour: null, range: [1, 12]));
		self::assertSame([3, 12], $hours->rangeOf(request: ['fromLessonHour' => 3, 'toLessonHour' => 13]));
		self::assertNull($hours->rangeOf(request: []));
	}//end testTheHourOfASession()
}//end class
