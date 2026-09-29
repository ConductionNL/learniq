<?php

/**
 * Learniq AttendanceFlagCreationHandler unit tests.
 *
 * Covers the guarded-manual-transition bridge fixed by
 * attendance-threshold-calculation: a `check-threshold` transition (never a
 * `threshold-crossed` state, which does not exist) creates an AttendanceFlag
 * reading learner/metric/window/breaching-record detail from the object
 * itself (never a getContext() call, which does not exist on
 * ObjectTransitionedEvent).
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/attendance/spec.md#scenario-a-guarded-manual-check-records-a-real-per-learner-crossing-and-creates-an-attendanceflag
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Exception\IntegriqUnavailableException;
use OCA\Learniq\Lifecycle\AttendanceFlagCreationHandler;
use OCA\Learniq\Service\IntegriqExchangeClient;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for AttendanceFlagCreationHandler::handle() on the check-threshold transition.
 */
class AttendanceFlagCreationHandlerTest extends TestCase {

	/**
	 * Recorded saveObject() calls.
	 *
	 * @var array<int, array{register: string, schema: string, object: array<string, mixed>}>
	 */
	private array $savedObjects = [];

	/**
	 * Recorded findAll() queries, keyed by schema.
	 *
	 * @var array<string, array<int, array<string,mixed>>>
	 */
	private array $findAllResults = [];

