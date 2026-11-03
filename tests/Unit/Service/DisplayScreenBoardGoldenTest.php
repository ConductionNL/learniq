<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Service\DisplayScreenBoard;
use OCA\Learniq\Tests\Support\GoldenFile;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Timetabling\Source\TimetableSource;
use OCA\Learniq\Timetabling\Source\TimetableSourceResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

/**
 * Pins what a public display screen shows (which lessons, the group,
 * subject and room, whether a teacher code shows, and the change mark)
 * against golden/DisplayScreenBoard.json, for screens by group list, by
 * location, by room, with teacher codes off and for changes only. Lessons
 * are built relative to today in Amsterdam and recorded as D0/D1 plus the
 * wall-clock time, so the golden does not depend on the day it runs.
 *
 * @covers \OCA\Learniq\Service\DisplayScreenBoard
 * @uses   \OCA\Learniq\Service\DisplayScreenLesson
 */
class DisplayScreenBoardGoldenTest extends TestCase {
	use GoldenFile;

	private const GOLDEN = __DIR__ . '/golden/DisplayScreenBoard.json';

	/**
	 * Reads and source questions, in order.
	 *
	 * @var list<mixed>
	 */
	private array $calls = [];

	/**
	 * Every screen shows what was recorded.
	 *
	 * @return void
	 */
	public function testBoardsMatchGolden(): void {
		$screens = [
			'groups, today' => ['id' => 'd-1', 'name' => 'Hal', 'cohortIds' => ['c-1', 'c-2']],
			'groups, today and tomorrow' => ['id' => 'd-2', 'name' => 'Hal', 'cohortIds' => ['c-1', 'c-2'], 'shows' => 'today-and-tomorrow'],
			'groups, changes only' => ['id' => 'd-3', 'cohortIds' => ['c-1', 'c-2'], 'shows' => 'changes-only'],
			'groups, no teacher codes' => ['id' => 'd-4', 'cohortIds' => ['c-1', 'c-2'], 'showTeacherCodes' => false],
			'groups, teacher codes as text' => ['id' => 'd-5', 'cohortIds' => ['c-1', 'c-2'], 'showTeacherCodes' => 'no'],
			'location' => ['id' => 'd-6', 'name' => 'Vestiging Noord', 'vestigingId' => 'v-1'],
			'blank location' => ['id' => 'd-7', 'vestigingId' => ''],
			'nothing named' => ['id' => 'd-8', 'cohortIds' => 'c-1'],
			'rooms' => ['id' => 'd-9', 'cohortIds' => ['c-1', 'c-2'], 'roomIds' => ['r-1']],
			'rooms not a list' => ['id' => 'd-10', 'cohortIds' => ['c-1', 'c-2'], 'roomIds' => 'r-1'],
		];

		$actual = [];
		foreach ($screens as $name => $screen) {
			$this->calls = [];
			$board = $this->board()->forScreen(screen: $screen);
			$actual[$name] = ['name' => $board['name'], 'lessons' => $this->relative(lessons: $board['lessons']), 'calls' => $this->calls];
		}

		$this->calls = [];
		$actual['cached'] = $this->board(cached: ['name' => 'uit de cache', 'lessons' => []])->forScreen(screen: ['id' => 'd-1']);
		$actual['cached calls'] = $this->calls;

		$this->assertMatchesGolden(file: self::GOLDEN, actual: $actual);
	}//end testBoardsMatchGolden()

