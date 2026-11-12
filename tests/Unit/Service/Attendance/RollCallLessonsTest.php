<?php

/**
 * Learniq RollCallLessons unit tests.
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
 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-day-without-a-lesson-gets-one-when-the-register-is-saved
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Attendance;

use DateTimeZone;
use OCA\Learniq\Service\Attendance\RollCallLessons;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pins which earlier lessons time a new one, and the query that reads them.
 */
class RollCallLessonsTest extends TestCase {

	/**
	 * Every findAll call: config, rbac, multitenancy.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * Create the lesson of a day over the given earlier rows.
	 *
	 * @param string            $date The day.
	 * @param array<int, mixed> $rows What the session query returns.
	 *
	 * @return array<string, mixed> The created lesson.
	 */
	private function create(string $date, array $rows): array {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use ($rows): array {
				$this->queries[] = ['config' => $config, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				return $rows;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			static fn (array $object): object => OrEntityFactory::make(array_merge($object, ['id' => 'new-1']), 'session')
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('l')->willReturn('dag');

		$lessons = new RollCallLessons($objectService, $l10n, new NullLogger());

		return $lessons->create(cohort: ['id' => 'g7', 'name' => 'Groep 7'], date: $date, zone: new DateTimeZone('Europe/Amsterdam'));
	}//end create()

	/**
	 * Earlier lessons of the group, read the same way every time.
	 *
	 * @return array<int, mixed>
	 */
	private static function earlier(): array {
		return [
			['id' => 'other', 'cohortId' => 'g3', 'startsAt' => '2026-09-25T07:00:00+02:00', 'endsAt' => '2026-09-25T07:30:00+02:00'],
			OrEntityFactory::make(['id' => 'thu', 'cohortId' => 'g7', 'startsAt' => '2026-10-01T09:00:00+02:00', 'endsAt' => '2026-10-01T15:00:00+02:00'], 'session'),
			['id' => 'bad', 'cohortId' => 'g7', 'startsAt' => 'garbage', 'endsAt' => '2026-09-26T15:00:00+02:00'],
			['id' => 'midnight', 'cohortId' => 'g7', 'startsAt' => '2026-10-02T00:00:00+02:00', 'endsAt' => '2026-10-02T01:00:00+02:00'],
			['id' => 'fri', 'cohortId' => 'g7', 'startsAt' => '2026-09-25T08:45:00+02:00', 'endsAt' => '2026-09-25T14:00:00+02:00'],
		];
	}//end earlier()

	/**
	 * A Friday is timed like the last earlier Friday of the group; lessons of
	 * another group, an unreadable start and a start at the day's midnight do
	 * not count; the query is the exact window, unscoped.
	 *
	 * @return void
	 */
	public function testANewLessonIsTimedLikeTheLastSameWeekday(): void {
		$lesson = $this->create(date: '2026-10-02', rows: self::earlier());

		self::assertSame('2026-10-02T08:45:00+02:00', $lesson['startsAt']);
		self::assertSame('2026-10-02T14:00:00+02:00', $lesson['endsAt']);
		self::assertSame(
			[
				[
					'config' => [
						'filters' => [
							'register' => 'learniq',
							'schema' => 'session',
							'cohortId' => 'g7',
							'startsAt' => ['lt' => '2026-10-02T00:00:00+02:00', 'gte' => '2026-08-03T00:00:00+02:00'],
						],
						'sort' => ['startsAt' => 'DESC'],
						'limit' => 20,
					],
					'rbac' => false,
					'multitenancy' => false,
				],
			],
			$this->queries
		);
	}//end testANewLessonIsTimedLikeTheLastSameWeekday()

	/**
	 * Without an earlier lesson on the same weekday the newest earlier lesson
	 * is the model, whatever order the rows came in.
	 *
	 * @return void
	 */
	public function testWithoutTheSameWeekdayTheNewestLessonIsTheModel(): void {
		$lesson = $this->create(date: '2026-10-03', rows: self::earlier());

		self::assertSame('2026-10-03T00:00:00+02:00', $lesson['startsAt']);
		self::assertSame('2026-10-03T01:00:00+02:00', $lesson['endsAt']);
	}//end testWithoutTheSameWeekdayTheNewestLessonIsTheModel()

	/**
	 * When only another group's lessons come back, the default school day applies.
	 *
	 * @return void
	 */
	public function testOnlyOtherGroupsMeansTheDefaultDay(): void {
		$lesson = $this->create(date: '2026-10-02', rows: [self::earlier()[0]]);

		self::assertSame('2026-10-02T08:30:00+02:00', $lesson['startsAt']);
		self::assertSame('2026-10-02T14:30:00+02:00', $lesson['endsAt']);
	}//end testOnlyOtherGroupsMeansTheDefaultDay()
}//end class
