<?php

/**
 * Tests for PlanninqTimetableImport, against verbatim copies of integriq's
 * RosterImportRequestedEvent and planninq's TimetableSessionsQueryEvent.
 *
 * @category Test
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The planninq path of a timetable-import job.
 */
class PlanninqTimetableImportTest extends TestCase {

	/**
	 * Every integriq request, in order.
	 *
	 * @var array<int,RosterImportRequestedEvent>
	 */
	public array $requests = [];

	/**
	 * What integriq answers: a result array, or null to stay silent.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $integriqAnswer = null;

	/**
	 * Criteria of every planninq query, in order.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $queries = [];

	/**
	 * The conflict detector double.
	 *
	 * @var TimetableConflictDetector&MockObject
	 */
	private TimetableConflictDetector&MockObject $detector;

	/**
	 * Reset.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->requests = [];
		$this->queries = [];
		$this->integriqAnswer = $this->delivered(created: 2, rejected: [['externalRef' => 'zm-9', 'errorCode' => 'missing-fields', 'errorMessage' => 'Missing required field(s): subject']]);
		$this->detector = $this->createMock(TimetableConflictDetector::class);

	}//end setUp()

	/**
	 * A delivered integriq result.
	 *
	 * @param int                            $created  Lessons planninq created.
	 * @param array<int,array<string,mixed>> $rejected Planninq rejections.
	 *
	 * @return array<string,mixed>
	 */
	public function delivered(int $created, array $rejected = []): array {
		return [
			'contractVersion' => 1,
			'status' => 'delivered',
			'systemId' => 'roster-zermelo',
			'target' => 'planninq',
			'flavour' => 'mock',
			'active' => false,
			'fetched' => ($created + count($rejected)),
			'planninq' => ['contractVersion' => 1, 'processed' => ($created + count($rejected)), 'created' => $created, 'updated' => 0, 'unchanged' => 0, 'rejected' => $rejected, 'sessionIds' => []],
		];

	}//end delivered()

