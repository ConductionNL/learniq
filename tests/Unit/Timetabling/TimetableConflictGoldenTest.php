<?php

/**
 * Learniq TimetableConflictDetector characterization test.
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
 *
 * @spec openspec/specs/timetabling/spec.md#requirement-conflict-detection-flags-double-bookings-and-capacity-overruns-without-resolving-them
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Timetabling;

use Closure;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Timetabling\SessionOverlapEvaluator;
use OCA\Learniq\Timetabling\SessionWindowLoader;
use OCA\Learniq\Timetabling\TimetableConflictDetector;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use Stringable;

/**
 * Pins every ObjectService call and log line TimetableConflictDetector makes,
 * per scenario, against tests/Unit/Timetabling/golden/TimetableConflict.json.
 * With RECORD_GOLDEN=1 the file is written instead of compared.
 *
 * @covers \OCA\Learniq\Timetabling\TimetableConflictDetector
 * @covers \OCA\Learniq\Timetabling\ConflictScan
 * @covers \OCA\Learniq\Timetabling\ConflictFinding
 * @covers \OCA\Learniq\Timetabling\SessionPair
 * @uses   \OCA\Learniq\Timetabling\SessionWindowLoader
 * @uses   \OCA\Learniq\Timetabling\SessionOverlapEvaluator
 */
class TimetableConflictGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/TimetableConflict.json';

	/**
	 * A session row.
	 *
	 * @param string              $id    Session id ('' for none).
	 * @param string              $start Start hour and minute, e.g. '09:00'.
	 * @param string              $end   End hour and minute.
	 * @param array<string,mixed> $extra Extra fields.
	 *
	 * @return array<string,mixed>
	 */
	private static function session(string $id, string $start, string $end, array $extra = []): array {
		$row = ['id' => $id, 'startsAt' => "2026-02-02T{$start}:00+00:00", 'endsAt' => "2026-02-02T{$end}:00+00:00", 'lifecycle' => 'scheduled', 'tenant_id' => 'tenant-1'];
		return array_merge($row, $extra);
	}//end session()

	/**
	 * The shared store: cohorts, rooms, exams, open conflicts and day-bucket sessions.
	 *
	 * @return array<string,list<array<string,mixed>>>
	 */
	private static function store(): array {
		$many = array_map(static fn (int $n): string => "l{$n}", range(1, 34));
		return [
			'cohort' => [
				['id' => 'c-a', 'teacherIds' => ['t-1'], 'learnerIds' => ['l1', 'l2']],
				['id' => 'c-b', 'teacherIds' => ['t-1', 't-2'], 'learnerIds' => ['l2', 'l3']],
				['id' => 'c-c', 'teacherIds' => ['t-3'], 'learnerIds' => ['l9']],
				['id' => 'c-big', 'teacherIds' => ['t-4'], 'learnerIds' => $many],
			],
			'room' => [
				['id' => 'r-small', 'capacity' => 30],
				['id' => 'r-big', 'capacity' => 300],
				['id' => 'r-none'],
			],
			'exam' => [
				['id' => 'e-1', 'sessionId' => 's-a'],
				['id' => 'e-2', 'sessionId' => 's-big'],
				['uuid' => 'e-3', 'sessionId' => 's-small'],
				['id' => 'e-4', 'sessionId' => 's-lost'],
				['id' => 'e-5', 'sessionId' => 's-big2'],
			],
			'timetable-conflict' => [
				['kind' => 'room-double-booking', 'sessionIds' => ['s-b', 's-a'], 'lifecycle' => 'open'],
				['kind' => 'cohort-double-booking', 'sessionIds' => 'not-a-list'],
				['kind' => 'room-capacity-exceeded', 'sessionIds' => ['s-big2'], 'lifecycle' => 'open'],
			],
			'session' => [
				self::session('s-day', '09:15', '09:45', ['cohortId' => 'c-c', 'roomId' => 'r-small', 'sessionDayBucket' => '2026-02-02']),
				self::session('s-gone', '09:15', '09:45', ['cohortId' => 'c-a', 'lifecycle' => 'cancelled', 'sessionDayBucket' => '2026-02-02']),
				self::session('', '09:15', '09:45', ['cohortId' => 'c-a', 'sessionDayBucket' => '2026-02-02']),
			],
		];
	}//end store()

	/**
	 * Each scenario: the entry point, its sessions and (for scanWindow) the tenant.
	 *
	 * @return array<string,array{0:string,1:list<array<string,mixed>>,2:string}>
	 */
	private static function scenarios(): array {
		$a = self::session('s-a', '09:00', '10:00', ['cohortId' => 'c-a', 'roomId' => 'r-1']);
		$b = self::session('s-b', '09:30', '10:30', ['cohortId' => 'c-b', 'roomId' => 'r-1']);
		$c = self::session('s-c', '09:45', '11:00', ['cohortId' => 'c-a', 'substituteTeacherId' => 't-2']);
		$far = self::session('s-far', '14:00', '15:00', ['cohortId' => 'c-a', 'roomId' => 'r-1']);
		$big = self::session('s-big', '12:00', '13:00', ['cohortId' => 'c-big', 'roomId' => 'r-small']);
		$small = self::session('s-small', '12:30', '13:30', ['cohortId' => 'c-big', 'roomId' => 'r-big']);
		$noCap = self::session('s-nocap', '16:00', '17:00', ['cohortId' => 'c-big', 'roomId' => 'r-none']);
		$uuidOnly = ['uuid' => 's-u'] + self::session('', '09:10', '09:20', ['cohortId' => 'c-b', 'roomId' => 'r-1']);
		unset($uuidOnly['id']);
		return [
			'mixed overlaps in a day' => ['scan', [$a, $b, $c, $far], ''],
			'capacity and exams' => ['scan', [$big, $small, $noCap, self::session('s-big2', '20:00', '21:00', ['cohortId' => 'c-big', 'roomId' => 'r-small'])], ''],
			'uuid-only and day-bucket sessions' => ['scan', [$uuidOnly, $a], ''],
			'missing cohort and room' => [
				'scan',
				[
					self::session('s-x', '09:00', '10:00', ['cohortId' => 'c-missing', 'roomId' => 'r-missing']),
					self::session('s-y', '09:00', '10:00', ['cohortId' => 'c-missing', 'roomId' => 'r-missing']),
				],
				'',
			],
			'no tenant' => ['scan', [array_merge($a, ['tenant_id' => null]), array_merge($b, ['tenant_id' => null])], ''],
			'single session without bucket' => ['scan', [self::session('s-solo', 'xx', 'yy', ['cohortId' => 'c-a'])], ''],
			'empty input' => ['scan', [], ''],
			'one conflict, uuid-only second' => [
				'scan',
				[
					['id' => 's-p', 'startsAt' => '2026-02-03T08:00:00+00:00', 'endsAt' => '2026-02-03T09:00:00+00:00', 'cohortId' => 'c-a', 'roomId' => 'r-9'],
					['uuid' => 's-q', 'startsAt' => '2026-02-03T08:30:00+00:00', 'endsAt' => '2026-02-03T09:30:00+00:00', 'cohortId' => 'c-c', 'roomId' => 'r-9'],
				],
				'',
			],
			'exam in a missing room' => ['scan', [self::session('s-lost', '18:00', '19:00', ['cohortId' => 'c-b', 'roomId' => 'r-missing'])], ''],
			'window of lessons' => ['scanWindow', [$a, $b, array_merge($c, ['lifecycle' => 'cancelled']), $big, $small], 'tenant-9'],
			'window with one lesson' => ['scanWindow', [$a, ['startsAt' => 'x']], 'tenant-9'],
		];
	}//end scenarios()

	/**
	 * Every scenario's calls equal the recorded golden.
	 *
	 * @return void
	 */
	public function testEveryScenarioMakesTheRecordedCalls(): void {
		$actual = [];
		foreach (self::scenarios() as $name => [$entry, $sessions, $tenant]) {
			$actual[$name] = $this->callsFor($entry, $sessions, $tenant);
		}

		$json = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) . "\n";
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		$this->assertSame(file_get_contents(self::GOLDEN), $json);
	}//end testEveryScenarioMakesTheRecordedCalls()

	/**
	 * Run one entry point over the store and return the calls it made.
	 *
	 * @param string                     $entry    'scan' or 'scanWindow'.
	 * @param list<array<string,mixed>>  $sessions The sessions passed in.
	 * @param string                     $tenant   The scanWindow tenant.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function callsFor(string $entry, array $sessions, string $tenant): array {
		$calls   = [];
		$store   = self::store();
		$service = $this->createMock(ObjectService::class);
		$service->method('findAll')->willReturnCallback(
			static function (...$args) use ($store, &$calls): array {
				$calls[] = ['findAll' => self::named('findAll', $args)];
				$config  = $args[0];
				$schema  = (string)$config['filters']['schema'];
				$rows    = array_values(
					array_filter(
						$store[$schema] ?? [],
						static function (array $row) use ($config): bool {
							if (isset($config['ids']) === true) {
								return ($row['id'] ?? null) === $config['ids'][0];
							}

							foreach (['sessionId', 'sessionDayBucket'] as $key) {
								if (isset($config['filters'][$key]) === true && ($row[$key] ?? null) !== $config['filters'][$key]) {
									return false;
								}
							}

							return true;
						}
					)
				);
				return array_map(static fn (array $row) => OrEntityFactory::make($row, $schema), $rows);
			}
		);
		$service->method('saveObject')->willReturnCallback(
			static function (...$args) use (&$calls) {
				$named = self::named('saveObject', $args);
				$stamp = (string)($named['object']['detectedAt'] ?? '');
				if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $stamp) === 1) {
					$named['object']['detectedAt'] = '<utc-atom>';
				}

				$calls[] = ['saveObject' => $named];
				return OrEntityFactory::make($named['object'], (string)$named['schema']);
			}
		);
		$sink   = static function (array $entry) use (&$calls): void {
			$calls[] = $entry;
		};
		$logger = new class($sink) extends AbstractLogger {
			/**
			 * @param Closure $sink Appends one entry to the call log.
			 */
			public function __construct(private Closure $sink) {
			}

			/**
			 * @param mixed               $level   Level.
			 * @param string|Stringable   $message Message.
			 * @param array<string,mixed> $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				($this->sink)(['log' => [$level, (string)$message, $context]]);
			}
		};

		$detector = new TimetableConflictDetector($service, new SessionWindowLoader($service), new SessionOverlapEvaluator(), $logger);
		if ($entry === 'scan') {
			$detector->scan($sessions);
			return $calls;
		}

		$detector->scanWindow($sessions, $tenant);
		return $calls;
	}//end callsFor()

	/**
	 * A mock call's positional arguments keyed by the loaded ObjectService's parameter names.
	 *
	 * @param string      $method The ObjectService method.
	 * @param list<mixed> $args   The positional arguments.
	 *
	 * @return array<string,mixed>
	 */
	private static function named(string $method, array $args): array {
		$params = (new ReflectionMethod(ObjectService::class, $method))->getParameters();
		$named  = [];
		foreach ($args as $i => $value) {
			if ($params[$i]->isDefaultValueAvailable() === true && $params[$i]->getDefaultValue() === $value) {
				continue;
			}

			$named[$params[$i]->getName()] = $value;
		}

		return $named;
	}//end named()
}//end class
