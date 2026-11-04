<?php

/**
 * Learniq PlanninqTimetableImport golden tests.
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
 * @spec openspec/specs/timetable-source/spec.md#requirement-a-timetable-import-job-delivers-into-planninq-when-planninq-is-the-source-req-003
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Timetabling;

if (class_exists('\\OCA\\Integriq\\Event\\RosterImportRequestedEvent') === false) {
	require_once __DIR__ . '/../../Stubs/Integriq/Event/RosterImportRequestedEvent.php';
}

if (class_exists('\\OCA\\Planninq\\Event\\TimetableSessionsQueryEvent') === false) {
	require_once __DIR__ . '/../../Stubs/Planninq/Event/TimetableSessionsQueryEvent.php';
}

use OCA\Integriq\Event\RosterImportRequestedEvent;
use OCA\Learniq\Service\TimetableExchangeSettings;
use OCA\Learniq\Tests\Support\CapturingLogger;
use OCA\Learniq\Timetabling\PlanninqTimetableImport;
use OCA\Learniq\Timetabling\Source\LocalSessionTimetableSource;
use OCA\Learniq\Timetabling\Source\PlanninqTimetableSource;
use OCA\Learniq\Timetabling\Source\TimetableSourceResolver;
use OCA\Learniq\Timetabling\TimetableConflictDetector;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Planninq\Event\TimetableSessionsQueryEvent;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins what deliver() and scanConflicts() return, throw, send and log per
 * scenario against tests/Unit/Timetabling/golden/PlanninqTimetableImport.json.
 * With RECORD_GOLDEN=1 the file is written instead of compared.
 *
 * @covers \OCA\Learniq\Timetabling\PlanninqTimetableImport
 * @uses   \OCA\Learniq\AppInfo\OpenRegisterAutoloader
 * @uses   \OCA\Learniq\Service\TimetableExchangeSettings
 * @uses   \OCA\Learniq\Timetabling\Source\LocalSessionTimetableSource
 * @uses   \OCA\Learniq\Timetabling\Source\PlanninqTimetableSource
 * @uses   \OCA\Learniq\Timetabling\Source\TimetableSourceResolver
 */
class PlanninqTimetableImportGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/PlanninqTimetableImport.json';

	private const ZERMELO = ['name' => 'Zermelo timetable import', 'targetSchema' => 'Zermelo:Appointment'];

	/**
	 * Everything the import did in one scenario.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $calls = [];

	/**
	 * A delivered integriq result.
	 *
	 * @param array<string,mixed>|null $planninq The planninq part, or null to leave it out.
	 * @param string|null              $flavour  The flavour, or null to leave it out.
	 *
	 * @return array<string,mixed>
	 */
	private static function delivered(?array $planninq, ?string $flavour = 'mock'): array {
		$result = ['contractVersion' => 1, 'status' => 'delivered', 'systemId' => 'roster-zermelo', 'target' => 'planninq'];
		if ($flavour !== null) {
			$result['flavour'] = $flavour;
		}

		if ($planninq !== null) {
			$result['planninq'] = $planninq;
		}

		return $result;
	}//end delivered()

	/**
	 * Delivery scenarios: job, profile, integriq answer (null: silent), integriq event class, kept maps.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:array<string,mixed>|null,2:array<string,mixed>|null,3:string,4:string}>
	 */
	public static function deliveries(): array {
		$event = PlanninqTimetableImport::INTEGRIQ_EVENT;
		$rejected = [['externalRef' => 'zm-9', 'errorCode' => 'missing-fields', 'errorMessage' => 'Missing subject'], ['errorCode' => 7], []];
		$job = ['id' => 'job-1', 'tenant_id' => 't-1', 'scope' => ['groupMap' => ['3a' => 'c-1', 'x' => '', '' => 'c-0', 'n' => 5, 'arr' => ['c']], 'teacherMap' => ['JAN' => 'jan']]];
		return [
			'partial' => [$job, self::ZERMELO, self::delivered(['processed' => 3, 'created' => 2, 'rejected' => [$rejected[0]]]), $event, ''],
			'all accepted' => [$job, self::ZERMELO, self::delivered(['processed' => 6, 'created' => 1, 'updated' => 2, 'unchanged' => 3, 'rejected' => []]), $event, ''],
			'all rejected' => [$job, self::ZERMELO, self::delivered(['processed' => '3', 'created' => 0, 'rejected' => $rejected]), $event, ''],
			'nothing at all' => [$job, self::ZERMELO, self::delivered(['processed' => 0]), $event, ''],
			'negative accepted with a rejection' => [$job, self::ZERMELO, self::delivered(['created' => -1, 'rejected' => [$rejected[0]]]), $event, ''],
			'no planninq part, no flavour' => [$job, self::ZERMELO, self::delivered(null, null), $event, ''],
			'uuid correlation' => [['uuid' => 'u-1', 'scope' => ['rosterSource' => 'roster-xedule']], null, self::delivered(['created' => 1]), $event, ''],
			'no id at all' => [['scope' => ['rosterSource' => 'roster-untis-oneroster', 'teacherMap' => 'nope']], null, self::delivered(['created' => 1]), $event, ''],
			'kept map' => [['id' => 'j', 'scope' => ['rosterSource' => 'roster-zermelo']], null, self::delivered(['created' => 1]), $event, '{"roster-zermelo":{"4H1":"cohort-1"}}'],
			'posted map wins over kept' => [['id' => 'j', 'scope' => ['rosterSource' => 'roster-zermelo', 'groupMap' => ['1A' => 'c-9']]], null, self::delivered(['created' => 1]), $event, '{"roster-zermelo":{"4H1":"cohort-1"}}'],
			'scope not an array' => [['id' => 'j', 'scope' => 'x'], ['targetSchema' => 'TimeEdit:Booking'], self::delivered(['created' => 1]), $event, ''],
			'unknown source in scope, vendor in profile name' => [['id' => 'j', 'scope' => ['rosterSource' => 'zermelo']], ['name' => 'My UNTIS feed'], self::delivered(['created' => 1]), $event, ''],
			'no rostering system' => [['id' => 'j', 'scope' => []], null, self::delivered(['created' => 1]), $event, ''],
			'integriq absent' => [$job, self::ZERMELO, self::delivered(['created' => 1]), 'OCA\\Integriq\\Event\\NoSuchEvent', ''],
			'integriq silent' => [$job, self::ZERMELO, null, $event, ''],
			'integriq failed' => [$job, self::ZERMELO, ['status' => 'failed', 'errorCode' => 'planninq-absent', 'error' => 'Planninq is not installed'], $event, ''],
			'integriq failed without code' => [$job, self::ZERMELO, ['status' => 'other'], $event, ''],
		];
	}//end deliveries()

	/**
	 * Conflict-scan scenarios: job, whether planninq is installed.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:bool}>
	 */
	public static function scans(): array {
		$window = ['from' => '2026-09-28T00:00:00+02:00', 'to' => '2026-10-04T00:00:00+02:00'];
		return [
			'cohorts and teachers' => [['id' => 'j', 'tenant_id' => 't-1', 'scope' => ['groupMap' => ['3a' => 'c-1', '3b' => 'c-2'], 'teacherMap' => ['JAN' => 'jan', 'J2' => 'jan', 'P' => 'piet']] + $window], true],
			'explicit window' => [['id' => 'j', 'tenant_id' => 't-1', 'scope' => ['groupMap' => ['3a' => 'c-1']] + $window], true],
			'teachers only, no tenant' => [['id' => 'j', 'scope' => ['teacherMap' => ['P' => 'piet']] + $window], true],
			'nothing mapped' => [['id' => 'j', 'scope' => $window], true],
			'kept map' => [['id' => 'j', 'scope' => ['rosterSource' => 'roster-zermelo'] + $window], true],
			'planninq absent' => [['id' => 'j', 'tenant_id' => 't-1', 'scope' => ['groupMap' => ['3a' => 'c-1']] + $window], false],
			'planninq does not answer for a teacher' => [['id' => 'j', 'tenant_id' => 't-1', 'scope' => ['groupMap' => ['3a' => 'c-1'], 'teacherMap' => ['S' => 'c-silent']] + $window], true],
			'only lessons without an id' => [['id' => 'j', 'tenant_id' => 't-1', 'scope' => ['groupMap' => ['3a' => 'c-empty']] + $window], true],
		];
	}//end scans()

	/**
	 * deliver() matches the recorded scenario.
	 *
	 * @param array<string,mixed>      $job      The job.
	 * @param array<string,mixed>|null $profile  The mapping profile.
	 * @param array<string,mixed>|null $answer   Integriq's answer, null for none.
	 * @param string                   $event    Integriq's event class.
	 * @param string                   $keptMaps The kept group maps (JSON).
	 *
	 * @return void
	 */
	#[DataProvider('deliveries')]
	public function testDeliverMatchesGolden(array $job, ?array $profile, ?array $answer, string $event, string $keptMaps): void {
		$logger = new CapturingLogger();
		$import = $this->import(answer: $answer, planninqInstalled: true, event: $event, keptMaps: $keptMaps, logger: $logger);
		try {
			$outcome = $import->deliver($job, $profile);
			if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', (string)$outcome['fields']['finishedAt']) === 1) {
				$outcome['fields']['finishedAt'] = '<date-c>';
			}

			$actual = ['outcome' => $outcome];
		} catch (RuntimeException $e) {
			$actual = ['exception' => $e->getMessage()];
		}

		$actual['calls'] = $this->calls;
		$actual['log']   = $logger->records;
		$this->assertGolden(actual: $actual);
	}//end testDeliverMatchesGolden()

	/**
	 * scanConflicts() matches the recorded scenario.
	 *
	 * @param array<string,mixed> $job               The job.
	 * @param bool                $planninqInstalled Whether planninq is installed.
	 *
	 * @return void
	 */
	#[DataProvider('scans')]
	public function testScanConflictsMatchesGolden(array $job, bool $planninqInstalled): void {
		$logger = new CapturingLogger();
		$import = $this->import(answer: null, planninqInstalled: $planninqInstalled, event: PlanninqTimetableImport::INTEGRIQ_EVENT, keptMaps: '{"roster-zermelo":{"4H1":"c-kept"}}', logger: $logger);
		$actual = ['scanned' => $import->scanConflicts($job), 'calls' => $this->calls, 'log' => $logger->records];
		$this->assertGolden(actual: $actual);
	}//end testScanConflictsMatchesGolden()

	/**
	 * Without a window in the scope the scan reads from now to fourteen days ahead.
	 *
	 * @return void
	 */
	public function testTheDefaultWindowIsTheNextFourteenDays(): void {
		$before = time();
		$this->import(answer: null, planninqInstalled: true, event: PlanninqTimetableImport::INTEGRIQ_EVENT, keptMaps: '', logger: new CapturingLogger())
			->scanConflicts(['id' => 'j', 'scope' => ['groupMap' => ['3a' => 'c-1']]]);
		$after = time();

		$criteria = $this->calls[0]['planninqQuery'];
		$from = strtotime((string)$criteria['from']);
		$to = strtotime((string)$criteria['to']);
		$this->assertGreaterThanOrEqual($before, $from);
		$this->assertLessThanOrEqual($after, $from);
		$this->assertGreaterThanOrEqual($before + (14 * 86400), $to);
		$this->assertLessThanOrEqual($after + (14 * 86400), $to);
	}//end testTheDefaultWindowIsTheNextFourteenDays()

	/**
	 * Compare with, or record, this data set's golden entry.
	 *
	 * @param array<string,mixed> $actual What the scenario did.
	 *
	 * @return void
	 */
	private function assertGolden(array $actual): void {
		$name   = $this->name() . ' ' . (string)$this->dataName();
		$golden = [];
		if (file_exists(self::GOLDEN) === true) {
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		if (getenv('RECORD_GOLDEN') === '1') {
			$golden[$name] = json_decode((string)json_encode($actual), true);
			ksort($golden);
			file_put_contents(self::GOLDEN, json_encode($golden, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$this->assertArrayHasKey($name, $golden);
		$this->assertSame($golden[$name], json_decode((string)json_encode($actual), true));
	}//end assertGolden()

	/**
	 * Build the import over a dispatcher that plays integriq and planninq, recording every call.
	 *
	 * @param array<string,mixed>|null $answer            Integriq's answer, null for none.
	 * @param bool                     $planninqInstalled Whether planninq is installed.
	 * @param string                   $event             Integriq's event class.
	 * @param string                   $keptMaps          The kept group maps (JSON).
	 * @param CapturingLogger          $logger            The logger.
	 *
	 * @return PlanninqTimetableImport
	 */
	private function import(?array $answer, bool $planninqInstalled, string $event, string $keptMaps, CapturingLogger $logger): PlanninqTimetableImport {
		$this->calls = [];
		$dispatcher  = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event) use ($answer): void {
				if ($event instanceof RosterImportRequestedEvent) {
					$this->calls[] = ['integriq' => [$event->getSourceApp(), $event->getSystemId(), $event->getOptions(), $event->getCorrelationId()]];
					if ($answer !== null) {
						$event->setResult($answer);
					}

					return;
				}

				if ($event instanceof TimetableSessionsQueryEvent) {
					$criteria      = $event->getCriteria();
					$this->calls[] = ['planninqQuery' => $criteria];
					$key = (string)($criteria['cohortId'] ?? ($criteria['teacherUserId'] ?? ''));
					$lessons = [
						['id' => 'p-' . $key, 'title' => 'Les', 'startsAt' => '2026-09-28T09:00:00+02:00', 'endsAt' => '2026-09-28T09:50:00+02:00', 'cohortId' => 'c-1', 'teacherUserId' => 'jan', 'status' => 'scheduled'],
						['id' => 'p-shared', 'title' => 'Gedeeld', 'startsAt' => '2026-09-28T10:00:00+02:00', 'endsAt' => '2026-09-28T10:50:00+02:00', 'cohortId' => 'c-2', 'teacherUserId' => 'piet', 'status' => 'scheduled'],
					];
					if ($key === 'c-silent') {
						return;
					}

					if ($key === 'c-empty') {
						$lessons = [['title' => 'Zonder id', 'startsAt' => '2026-09-28T09:00:00+02:00', 'endsAt' => '2026-09-28T09:50:00+02:00']];
					}

					$event->setSessions($lessons);
				}
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($planninqInstalled);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('auto');
		$resolver = new TimetableSourceResolver(
			$config,
			new LocalSessionTimetableSource($this->createMock(ObjectService::class)),
			new PlanninqTimetableSource($appManager, $dispatcher)
		);

		$settingsConfig = $this->createMock(IAppConfig::class);
		$settingsConfig->method('getValueString')->willReturn($keptMaps);

		$detector = $this->createMock(TimetableConflictDetector::class);
		$detector->method('scanWindow')->willReturnCallback(
			function (array $sessions, string $tenantId): void {
				$this->calls[] = ['scanWindow' => [array_column($sessions, 'id'), $tenantId]];
			}
		);

		return new PlanninqTimetableImport($resolver, $dispatcher, $detector, $logger, new TimetableExchangeSettings($settingsConfig), $event);
	}//end import()
}//end class
