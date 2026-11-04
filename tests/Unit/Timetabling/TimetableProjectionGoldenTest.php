<?php

/**
 * Learniq timetable projection golden tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Timetabling
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Timetabling;

use OCA\Learniq\Service\LessonNoteReader;
use OCA\Learniq\Service\TimetableIcsWriter;
use OCA\Learniq\Service\TimetableProjector;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Timetabling\SessionSeries;
use OCA\Learniq\Timetabling\Source\PlanninqTimetableSource;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * Pins the ICS events, the planninq lesson to session mapping, the projected
 * session and the session series for a range of inputs against
 * tests/Unit/Timetabling/golden/TimetableProjection.json. With RECORD_GOLDEN=1
 * the file is written instead of compared.
 *
 * @covers \OCA\Learniq\Service\TimetableIcsWriter
 * @covers \OCA\Learniq\Service\TimetableProjector
 * @covers \OCA\Learniq\Timetabling\SessionSeries
 * @covers \OCA\Learniq\Timetabling\Source\PlanninqTimetableSource
 */
class TimetableProjectionGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/TimetableProjection.json';

	/**
	 * Every output equals the recorded golden.
	 *
	 * @return void
	 */
	public function testProjectionMatchesGolden(): void {
		$actual = [
			'ics' => $this->ics(),
			'toSession' => $this->toSessions(),
			'projectSession' => $this->projected(),
			'series' => $this->series(),
		];
		$actual = json_decode((string)json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true);
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), $actual);
	}//end testProjectionMatchesGolden()

	/**
	 * ICS documents for a set of events.
	 *
	 * @return array<int,string>
	 */
	private function ics(): array {
		$events = [
			['uid' => 'u-1', 'start' => '2026-03-02T09:00:00+01:00', 'end' => '2026-03-02T09:50:00+01:00', 'summary' => 'Wiskunde; H3, deel 2', 'location' => 'Lokaal 12', 'description' => "Regel 1\nRegel 2", 'url' => 'https://meet.example.nl/abc', 'cancelled' => false],
			['uid' => 'u-2', 'start' => '2026-03-02T10:00:00+01:00', 'summary' => 'Geen einde', 'location' => '', 'url' => 'http://insecure.example.nl', 'cancelled' => true],
			['uid' => 'u-3', 'start' => 'not a date', 'summary' => 'Weg'],
			['start' => '2026-03-03T08:00:00Z', 'end' => 'nope', 'summary' => str_repeat('Lange titel ', 12), 'url' => 'https://a b', 'cancelled' => 'yes'],
			['uid' => 'u-5', 'start' => '2026-03-04T08:00:00+00:00', 'description' => 'Alleen beschrijving'],
		];
		return [
			(new TimetableIcsWriter())->write('Rooster', $events, 1767225600),
			(new TimetableIcsWriter())->write('Leeg', [], 1767225600),
		];
	}//end ics()

	/**
	 * Planninq lessons mapped to sessions.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function toSessions(): array {
		$source = new PlanninqTimetableSource($this->createMock(IAppManager::class), $this->createMock(IEventDispatcher::class));
		$method = new ReflectionMethod($source, 'toSession');
		$lessons = [
			['id' => 'p-1', 'title' => 'Wiskunde', 'subject' => 'wi', 'startsAt' => 's', 'endsAt' => 'e', 'roomLabel' => 'Lokaal 1', 'roomReference' => 'r-1', 'cohortId' => 'c', 'courseId' => 'k', 'status' => 'cancelled', 'teacherUserId' => 'jan', 'groupReference' => 'g', 'teacherReference' => 'JAN', 'externalRef' => 'x', 'sourceSystem' => 'zermelo', 'onlineMeetingUrl' => 'https://meet/1'],
			['id' => 7, 'title' => '', 'subject' => 'Engels', 'roomLabel' => '', 'roomReference' => 'r-2', 'status' => 'scheduled', 'onlineMeetingUrl' => 'http://meet/2'],
			[],
		];
		return array_map(static fn (array $lesson): array => $method->invoke($source, $lesson), $lessons);
	}//end toSessions()

	/**
	 * Sessions projected for a page.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function projected(): array {
		$projector = new TimetableProjector(new NullLogger(), $this->createMock(LessonNoteReader::class));
		$method = new ReflectionMethod($projector, 'projectSession');
		$rooms = [
			'r-1' => ['id' => 'room-1', 'name' => 'Lokaal 1', 'capacity' => 30, 'facilities' => ['beamer']],
			'r-2' => ['uuid' => 'room-2'],
			'r-3' => [],
		];
		$sessions = [
			['id' => 's-1', 'title' => 'Les', 'startsAt' => 'a', 'endsAt' => 'b', 'location' => 'L', 'cohortId' => 'c', 'courseId' => 'k', 'lessonId' => 'l', 'lifecycle' => 'scheduled', 'roomId' => 'r-1', 'substituteTeacherId' => 't', 'teacherUserId' => 'jan', 'externalRef' => 'x', 'sourceSystem' => 'z', 'cover' => true, 'changeReasonKind' => 'illness', 'changeReason' => 'ziek', 'changedAt' => 'now', 'source' => 'planninq', 'onlineMeetingUrl' => 'https://meet/1'],
			['uuid' => 's-2', 'roomId' => 'r-2', 'cover' => 'yes', 'onlineMeetingUrl' => 'ftp://x'],
			['roomId' => 'r-3'],
			['roomId' => 'r-missing'],
			[],
		];
		return array_map(static fn (array $session): array => $method->invoke($projector, $session, $rooms), $sessions);
	}//end projected()

	/**
	 * Session series for a set of anchors.
	 *
	 * @return array<string,mixed>
	 */
	private function series(): array {
		$rows = [
			['id' => 'a-1', 'title' => 'Ma 9u', 'startsAt' => '2026-03-02T09:00:00+01:00', 'endsAt' => '2026-03-02T09:50:00+01:00', 'cohortId' => 'c-1', 'courseId' => 'k-1', 'lifecycle' => 'scheduled'],
			['id' => 'a-2', 'title' => 'Ma 9u', 'startsAt' => '2026-03-09T09:00:00+01:00', 'endsAt' => '2026-03-09T09:50:00+01:00', 'cohortId' => 'c-1', 'courseId' => 'k-1', 'lifecycle' => 'cancelled'],
			['id' => 'a-3', 'title' => 'Ma 10u', 'startsAt' => '2026-03-09T10:00:00+01:00', 'cohortId' => 'c-1', 'courseId' => 'k-1'],
			['id' => 'a-4', 'title' => 'Ma 9u laat', 'startsAt' => '2026-05-25T09:00:00+02:00', 'cohortId' => 'c-1', 'courseId' => 'k-1', 'lifecycle' => 'in-progress'],
			['id' => 'a-6', 'title' => 'Ma 9u, zes weken later', 'startsAt' => '2026-04-13T09:00:00+02:00', 'cohortId' => 'c-1', 'courseId' => 'k-1'],
			['id' => 'a-5', 'title' => 'Di 9u', 'startsAt' => '2026-03-03T09:00:00+01:00', 'cohortId' => 'c-1', 'courseId' => 'k-1'],
			['id' => 'b-1', 'startsAt' => '2026-03-02T09:00:00+01:00', 'cohortId' => 'c-2', 'courseId' => ''],
			['id' => 'b-2', 'startsAt' => '2026-03-16T09:00:00+01:00', 'cohortId' => 'c-2'],
			['id' => 'x-1', 'startsAt' => 'garbage', 'cohortId' => 'c-3'],
		];
		$queries = [];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static function (...$args) use ($rows) {
				$id = (string)$args[0];
				if ($id === 'boom') {
					throw new RuntimeException('down');
				}

				foreach ($rows as $row) {
					if ($row['id'] === $id) {
						return OrEntityFactory::make($row, 'session');
					}
				}

				return null;
			}
		);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config) use ($rows, &$queries): array {
				$queries[] = $config;
				$filters = array_diff_key($config['filters'], ['register' => 1, 'schema' => 1]);
				$matched = array_filter($rows, static function (array $row) use ($filters): bool {
					foreach ($filters as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							return false;
						}
					}

					return true;
				});
				return array_map(static fn (array $row) => OrEntityFactory::make($row, 'session'), array_reverse(array_values($matched)));
			}
		);
		$series = new SessionSeries($objects);
		$out = [];
		foreach ([['a-1', null], ['a-1', '2026-06-30'], ['a-1', 'bad'], ['b-1', null], ['x-1', null], ['missing', null], ['boom', null]] as [$id, $until]) {
			$out[$id . '|' . (string)$until] = $series->series($id, $until);
		}

		$out['queries'] = $queries;
		return $out;
	}//end series()
}//end class