	/**
	 * The integriq client double.
	 *
	 * @var IntegriqExchangeClient&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $integriq;

	/**
	 * Reset capture buffers before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->savedObjects = [];
		$this->findAllResults = [];

	}//end setUp()

	/**
	 * Build a handler with stubbed collaborators.
	 *
	 * @return AttendanceFlagCreationHandler
	 */
	private function makeHandler(): AttendanceFlagCreationHandler {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null): ObjectEntity {
				$data = ($object instanceof ObjectEntity) ? $object->jsonSerialize() : $object;
				$this->savedObjects[] = [
					'register' => (string)$register,
					'schema' => (string)$schema,
					'object' => $data,
				];
				return OrEntityFactory::make($data, (string)$schema, (string)$register, 'saved-' . count($this->savedObjects));
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $query) {
				$schema = (string)($query['filters']['schema'] ?? '');
				return $this->findAllResults[$schema] ?? [];
			}
		);

		$this->integriq = $this->createMock(IntegriqExchangeClient::class);

		return new AttendanceFlagCreationHandler($objectService, $this->integriq, new NullLogger());

	}//end makeHandler()

	/**
	 * Build a mocked ObjectTransitionedEvent for a check-threshold transition.
	 *
	 * @param array<string, mixed> $thresholdData The AttendanceThreshold's jsonSerialize() payload.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function makeEvent(array $thresholdData): ObjectTransitionedEvent {
		$objectEntity = $this->createMock(ObjectEntity::class);
		$objectEntity->method('jsonSerialize')->willReturn($thresholdData);

		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn($objectEntity);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn('attendance-threshold');
		$event->method('getAction')->willReturn('check-threshold');
		$event->method('getTo')->willReturn('active');
		$event->method('getFrom')->willReturn('active');

		return $event;

	}//end makeEvent()

	/**
	 * A check-threshold transition creates an AttendanceFlag with the
	 * learner/metric/window/breaching-record detail read from the object's
	 * own checked* fields (never a getContext() call).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/attendance/spec.md#scenario-a-guarded-manual-check-records-a-real-per-learner-crossing-and-creates-an-attendanceflag
	 */
	public function testCheckThresholdCreatesAttendanceFlag(): void {
		$handler = $this->makeHandler();

		$threshold = [
			'id' => 'threshold-1',
			'cohortId' => 'cohort-1',
			'tenant_id' => 'tenant-a',
			'checkedLearnerId' => 'learner-1',
			'checkedMetricValue' => 18,
			'checkedWindowStart' => '2026-08-01',
			'checkedWindowEnd' => '2026-08-28',
			'checkedBreachingRecordIds' => ['rec-1', 'rec-2'],
			'onCross' => ['notify' => true, 'createFlag' => true, 'dataExchangeTarget' => null],
		];

		$handler->handle($this->makeEvent($threshold));

		$flagSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'attendance-flag'));
		self::assertCount(1, $flagSaves);
		self::assertSame('learner-1', $flagSaves[0]['object']['learnerId']);
		self::assertSame('threshold-1', $flagSaves[0]['object']['attendanceThresholdId']);
		self::assertSame('cohort-1', $flagSaves[0]['object']['cohortId']);
		self::assertSame('2026-08-01', $flagSaves[0]['object']['windowStart']);
		self::assertSame('2026-08-28', $flagSaves[0]['object']['windowEnd']);
		self::assertSame(18.0, $flagSaves[0]['object']['metricValue']);
		self::assertSame(['rec-1', 'rec-2'], $flagSaves[0]['object']['breachingRecordIds']);
		self::assertSame('open', $flagSaves[0]['object']['lifecycle']);

	}//end testCheckThresholdCreatesAttendanceFlag()

	/**
	 * A crossing whose threshold names a target asks integriq for the job,
	 * owned by the saved flag, and stamps the job id on the flag
	 * (data-exchange-to-integriq).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-learniq-asks-integriq-to-carry-an-exchange
	 */
	public function testAnAttendanceFlagAsksForALeerplichtReport(): void {
		$handler = $this->makeHandler();
		$this->integriq->expects($this->once())->method('requestJob')->with(
			'leerplicht',
			'export',
			'attendance-flag/saved-1',
			['schema' => 'attendance-flag', 'recordIds' => ['saved-1'], 'tenantId' => 'tenant-a'],
			'learniq-leerplicht-export-melding'
		)->willReturn('job-7');

		$handler->handle($this->makeEvent([
			'id' => 'threshold-1',
			'cohortId' => 'cohort-1',
			'tenant_id' => 'tenant-a',
			'checkedLearnerId' => 'learner-1',
			'checkedMetricValue' => 18,
			'checkedWindowStart' => '2026-08-01',
			'checkedWindowEnd' => '2026-08-28',
			'checkedBreachingRecordIds' => ['rec-1'],
			'onCross' => ['dataExchangeTarget' => 'leerplicht'],
		]));

		$flagSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'attendance-flag'));
		self::assertCount(2, $flagSaves, 'The flag is saved, then stamped with the job id.');
		self::assertNull($flagSaves[0]['object']['dataExchangeJobId']);
		self::assertSame('job-7', $flagSaves[1]['object']['dataExchangeJobId']);
		self::assertSame([], array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'data-exchange-job'));

	}//end testAnAttendanceFlagAsksForALeerplichtReport()

	/**
	 * Without integriq the flag is still saved, without a job id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-learniq-asks-integriq-to-carry-an-exchange
	 */
	public function testWithoutIntegriqTheFlagStaysWithoutAJob(): void {
		$handler = $this->makeHandler();
		$this->integriq->method('requestJob')->willThrowException(new IntegriqUnavailableException('Integriq is not installed.'));

		$handler->handle($this->makeEvent([
			'id' => 'threshold-1',
			'cohortId' => 'cohort-1',
			'tenant_id' => 'tenant-a',
			'checkedLearnerId' => 'learner-1',
			'checkedMetricValue' => 18,
			'checkedWindowStart' => '2026-08-01',
			'checkedWindowEnd' => '2026-08-28',
			'checkedBreachingRecordIds' => [],
			'onCross' => ['dataExchangeTarget' => 'leerplicht'],
		]));

		$flagSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'attendance-flag'));
		self::assertCount(1, $flagSaves);
		self::assertNull($flagSaves[0]['object']['dataExchangeJobId']);

	}//end testWithoutIntegriqTheFlagStaysWithoutAJob()

	/**
	 * Threshold kinds and the flag kind their crossing must carry; null means
	 * the flag leaves flagKind to the schema default.
	 *
	 * @return array<string, array{0: string|null, 1: string|null}>
	 */
	public static function thresholdKinds(): array {
		return [
			'leerplicht profile' => ['leerplicht-16uur', 'signal-verzuim'],
			'university workgroup requirement' => ['college-aanwezigheid', 'attendance-requirement'],
			'training attendance' => ['training-attendance', 'attendance-requirement'],
			'company compliance presence' => ['compliance-presence', 'attendance-requirement'],
			'generic threshold keeps the default' => ['generic', null],
			'threshold without a kind keeps the default' => [null, null],
		];
	}//end thresholdKinds()

	/**
	 * The flag kind follows the threshold kind, so a student under a
	 * workgroup requirement is not flagged as a leerplicht signal. Red
	 * before the fix: no flag carried a kind, so every flag read as
	 * `signal-verzuim`.
	 *
	 * @param string|null $thresholdKind AttendanceThreshold.kind.
	 * @param string|null $expected The flagKind the created flag carries, or null for none.
	 *
	 * @return void
	 *
	 * @dataProvider thresholdKinds
	 *
	 * @spec openspec/specs/attendance/spec.md#requirement-an-attendance-flag-outside-the-leerplicht-carries-a-neutral-kind
	 */
	public function testTheFlagKindFollowsTheThresholdKind(?string $thresholdKind, ?string $expected): void {
		$handler = $this->makeHandler();
		$threshold = [
			'id' => 'threshold-1',
			'tenant_id' => 'tenant-a',
			'checkedLearnerId' => 'learner-1',
			'checkedMetricValue' => 79.5,
			'checkedWindowStart' => '2026-02-01',
			'checkedWindowEnd' => '2026-06-30',
			'onCross' => ['notify' => true, 'createFlag' => true, 'dataExchangeTarget' => null],
		];
		if ($thresholdKind !== null) {
			$threshold['kind'] = $thresholdKind;
		}

		$handler->handle($this->makeEvent($threshold));

		$flagSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'attendance-flag'));
		self::assertCount(1, $flagSaves);
		if ($expected === null) {
			self::assertArrayNotHasKey('flagKind', $flagSaves[0]['object']);
			return;
		}

		self::assertSame($expected, $flagSaves[0]['object']['flagKind'] ?? null);
	}//end testTheFlagKindFollowsTheThresholdKind()

	/**
	 * Every flag kind the handler writes is one the register accepts, and the
	 * school default is unchanged.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/attendance/spec.md#requirement-an-attendance-flag-outside-the-leerplicht-carries-a-neutral-kind
	 */
	public function testTheRegisterAcceptsEveryFlagKindTheHandlerWrites(): void {
		$register = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/learniq_register.json'), true);
		$flagKind = $register['components']['schemas']['AttendanceFlag']['properties']['flagKind'];

		foreach (self::thresholdKinds() as [$_kind, $expected]) {
			if ($expected !== null) {
				self::assertContains($expected, $flagKind['enum']);
			}
		}

		self::assertSame('signal-verzuim', $flagKind['default']);
		self::assertContains('thuiszitter', $flagKind['enum']);
		self::assertTrue(version_compare($register['components']['schemas']['AttendanceFlag']['version'], '0.2.0', '>='));
	}//end testTheRegisterAcceptsEveryFlagKindTheHandlerWrites()

	/**
	 * A duplicate check for the same learner/threshold/window is skipped.
	 *
	 * @return void
	 */
	public function testDuplicateCrossingIsSkipped(): void {
		$handler = $this->makeHandler();
		$this->findAllResults['attendance-flag'] = [['id' => 'existing-flag']];

		$threshold = [
			'id' => 'threshold-1',
			'tenant_id' => 'tenant-a',
			'checkedLearnerId' => 'learner-1',
			'checkedMetricValue' => 18,
			'checkedWindowStart' => '2026-08-01',
		];

		$handler->handle($this->makeEvent($threshold));

		$flagSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'attendance-flag'));
		self::assertCount(0, $flagSaves);

	}//end testDuplicateCrossingIsSkipped()

	/**
	 * A transition action other than check-threshold is ignored — in
	 * particular the old (never-real) `threshold-crossed` `to`-state has no
	 * effect, only the action name matters.
	 *
	 * @return void
	 */
	public function testNonCheckThresholdActionIgnored(): void {
		$handler = $this->makeHandler();

		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn('attendance-threshold');
		$event->method('getAction')->willReturn('activate');
		$event->method('getTo')->willReturn('active');

		$handler->handle($event);

		self::assertCount(0, $this->savedObjects);

	}//end testNonCheckThresholdActionIgnored()

	/**
	 * A missing checkedLearnerId (and no fallback learnerId) skips flag creation.
	 *
	 * @return void
	 */
	public function testMissingLearnerIdSkips(): void {
		$handler = $this->makeHandler();

		$threshold = ['id' => 'threshold-1', 'tenant_id' => 'tenant-a'];

		$handler->handle($this->makeEvent($threshold));

		self::assertCount(0, $this->savedObjects);

	}//end testMissingLearnerIdSkips()

	/**
	 * A non-ObjectTransitionedEvent is ignored.
	 *
	 * @return void
	 */
	public function testNonMatchingEventTypeIgnored(): void {
		$handler = $this->makeHandler();

		$handler->handle($this->createMock(Event::class));

		self::assertCount(0, $this->savedObjects);

	}//end testNonMatchingEventTypeIgnored()
}//end class
