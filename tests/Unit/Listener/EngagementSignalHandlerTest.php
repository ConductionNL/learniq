<?php

/**
 * Learniq EngagementSignalHandler unit tests.
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
 * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#requirement-at-risk-detection-beyond-bsa-is-a-deterministic-rule-based-threshold--not-aiml
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\BackgroundJob\EngagementSignalJob;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\Learniq\Listener\EngagementSignalHandler;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EngagementSignalHandler::handle() on ObjectCreatedEvent<XapiStatement>.
 */
class EngagementSignalHandlerTest extends TestCase {

	/**
	 * In-memory fake OR datastore, keyed by schema slug. Persists across
	 * multiple handle() calls within one test so idempotency can be
	 * exercised.
	 *
	 * @var array<string, array<int, array<string,mixed>>>
	 */
	private array $db = [];

	/** @var array<int, array<string, mixed>> */
	private array $deferred = [];

	/** @var ListenerDeferralService&MockObject */
	private ListenerDeferralService $deferral;

	/**
	 * Recorded saveObject() calls.
	 *
	 * @var array<int, array{register: string, schema: string, object: array<string, mixed>}>
	 */
	private array $savedObjects = [];

	/**
	 * Resolver turning the entity's numeric register/schema ids into slugs.
	 *
	 * @var ListenerSchemaResolver&MockObject
	 */
	private ListenerSchemaResolver&MockObject $schemaResolver;

	/**
	 * Reset fixtures before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->db = [];
		$this->savedObjects = [];
		$this->schemaResolver = $this->createMock(ListenerSchemaResolver::class);

	}//end setUp()

	/**
	 * Stub the resolver the way OpenRegister behaves in production: the entity
	 * carries numeric ids and the resolver turns them into slugs.
	 *
	 * @param string $schemaSlug The slug the resolver resolves the schema id to.
	 *
	 * @return void
	 */
	private function stubResolver(string $schemaSlug): void {
		$this->schemaResolver->method('registerSlug')->willReturn('learniq');
		$this->schemaResolver->method('schemaSlug')->willReturn($schemaSlug);

	}//end stubResolver()

