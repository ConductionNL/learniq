<?php

/**
 * Learniq SchoolAdviesSendToRodHandler unit tests.
 *
 * Mirrors SupportRequestSubmitHandlerTest's own structure: sending a
 * SchoolAdvies to ROD auto-queues a DataExchangeJob (target: bron-rod,
 * scope.schema: school-advies) and stamps the job id back onto the
 * SchoolAdvies.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
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
 * @spec openspec/changes/po-schooladvies-flow/specs/enrolment/spec.md#requirement-sending-a-definitief-schooladvies-to-rod-auto-queues-the-existing-bron-rod-dataexchangejob
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Listener\SchoolAdviesSendToRodHandler;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for SchoolAdviesSendToRodHandler::handle() on SchoolAdvies -> verzonden-naar-rod.
 */
class SchoolAdviesSendToRodHandlerTest extends TestCase {

	/**
	 * Recorded saveObject() calls.
	 *
	 * @var array<int, array{register: string, schema: string, object: array<string, mixed>}>
	 */
	private array $savedObjects = [];

	/**
	 * Reset the capture buffer before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->savedObjects = [];

	}//end setUp()

	/**
	 * Build a handler with a stubbed ObjectService.
	 *
	 * @param string|null $savedJobId UUID to return for the DataExchangeJob save, or null to
	 *                                simulate a save that yields no id.
	 * @param array<string,mixed>|null $existingSchoolAdvies The SchoolAdvies row findAll() returns when the
	 *                                                       handler looks it up to stamp dataExchangeJobId onto.
	 *
	 * @return SchoolAdviesSendToRodHandler
	 */
	private function makeHandler(
		?string $savedJobId,
		?array $existingSchoolAdvies = null,
	): SchoolAdviesSendToRodHandler {
		$objectService = $this->createMock(ObjectService::class);

		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($existingSchoolAdvies): array {
				$schema = $config['schema'] ?? '';

				if ($schema === 'school-advies') {
					return $existingSchoolAdvies === null ? [] : [$existingSchoolAdvies];
				}

				return [];
			}
		);

		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null) use ($savedJobId): ObjectEntity {
				$schema = (string)$schema;
				$data = ($object instanceof ObjectEntity) ? $object->jsonSerialize() : $object;
				$this->savedObjects[] = [
					'register' => (string)$register,
					'schema' => $schema,
					'object' => $data,
				];

				if ($schema === 'data-exchange-job') {
					if ($savedJobId === null) {
						$idless = $data;
						unset($idless['id'], $idless['uuid']);
						return OrEntityFactory::make($idless, $schema, (string)$register);
					}

					return OrEntityFactory::make(array_merge($data, ['id' => $savedJobId]), $schema, (string)$register);
				}

				return OrEntityFactory::make($data, $schema, (string)$register);
			}
		);

		return new SchoolAdviesSendToRodHandler($objectService, new NullLogger());
	}//end makeHandler()

	/**
	 * Build a mocked ObjectTransitionedEvent for a SchoolAdvies -> verzonden-naar-rod transition.
	 *
	 * @param array<string,mixed> $adviesData The SchoolAdvies' jsonSerialize() payload.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function makeEvent(array $adviesData): ObjectTransitionedEvent {
		$objectEntity = $this->createMock(ObjectEntity::class);
		$objectEntity->method('jsonSerialize')->willReturn($adviesData);

		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn($objectEntity);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn('school-advies');
		$event->method('getAction')->willReturn('verzendenNaarRod');
		$event->method('getTo')->willReturn('verzonden-naar-rod');

		return $event;
	}//end makeEvent()

	/**
	 * Sending a definitief advies creates a bron-rod DataExchangeJob and
	 * stamps its id back onto the SchoolAdvies.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/po-schooladvies-flow/specs/enrolment/spec.md#scenario-sending-a-definitief-advies-creates-and-links-a-bron-rod-dataexchangejob
	 */
	public function testSendToRodCreatesAndLinksJob(): void {
		$adviesData = [
			'id' => 'advies-1',
			'learnerId' => 'learner-007',
			'tenant_id' => 'tenant-a',
			'definitiefAdviesLevel' => 'havo',
			'lifecycle' => 'verzonden-naar-rod',
		];

		$handler = $this->makeHandler(savedJobId: 'job-uuid-1', existingSchoolAdvies: $adviesData);
		$handler->handle($this->makeEvent($adviesData));

		$jobSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'data-exchange-job'));
		self::assertCount(1, $jobSaves);
		self::assertSame('bron-rod', $jobSaves[0]['object']['target']);
		self::assertSame('export', $jobSaves[0]['object']['direction']);
		self::assertSame('school-advies', $jobSaves[0]['object']['scope']['schema']);
		self::assertSame('learner-007', $jobSaves[0]['object']['scope']['filters']['learnerId']);
		self::assertSame('advies-1', $jobSaves[0]['object']['scope']['filters']['schoolAdviesId']);
		self::assertSame('queued', $jobSaves[0]['object']['lifecycle']);

		$adviesSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'school-advies'));
		self::assertCount(1, $adviesSaves);
		self::assertSame('job-uuid-1', $adviesSaves[0]['object']['dataExchangeJobId']);

	}//end testSendToRodCreatesAndLinksJob()

	/**
	 * A transition to a different state (e.g. vaststellenDefinitief) is ignored.
	 *
	 * @return void
	 */
	public function testIgnoresTransitionsNotTargetingVerzondenNaarRod(): void {
		$adviesData = ['id' => 'advies-2', 'learnerId' => 'learner-008', 'tenant_id' => 'tenant-a'];

		$objectEntity = $this->createMock(ObjectEntity::class);
		$objectEntity->method('jsonSerialize')->willReturn($adviesData);

		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn($objectEntity);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn('school-advies');
		$event->method('getAction')->willReturn('vaststellenDefinitief');
		$event->method('getTo')->willReturn('definitief');

		$handler = $this->makeHandler(savedJobId: 'job-uuid-2');
		$handler->handle($event);

		self::assertCount(0, $this->savedObjects);

	}//end testIgnoresTransitionsNotTargetingVerzondenNaarRod()
}//end class
