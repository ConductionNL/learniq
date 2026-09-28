<?php

/**
 * Learniq TimetableImportHandler unit tests.
 *
 * Mirrors DataExchangeRunHandlerTest's established convention of exercising
 * the private mapping/upsert methods via reflection rather than standing up
 * the full OpenConnector HTTP call chain (IClientService::newClient()->post()
 * is not exercised by any existing test in this suite either).
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
 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-timetable-import-upserts-session-objects-idempotently-by-externalref
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Timetabling;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Timetabling\PlanninqTimetableImport;
use OCA\Learniq\Timetabling\TimetableConflictDetector;
use OCA\Learniq\Timetabling\TimetableConnectorClient;
use OCA\Learniq\Timetabling\TimetableImportHandler;
use OCA\Learniq\Timetabling\TimetableRecordMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for TimetableImportHandler.
 */
class TimetableImportHandlerTest extends TestCase {

	/**
	 * ObjectService mock.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * TransitionEngine mock.
	 *
	 * @var TransitionEngine&MockObject
	 */
	private TransitionEngine&MockObject $transitionEngine;

	/**
	 * @var array<int,array{register:string,schema:string,object:array<string,mixed>}> Every saveObject() call, in order.
	 */
	private array $saves = [];

	/**
	 * @var array<string,array<int,array<string,mixed>>> Session fixtures, keyed by schema name 'session'.
	 */
	private array $existingSessions = [];