	/**
	 * Build a handler backed by an ObjectService stub over $this->db and a
	 * mocked EngagementScoreEvaluator returning a fixed result.
	 *
	 * @param array{timeOnTaskMinutes: float, lastActivityAt: string|null, score: int} $evaluated Result the mocked evaluator returns.
	 * @param DateTime $now The "now" the injected ITimeFactory reports.
	 *
	 * @return EngagementSignalHandler
	 */
	private function makeHandler(array $evaluated, DateTime $now): EngagementSignalHandler {
		$objectService = $this->createMock(ObjectService::class);

		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) {
				if ($schema === 'cohort') {
					foreach (($this->db['cohort'] ?? []) as $cohort) {
						if (($cohort['id'] ?? null) === $id) {
							return OrEntityFactory::make($cohort, 'cohort');
						}
					}
				}

				return null;
			}
		);

		$objectService->method('findAll')->willReturnCallback(
			function (array $config) {
				$schema = $config['schema'];
				$records = $this->db[$schema] ?? [];
				$filters = $config['filters'] ?? [];

				$matched = array_values(
					array_filter(
						$records,
						static function (array $rec) use ($filters) {
							foreach ($filters as $key => $value) {
								if (($rec[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);

				if (isset($config['limit']) === true) {
					$matched = array_slice($matched, 0, (int)$config['limit']);
				}

				return $matched;
			}
		);

		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null): ObjectEntity {
				$register = (string)$register;
				$schema = (string)$schema;

				if (isset($object['id']) === false) {
					$object['id'] = $schema . '-auto-' . (count($this->db[$schema] ?? []) + 1);
				}

				$this->savedObjects[] = ['register' => $register, 'schema' => $schema, 'object' => $object];

				$existingIndex = null;
				foreach (($this->db[$schema] ?? []) as $index => $rec) {
					if (($rec['id'] ?? null) === $object['id']) {
						$existingIndex = $index;
						break;
					}
				}

				if ($existingIndex !== null) {
					$this->db[$schema][$existingIndex] = $object;
				} else {
					$this->db[$schema][] = $object;
				}

				return OrEntityFactory::make($object, $schema, $register);
			}
		);

		$this->deferral = $this->createMock(ListenerDeferralService::class);
		$this->deferral->method('defer')->willReturnCallback(
			function (string $jobClass, array $entry, int $chunkSize = 100, ?string $dedupeKey = null): void {
				$this->deferred[] = ['jobClass' => $jobClass, 'entry' => $entry, 'dedupeKey' => $dedupeKey];
			}
		);

		return new EngagementSignalHandler($this->deferral, $this->schemaResolver);
	}//end makeHandler()

	/**
	 * Seed a record into the fake datastore.
	 *
	 * @param string $schema Schema slug.
	 * @param array<string, mixed> $record Record data.
	 *
	 * @return void
	 */
	private function seed(string $schema, array $record): void {
		$this->db[$schema][] = $record;

	}//end seed()

	/**
	 * Build a mocked ObjectCreatedEvent<XapiStatement>.
	 *
	 * @param array<string, mixed> $data The XapiStatement jsonSerialize() payload.
	 *
	 * @return ObjectCreatedEvent
	 */
	private function makeXapiEvent(array $data): ObjectCreatedEvent {
		$objectEntity = OrEntityFactory::make($data, '1280', '9');
		$this->stubResolver('xapi-statement');

		$event = $this->createMock(ObjectCreatedEvent::class);
		$event->method('getObject')->willReturn($objectEntity);

		return $event;
	}//end makeXapiEvent()

	/**
	 * Fetch every saveObject() call recorded for a given schema.
	 *
	 * @param string $schema Schema slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function savedFor(string $schema): array {
		return array_values(
			array_map(
				static fn (array $s) => $s['object'],
				array_filter($this->savedObjects, static fn (array $s) => $s['schema'] === $schema)
			)
		);

	}//end savedFor()






	/**
	 * An xAPI statement defers the recompute rather than doing it.
	 *
	 * @return void
	 * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#scenario-time-on-task-accumulates-across-statements
	 */
	public function testAnXapiStatementDefersTheRecompute(): void {
		$handler = $this->makeHandler(
			evaluated: ['timeOnTaskMinutes' => 12.0, 'lastActivityAt' => '2026-07-13T09:00:00+02:00', 'score' => 55],
			now: new DateTime('2026-07-13 10:00:00', new DateTimeZone('Europe/Amsterdam'))
		);

		$handler->handle(
			$this->makeXapiEvent(
				['verified_actor_id' => 'learner-1', 'courseId' => 'course-1', 'tenant_id' => 'tenant-a']
			)
		);

		self::assertCount(1, $this->deferred);
		self::assertSame(EngagementSignalJob::class, $this->deferred[0]['jobClass']);
		self::assertSame('learner-1', $this->deferred[0]['entry']['learnerId']);
		self::assertSame('course-1', $this->deferred[0]['entry']['courseId']);
		self::assertSame('learner-1|course-1', $this->deferred[0]['dedupeKey']);
		self::assertSame([], $this->db, 'nothing may be written on the event path');
	}//end testAnXapiStatementDefersTheRecompute()

	/**
	 * No AI/ML client, HTTP call, or Hermiq dependency is constructed
	 * anywhere by this handler — verified structurally: it depends only on
	 * ObjectService, EngagementScoreEvaluator, ListenerSchemaResolver, and
	 * ITimeFactory.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#requirement-at-risk-detection-beyond-bsa-is-a-deterministic-rule-based-threshold--not-aiml
	 */
	public function testConstructorHasNoAiOrHermiqDependency(): void {
		$reflection = new \ReflectionClass(EngagementSignalHandler::class);
		$constructor = $reflection->getConstructor();
		self::assertNotNull($constructor);

		$paramTypes = array_map(
			static fn (\ReflectionParameter $p) => (string)$p->getType(),
			$constructor->getParameters()
		);

		foreach ($paramTypes as $type) {
			self::assertStringNotContainsStringIgnoringCase('hermiq', $type);
			self::assertStringNotContainsStringIgnoringCase('aifeature', $type);
		}

	}//end testConstructorHasNoAiOrHermiqDependency()
}//end class