	/**
	 * Build the import over a dispatcher that plays integriq and planninq.
	 *
	 * @param bool   $planninqInstalled Whether planninq is installed.
	 * @param string $integriqEvent     Integriq's event class name.
	 *
	 * @return PlanninqTimetableImport
	 */
	public function import(bool $planninqInstalled = true, string $integriqEvent = PlanninqTimetableImport::INTEGRIQ_EVENT, string $keptMaps = ''): PlanninqTimetableImport {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				if ($event instanceof RosterImportRequestedEvent) {
					$this->requests[] = $event;
					if ($this->integriqAnswer !== null) {
						$event->setResult($this->integriqAnswer);
					}

					return;
				}

				if ($event instanceof TimetableSessionsQueryEvent) {
					$this->queries[] = $event->getCriteria();
					$criteria = $event->getCriteria();
					$event->setSessions(
						[
							['id' => 'p-1', 'title' => 'Wiskunde', 'startsAt' => '2026-09-28T09:00:00+02:00', 'endsAt' => '2026-09-28T09:50:00+02:00', 'cohortId' => ($criteria['cohortId'] ?? ''), 'teacherUserId' => 'jan', 'status' => 'scheduled'],
							['id' => 'p-2', 'title' => 'Engels', 'startsAt' => '2026-09-28T09:10:00+02:00', 'endsAt' => '2026-09-28T10:00:00+02:00', 'cohortId' => 'c-2', 'teacherUserId' => 'jan', 'status' => 'scheduled'],
						]
					);
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

		return new PlanninqTimetableImport($resolver, $dispatcher, $this->detector, new NullLogger(), new TimetableExchangeSettings($settingsConfig), $integriqEvent);

	}//end import()

	/**
	 * A Zermelo job is delivered by integriq; counts, rejections and the transition come back.
	 *
	 * @return void
	 */
	public function testZermeloJobIsDeliveredAndRecorded(): void {
		$job = ['id' => 'job-1', 'target' => 'timetable-import', 'tenant_id' => 't-1', 'scope' => ['schema' => 'session', 'groupMap' => ['3a' => 'c-1', 'x' => ''], 'teacherMap' => ['JAN' => 'jan']]];
		$profile = ['name' => 'Zermelo timetable import', 'targetSchema' => 'Zermelo:Appointment'];

		$import = $this->import();
		$this->assertTrue($import->applies());
		$outcome = $import->deliver($job, $profile);

		$this->assertCount(1, $this->requests);
		$this->assertSame('learniq', $this->requests[0]->getSourceApp());
		$this->assertSame('roster-zermelo', $this->requests[0]->getSystemId());
		$this->assertSame('job-1', $this->requests[0]->getCorrelationId());
		$this->assertSame(['3a' => 'c-1'], $this->requests[0]->getOptions()['groupMap'], 'empty map entries are not sent');

		$this->assertSame('partial', $outcome['state']);
		$result = $outcome['fields']['result'];
		$this->assertSame(3, $result['recordsProcessed']);
		$this->assertSame(2, $result['recordsAccepted']);
		$this->assertSame(1, $result['recordsRejected']);
		$this->assertSame('zm-9', $result['validationReport'][0]['recordId']);
		$this->assertSame('planninq', $result['target']);
		$this->assertSame('mock', $result['flavour']);
	}//end testZermeloJobIsDeliveredAndRecorded()

	/**
	 * A request without its own group map sends the one the administrator
	 * keeps for its rostering system; a posted map wins.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-an-import-without-a-posted-map-uses-the-kept-map
	 */
	public function testAnImportWithoutAMapUsesTheKeptOne(): void {
		$import = $this->import(keptMaps: '{"roster-zermelo":{"4H1":"cohort-1"}}');
		$this->integriqAnswer = $this->delivered(created: 1);

		$import->deliver(['id' => 'j', 'scope' => ['rosterSource' => 'roster-zermelo']], null);
		$import->deliver(['id' => 'j', 'scope' => ['rosterSource' => 'roster-zermelo', 'groupMap' => ['1A' => 'cohort-9']]], null);
		$import->deliver(['id' => 'j', 'scope' => ['rosterSource' => 'roster-xedule']], null);

		$this->assertSame(['4H1' => 'cohort-1'], $this->requests[0]->getOptions()['groupMap']);
		$this->assertSame(['1A' => 'cohort-9'], $this->requests[1]->getOptions()['groupMap']);
		$this->assertSame([], $this->requests[2]->getOptions()['groupMap']);
	}//end testAnImportWithoutAMapUsesTheKeptOne()

	/**
	 * The rostering source comes from the scope first, then from the profile's vendor.
	 *
	 * @return void
	 */
	public function testRosterSourceComesFromScopeThenProfile(): void {
		$import = $this->import();
		$this->integriqAnswer = $this->delivered(created: 1);

		$import->deliver(['id' => 'j', 'scope' => ['rosterSource' => 'roster-timeedit']], ['targetSchema' => 'Zermelo:Appointment']);
		$import->deliver(['id' => 'j', 'scope' => ['rosterSource' => 'nonsense']], ['targetSchema' => 'Untis:Period']);
		$import->deliver(['id' => 'j', 'scope' => []], ['name' => 'Xedule timetable import']);

		$this->assertSame(
			['roster-timeedit', 'roster-untis-oneroster', 'roster-xedule'],
			array_map(static fn (RosterImportRequestedEvent $e): string => $e->getSystemId(), $this->requests)
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('which rostering system');
		$import->deliver(['id' => 'j', 'scope' => []], null);
	}//end testRosterSourceComesFromScopeThenProfile()

	/**
	 * Integriq absent, silent or failing: a readable exception, never a delivered job.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/timetable-source/spec.md#requirement-a-timetable-import-job-delivers-into-planninq-when-planninq-is-the-source-req-003
	 */
	public function testIntegriqAbsentSilentOrFailingIsAReadableFailure(): void {
		$job = ['id' => 'j', 'scope' => ['rosterSource' => 'roster-zermelo']];
		$cases = [
			'absent' => [$this->import(integriqEvent: 'OCA\\Integriq\\Event\\NoSuchEvent'), null, 'Integriq is not installed'],
			'silent' => [$this->import(), null, 'did not answer'],
			'failed' => [$this->import(), ['contractVersion' => 1, 'status' => 'failed', 'errorCode' => 'planninq-absent', 'error' => 'Planninq is not installed'], 'planninq-absent'],
		];

		foreach ($cases as $label => [$import, $answer, $message]) {
			$this->integriqAnswer = $answer;
			try {
				$import->deliver($job, null);
				$this->fail("{$label}: must not pass as delivered");
			} catch (RuntimeException $e) {
				$this->assertStringContainsString($message, $e->getMessage(), $label);
			}
		}
	}//end testIntegriqAbsentSilentOrFailingIsAReadableFailure()

	/**
	 * Without planninq the path does not apply.
	 *
	 * @return void
	 */
	public function testDoesNotApplyWithoutPlanninq(): void {
		$this->assertFalse($this->import(planninqInstalled: false)->applies());
	}//end testDoesNotApplyWithoutPlanninq()

	/**
	 * The conflict scan reads planninq for the mapped cohorts and teachers and scans the lessons once each.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/timetable-source/spec.md#requirement-conflict-detection-runs-on-the-adapters-lessons-req-004
	 */
	public function testConflictScanRunsOnPlanninqLessons(): void {
		$this->detector->expects($this->once())->method('scanWindow')->with(
			$this->callback(static fn (array $lessons): bool => array_column($lessons, 'id') === ['p-1', 'p-2'] && $lessons[0]['source'] === 'planninq'),
			't-1'
		);

		$scanned = $this->import()->scanConflicts(
			['id' => 'j', 'tenant_id' => 't-1', 'scope' => ['groupMap' => ['3a' => 'c-1'], 'teacherMap' => ['JAN' => 'jan'], 'from' => '2026-09-28T00:00:00+02:00', 'to' => '2026-10-04T00:00:00+02:00']]
		);

		$this->assertSame(2, $scanned, 'lessons found by cohort and by teacher are scanned once');
		$this->assertSame('c-1', $this->queries[0]['cohortId']);
		$this->assertSame('jan', $this->queries[1]['teacherUserId']);
		$this->assertSame('2026-09-28T00:00:00+02:00', $this->queries[0]['from']);
	}//end testConflictScanRunsOnPlanninqLessons()

	/**
	 * With nothing mapped there is nothing to read, and nothing is scanned.
	 *
	 * @return void
	 */
	public function testConflictScanWithoutMapsScansNothing(): void {
		$this->detector->expects($this->never())->method('scanWindow');

		$this->assertSame(0, $this->import()->scanConflicts(['id' => 'j', 'scope' => []]));
		$this->assertSame([], $this->queries);
	}//end testConflictScanWithoutMapsScansNothing()
}//end class
