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
 * @spec openspec/changes/attendance-threshold-calculation/specs/attendance/spec.md#scenario-a-guarded-manual-check-records-a-real-per-learner-crossing-and-creates-an-attendanceflag
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Lifecycle\AttendanceFlagCreationHandler;
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
				return OrEntityFactory::make($data, (string)$schema, (string)$register);
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $query) {
				$schema = (string)($query['schema'] ?? '');
				return $this->findAllResults[$schema] ?? [];
			}
		);

		return new AttendanceFlagCreationHandler($objectService, new NullLogger());

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
	 * @spec openspec/changes/attendance-threshold-calculation/specs/attendance/spec.md#scenario-a-guarded-manual-check-records-a-real-per-learner-crossing-and-creates-an-attendanceflag
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