	/**
	 * @var array<string,array<int,array<string,mixed>>> Rows of other schemas (job, mapping profile), keyed by schema.
	 */
	private array $rows = [];

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->transitionEngine = $this->createMock(TransitionEngine::class);
		$this->saves = [];
		$this->existingSessions = [];
		$this->rows = [];

		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config): array {
				$schema = ($config['filters']['schema'] ?? '');
				if ($schema !== 'session') {
					$id = ($config['filters']['id'] ?? null);
					return array_values(
						array_filter(
							($this->rows[$schema] ?? []),
							static fn (array $row): bool => $id === null || ($row['id'] ?? null) === $id
						)
					);
				}

				$externalRef = $config['filters']['externalRef'] ?? null;
				if ($externalRef === null) {
					return [];
				}

				return array_values(
					array_filter(
						$this->existingSessions,
						static fn (array $s): bool => ($s['externalRef'] ?? null) === $externalRef
					)
				);
			}
		);

		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null) {
				$this->saves[] = [
					'register' => (string)$register,
					'schema' => (string)$schema,
					'object' => $object,
				];
				return OrEntityFactory::make($object, (string)$schema, (string)$register);
			}
		);

	}//end setUp()

	/**
	 * Build the handler under test.
	 *
	 * @param PlanninqTimetableImport|null $planninqImport The planninq path; by default one that does not apply.
	 *
	 * @return TimetableImportHandler
	 */
	private function handler(?PlanninqTimetableImport $planninqImport = null): TimetableImportHandler {
		if ($planninqImport === null) {
			$planninqImport = $this->createMock(PlanninqTimetableImport::class);
			$planninqImport->method('applies')->willReturn(false);
		}

		return new TimetableImportHandler(
			$this->objectService,
			$this->transitionEngine,
			$this->createMock(TimetableConflictDetector::class),
			new TimetableRecordMapper(),
			$planninqImport,
			$this->createMock(TimetableConnectorClient::class),
			new NullLogger()
		);

	}//end handler()

	/**
	 * Build the record mapper under test.
	 *
	 * @return TimetableRecordMapper
	 */
	private function mapper(): TimetableRecordMapper {
		return new TimetableRecordMapper();
	}//end mapper()

	/**
	 * Invoke a private method via reflection.
	 *
	 * @param object $object The instance.
	 * @param string $method Method name.
	 * @param array<int,mixed> $args Positional arguments.
	 *
	 * @return mixed The method's return value.
	 */
	private function invokePrivate(object $object, string $method, array $args) {
		$ref = new \ReflectionMethod($object, $method);
		return $ref->invokeArgs($object, $args);
	}//end invokePrivate()

	/**
	 * TimetableRecordMapper::map() resolves fieldMappings in reverse (targetField -> scholiqField).
	 *
	 * @return void
	 */
	public function testMapRecordAppliesFieldMappingsInReverse(): void {
		$profile = [
			'fieldMappings' => [
				['scholiqField' => 'externalRef', 'targetField' => 'appointmentId', 'transform' => null],
				['scholiqField' => 'cohortId', 'targetField' => 'groupInDepartment', 'transform' => null],
				['scholiqField' => 'title', 'targetField' => 'subjects', 'transform' => null],
				['scholiqField' => 'startsAt', 'targetField' => 'start', 'transform' => 'date-iso8601'],
				['scholiqField' => 'endsAt', 'targetField' => 'end', 'transform' => 'date-iso8601'],
			],
		];
		$record = [
			'appointmentId' => 'zerm-123',
			'groupInDepartment' => 'cohort-1',
			'subjects' => 'NIS2',
			'start' => '2026-02-02 09:00:00',
			'end' => '2026-02-02 10:00:00',
		];

		$mapped = $this->mapper()->map($record, $profile, 'tenant-a');

		self::assertSame('zerm-123', $mapped['externalRef']);
		self::assertSame('cohort-1', $mapped['cohortId']);
		self::assertSame('NIS2', $mapped['title']);
		self::assertSame('tenant-a', $mapped['tenant_id']);
		self::assertNotEmpty($mapped['startsAt']);

	}//end testMapRecordAppliesFieldMappingsInReverse()

	/**
	 * missingRequiredFields() detects every gap.
	 *
	 * @return void
	 */
	public function testMissingRequiredFieldsDetectsGaps(): void {
		$missing = $this->mapper()->missingRequiredFields(['title' => 'X']);

		self::assertContains('cohortId', $missing);
		self::assertContains('startsAt', $missing);
		self::assertContains('endsAt', $missing);
		self::assertContains('externalRef', $missing);
		self::assertNotContains('title', $missing);

	}//end testMissingRequiredFieldsDetectsGaps()

	/**
	 * missingRequiredFields() is empty for a fully-populated mapped record.
	 *
	 * @return void
	 */
	public function testMissingRequiredFieldsEmptyWhenComplete(): void {
		$missing = $this->mapper()->missingRequiredFields(
			['cohortId' => 'c-1', 'title' => 'X', 'startsAt' => 'a', 'endsAt' => 'b', 'externalRef' => 'ref-1']
		);

		self::assertSame([], $missing);

	}//end testMissingRequiredFieldsEmptyWhenComplete()

	/**
	 * A first-time import (no matching externalRef) creates a new, `scheduled` Session.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-re-importing-the-same-timetable-does-not-duplicate-sessions
	 */
	public function testUpsertSessionCreatesOnFirstImport(): void {
		$mapped = ['externalRef' => 'ext-1', 'cohortId' => 'c-1', 'title' => 'Bio', 'startsAt' => 'a', 'endsAt' => 'b', 'tenant_id' => 'tenant-a'];

		$saved = $this->invokePrivate($this->handler(), 'upsertSession', [$mapped, 'tenant-a']);

		self::assertNotNull($saved);
		self::assertSame('scheduled', $saved['lifecycle']);
		self::assertCount(1, $this->saves);
		self::assertSame('session', $this->saves[0]['schema']);

	}//end testUpsertSessionCreatesOnFirstImport()

	/**
	 * Re-importing the same occurrence (matching externalRef) updates the
	 * existing Session in place — no duplicate is created.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-re-importing-the-same-timetable-does-not-duplicate-sessions
	 */
	public function testUpsertSessionUpdatesInPlaceOnReimport(): void {
		$this->existingSessions = [
			['id' => 'session-1', 'externalRef' => 'ext-1', 'cohortId' => 'c-1', 'title' => 'Bio (old time)', 'startsAt' => 'a-old', 'endsAt' => 'b-old', 'lifecycle' => 'scheduled', 'tenant_id' => 'tenant-a'],
		];

		$mapped = ['externalRef' => 'ext-1', 'cohortId' => 'c-1', 'title' => 'Bio', 'startsAt' => 'a-new', 'endsAt' => 'b-new', 'tenant_id' => 'tenant-a'];

		$saved = $this->invokePrivate($this->handler(), 'upsertSession', [$mapped, 'tenant-a']);

		self::assertSame('session-1', $saved['id']);
		self::assertSame('a-new', $saved['startsAt']);
		self::assertCount(1, $this->saves);

	}//end testUpsertSessionUpdatesInPlaceOnReimport()

	/**
	 * A manually-created Session (no externalRef) is never matched by an import
	 * — a distinct import occurrence never touches it, even sharing the same cohort.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-a-manually-created-session-is-never-touched-by-an-import
	 */
	public function testManuallyCreatedSessionIsNeverTouchedByImport(): void {
		$this->existingSessions = [
			['id' => 'manual-session', 'externalRef' => null, 'cohortId' => 'c-1', 'title' => 'Manual lesson', 'startsAt' => 'manual-a', 'endsAt' => 'manual-b', 'lifecycle' => 'scheduled', 'tenant_id' => 'tenant-a'],
		];

		$mapped = ['externalRef' => 'ext-2', 'cohortId' => 'c-1', 'title' => 'Imported lesson', 'startsAt' => 'a', 'endsAt' => 'b', 'tenant_id' => 'tenant-a'];

		$this->invokePrivate($this->handler(), 'upsertSession', [$mapped, 'tenant-a']);

		self::assertCount(1, $this->saves);
		self::assertNotSame('manual-session', $this->saves[0]['object']['id'] ?? null);
		self::assertSame('scheduled', $this->saves[0]['object']['lifecycle']);

	}//end testManuallyCreatedSessionIsNeverTouchedByImport()

	/**
	 * handle() ignores a transition that does not reach `running`.
	 *
	 * @return void
	 */
	public function testHandleIgnoresNonRunningTransition(): void {
		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn('data-exchange-job');
		$event->method('getTo')->willReturn('queued');
		$event->expects(self::never())->method('getObject');

		$this->handler()->handle($event);

		self::assertCount(0, $this->saves);

	}//end testHandleIgnoresNonRunningTransition()

	/**
	 * handle() ignores a running job whose target is not timetable-import.
	 *
	 * @return void
	 */
	public function testHandleIgnoresOtherTargets(): void {
		$objectEntity = $this->createMock(ObjectEntity::class);
		$objectEntity->method('jsonSerialize')->willReturn(['id' => 'job-1', 'target' => 'bron-rod']);

		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn('data-exchange-job');
		$event->method('getTo')->willReturn('running');
		$event->method('getObject')->willReturn($objectEntity);

		$this->transitionEngine->expects(self::never())->method('transition');

		$this->handler()->handle($event);

		self::assertCount(0, $this->saves);

	}//end testHandleIgnoresOtherTargets()

	/**
	 * A running timetable-import job event for job-1.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function runningImportJob(): ObjectTransitionedEvent {
		$job = ['id' => 'job-1', 'target' => 'timetable-import', 'tenant_id' => 'tenant-a', 'mappingProfileId' => 'profile-z', 'scope' => ['schema' => 'session']];
		$this->rows['data-exchange-job'] = [$job];
		$this->rows['data-mapping-profile'] = [['id' => 'profile-z', 'name' => 'Zermelo timetable import', 'targetSchema' => 'Zermelo:Appointment']];

		$objectEntity = $this->createMock(ObjectEntity::class);
		$objectEntity->method('jsonSerialize')->willReturn($job);

		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn('data-exchange-job');
		$event->method('getTo')->willReturn('running');
		$event->method('getObject')->willReturn($objectEntity);

		return $event;

	}//end runningImportJob()

	/**
	 * With planninq as the source, the job is delivered through integriq: its
	 * outcome lands on the job, the conflict scan runs on planninq's lessons,
	 * and no Session is written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-a-timetable-import-job-delivers-into-planninq-when-planninq-is-the-source-req-003
	 */
	public function testPlanninqSourceDeliversAndWritesNoSession(): void {
		$planninqImport = $this->createMock(PlanninqTimetableImport::class);
		$planninqImport->method('applies')->willReturn(true);
		$planninqImport->expects(self::once())->method('deliver')->with(
			self::callback(static fn (array $job): bool => $job['id'] === 'job-1'),
			self::callback(static fn (?array $profile): bool => ($profile['targetSchema'] ?? '') === 'Zermelo:Appointment')
		)->willReturn(
			['state' => 'partial', 'fields' => ['finishedAt' => 'now', 'result' => ['recordsProcessed' => 3, 'recordsAccepted' => 2, 'recordsRejected' => 1]]]
		);
		$planninqImport->expects(self::once())->method('scanConflicts');
		$this->transitionEngine->expects(self::once())->method('transition')->with('job-1', 'partial');

		$this->handler($planninqImport)->handle($this->runningImportJob());

		self::assertSame([], array_filter($this->saves, static fn (array $save): bool => $save['schema'] === 'session'), 'no Session is written');
		$last = end($this->saves);
		self::assertSame('data-exchange-job', $last['schema']);
		self::assertSame(2, $last['object']['result']['recordsAccepted']);

	}//end testPlanninqSourceDeliversAndWritesNoSession()

	/**
	 * A delivery that cannot happen fails the job with the readable reason and scans nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-a-timetable-import-job-delivers-into-planninq-when-planninq-is-the-source-req-003
	 */
	public function testPlanninqDeliveryFailureFailsTheJob(): void {
		$planninqImport = $this->createMock(PlanninqTimetableImport::class);
		$planninqImport->method('applies')->willReturn(true);
		$planninqImport->method('deliver')->willThrowException(new \RuntimeException('Integriq is not installed, so the timetable cannot be delivered to planninq.'));
		$planninqImport->expects(self::never())->method('scanConflicts');
		$this->transitionEngine->expects(self::once())->method('transition')->with('job-1', 'fail');

		$this->handler($planninqImport)->handle($this->runningImportJob());

		$last = end($this->saves);
		self::assertStringContainsString('Integriq is not installed', $last['object']['errorMessage']);
		self::assertSame([], array_filter($this->saves, static fn (array $save): bool => $save['schema'] === 'session'));

	}//end testPlanninqDeliveryFailureFailsTheJob()
}//end class