	/**
	 * The board over fixed groups, rooms and lessons.
	 *
	 * @param array<string,mixed>|null $cached What the cache holds.
	 *
	 * @return DisplayScreenBoard
	 */
	private function board(?array $cached=null): DisplayScreenBoard {
		$store = [
			'cohort' => [
				['id' => 'c-1', 'name' => '4H1', 'locationId' => 'v-1'],
				['id' => 'c-2', 'name' => '5V2', 'locationId' => 'v-2'],
				['name' => 'zonder id', 'locationId' => 'v-1'],
			],
			'room' => [
				['id' => 'r-1', 'name' => 'Lokaal 1', 'code' => 'A1'],
				['id' => 'r-2', 'name' => 'Lokaal 2', 'code' => 'A2'],
				['name' => 'zonder id', 'code' => 'Z9'],
			],
		];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('runAsSystem')->willReturnCallback(
			function (callable $operation) {
				$this->calls[] = 'runAsSystem';
				return $operation();
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config, ...$rest) use ($store): array {
				$this->calls[] = ['findAll' => $config, 'flags' => $rest];
				$filters = $config['filters'];
				$rows = ($store[$filters['schema']] ?? []);
				if (isset($config['ids']) === true) {
					$rows = array_filter($rows, static fn (array $row): bool => in_array($row['id'] ?? '', $config['ids'], true));
				}

				if (isset($filters['locationId']) === true) {
					$rows = array_filter($rows, static fn (array $row): bool => ($row['locationId'] ?? '') === $filters['locationId']);
				}

				$rows = array_values($rows);
				if ($filters['schema'] === 'room') {
					return array_merge(array_slice($rows, 0, 1), OrEntityFactory::makeMany(array_slice($rows, 1), 'room'));
				}

				return $rows;
			}
		);

		$source = $this->createMock(TimetableSource::class);
		$source->method('sessionsForCohorts')->willReturnCallback(
			function (array $cohortIds, ?string $from, ?string $to): array {
				$this->calls[] = ['sessionsForCohorts' => $cohortIds, 'window' => $this->relative(lessons: [['startsAt' => (string)$from, 'endsAt' => (string)$to]])];
				return $this->lessons();
			}
		);
		$resolver = $this->createMock(TimetableSourceResolver::class);
		$resolver->method('current')->willReturn($source);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(
			function (string $key) use ($cached) {
				$this->calls[] = ['cache get' => $key];
				return $cached;
			}
		);
		$cache->method('set')->willReturnCallback(
			function (string $key, $value, int $ttl): bool {
				$this->calls[] = ['cache set' => [$key, $ttl]];
				return true;
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		return new DisplayScreenBoard($objects, $resolver, $factory);
	}//end board()

	/**
	 * Lessons today, tomorrow and outside the window, by room id, room code and free location.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function lessons(): array {
		$at = static fn (int $day, string $time): string => (new DateTimeImmutable('today', new DateTimeZone('Europe/Amsterdam')))->modify(sprintf('%+d days', $day))->modify($time)->format(DATE_ATOM);
		return [
			['cohortId' => 'c-1', 'subject' => 'Wiskunde', 'startsAt' => $at(0, '08:30'), 'endsAt' => $at(0, '09:20'), 'roomId' => 'r-1', 'teacherReference' => 'JAN', 'teacherUserId' => 'jan.devries'],
			['cohortId' => 'c-1', 'title' => 'Engels', 'startsAt' => $at(0, '09:20'), 'endsAt' => $at(0, '10:10'), 'roomReference' => 'A2', 'location' => 'Gang', 'lifecycle' => 'cancelled', 'teacherReference' => ''],
			['cohortId' => 'c-2', 'subject' => 'Biologie', 'startsAt' => $at(0, '10:30'), 'endsAt' => $at(0, '11:20'), 'roomId' => 'r-2', 'substituteTeacherId' => 'sub.uid', 'teacherReference' => 'PIE'],
			['cohortId' => 'c-2', 'startsAt' => $at(0, '11:20'), 'endsAt' => $at(0, '12:10'), 'roomId' => 'r-9', 'location' => 'Aula', 'changeReasonKind' => 'room-unavailable', 'substituteTeacherId' => ''],
			['groupReference' => 'EXT', 'subject' => 'Gym', 'startsAt' => $at(0, '08:30'), 'endsAt' => $at(0, '09:20'), 'roomReference' => 'A1'],
			['cohortId' => 'c-1', 'subject' => 'Morgen', 'startsAt' => $at(1, '08:30'), 'endsAt' => $at(1, '09:20'), 'roomId' => 'r-1', 'substituteTeacherId' => 7],
			['cohortId' => 'c-1', 'subject' => 'Overmorgen', 'startsAt' => $at(2, '08:30'), 'roomId' => 'r-1'],
			['cohortId' => 'c-1', 'subject' => 'Gisteren', 'startsAt' => $at(-1, '23:59'), 'roomId' => 'r-1'],
			['cohortId' => 'c-1', 'subject' => 'Geen tijd', 'startsAt' => 'not a time', 'roomId' => 'r-1'],
		];
	}//end lessons()

	/**
	 * Lessons with their times written as day offsets from today (Amsterdam) and the local time.
	 *
	 * @param array<int,array<string,mixed>> $lessons The lessons.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function relative(array $lessons): array {
		$zone = new DateTimeZone('Europe/Amsterdam');
		$today = new DateTimeImmutable('today', $zone);
		foreach ($lessons as $index => $lesson) {
			foreach (['startsAt', 'endsAt'] as $field) {
				if (($lesson[$field] ?? '') === '') {
					continue;
				}

				$local = (new DateTimeImmutable($lesson[$field]))->setTimezone($zone);
				$day = (int)$today->diff($local->setTime(0, 0))->format('%r%a');
				$lessons[$index][$field] = 'D' . $day . ' ' . $local->format('H:i');
			}
		}

		return $lessons;
	}//end relative()
}//end class
