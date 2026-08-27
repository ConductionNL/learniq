<?php

/**
 * Learniq LearnerEngagementRollupHandler unit tests.
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
 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#requirement-learner-totals-level-and-streak-are-computed-by-a-php-evaluator-not-a-sum-aggregation
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\BackgroundJob\LearnerEngagementRollupJob;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\Learniq\Listener\LearnerEngagementRollupHandler;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LearnerEngagementRollupHandler::handle().
 */
class LearnerEngagementRollupHandlerTest extends TestCase {

	/**
	 * Recorded saveObject() calls.
	 *
	 * @var array<int, array{register: string, schema: string, object: array<string, mixed>}>
	 */
	private array $savedObjects = [];

	/** @var array<int, array<string, mixed>> */
	private array $deferred = [];

	/** @var ListenerDeferralService&MockObject */
	private ListenerDeferralService $deferral;

	/**
	 * Existing LearnerEngagement row to return from findAll(), or null.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $existingEngagement = null;

	/**
	 * Active streak-milestone PointRule rows to return from findAll().
	 *
	 * @var array<int, array>
	 */
	private array $streakRules = [];

	/**
	 * Evaluator result to return.
	 *
	 * @var array<string,mixed>
	 */
	private array $evaluatorResult = [
		'totalPoints' => 0.0,
		'levelId' => null,
		'currentStreakDays' => 0,
		'longestStreakDays' => 0,
		'lastActivityDate' => null,
	];

	/**
	 * Resolver turning the entity's numeric register/schema ids into slugs.
	 *
	 * @var ListenerSchemaResolver&MockObject
	 */
	private ListenerSchemaResolver&MockObject $schemaResolver;

	/**
	 * Reset the capture buffers before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->schemaResolver = $this->createMock(ListenerSchemaResolver::class);
		$this->savedObjects = [];
		$this->existingEngagement = null;
		$this->streakRules = [];
		$this->evaluatorResult = [
			'totalPoints' => 0.0,
			'levelId' => null,
			'currentStreakDays' => 0,
			'longestStreakDays' => 0,
			'lastActivityDate' => null,
		];

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
	 * Build a handler with mocked collaborators.
	 *
	 * @param DateTime $now The "now" the injected ITimeFactory reports.
	 *
	 * @return LearnerEngagementRollupHandler
	 */
	private function makeHandler(DateTime $now): LearnerEngagementRollupHandler {
		$objectService = $this->createMock(ObjectService::class);

		$objectService->method('findAll')->willReturnCallback(
			function (array $config) {
				if ($config['schema'] === 'learner-engagement') {
					return $this->existingEngagement === null ? [] : [$this->existingEngagement];
				}

				if ($config['schema'] === 'point-rule') {
					return $this->streakRules;
				}

				return [];
			}
		);

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

		$this->deferral = $this->createMock(ListenerDeferralService::class);
		$this->deferral->method('defer')->willReturnCallback(
			function (string $jobClass, array $entry, int $chunkSize = 100, ?string $dedupeKey = null): void {
				$this->deferred[] = ['jobClass' => $jobClass, 'entry' => $entry, 'dedupeKey' => $dedupeKey];
			}
		);

		return new LearnerEngagementRollupHandler($this->deferral, $this->schemaResolver);
	}//end makeHandler()

	/**
	 * Build a mocked ObjectCreatedEvent for a PointAward.
	 *
	 * @param array<string, mixed> $data The PointAward's jsonSerialize() payload.
	 *
	 * @return ObjectCreatedEvent
	 */
	private function makeEvent(array $data): ObjectCreatedEvent {
		$objectEntity = OrEntityFactory::make($data, '1280', '9');
		$this->stubResolver('point-award');

		$event = $this->createMock(ObjectCreatedEvent::class);
		$event->method('getObject')->willReturn($objectEntity);

		return $event;
	}//end makeEvent()

	/**
	 * Filter savedObjects to those matching a schema.
	 *
	 * @param string $schema Schema slug.
	 *
	 * @return array<int, array>
	 */
	private function savesFor(string $schema): array {
		return array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === $schema));
	}//end savesFor()





	/**
	 * A PointAward defers the roll-up with the learner, tenant and kind.
	 *
	 * The listener's whole job now is to decide a roll-up is owed and say for
	 * whom — the recompute, the write and the milestone award happen in
	 * LearnerEngagementRollupJob, out of the PointAward write.
	 *
	 * @return void
	 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-new-pointaward-recomputes-totals-and-level
	 */
	public function testAPointAwardDefersTheRollup(): void {
		$handler = $this->makeHandler(new DateTime('2026-05-01 12:00:00', new DateTimeZone('UTC')));

		$handler->handle(
			$this->makeEvent(['learnerId' => 'learner-1', 'tenant_id' => 'tenant-a', 'sourceKind' => 'lesson'])
		);

		self::assertCount(1, $this->deferred);
		self::assertSame(LearnerEngagementRollupJob::class, $this->deferred[0]['jobClass']);
		self::assertSame('learner-1', $this->deferred[0]['entry']['learnerId']);
		self::assertSame('tenant-a', $this->deferred[0]['entry']['tenantId']);
		self::assertSame('lesson', $this->deferred[0]['entry']['sourceKind']);
		self::assertSame([], $this->savedObjects, 'nothing may be written on the event path');
	}//end testAPointAwardDefersTheRollup()

	/**
	 * The dedupe key carries `sourceKind`, and that is load-bearing.
	 *
	 * A milestone bonus award carries a recursion guard: its own roll-up must
	 * NOT re-check milestones. If the dedupe key were learner+tenant alone, a
	 * milestone award arriving first in a request would swallow an ordinary
	 * award arriving second — and the ordinary award's milestone check, which
	 * the guard does not apply to, would be silently dropped with it.
	 *
	 * @return void
	 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-new-pointaward-recomputes-totals-and-level
	 */
	public function testTheDedupeKeyDistinguishesAMilestoneAwardFromAnOrdinaryOne(): void {
		$handler = $this->makeHandler(new DateTime('2026-05-01 12:00:00', new DateTimeZone('UTC')));

		$handler->handle($this->makeEvent(['learnerId' => 'l1', 'tenant_id' => 't1', 'sourceKind' => 'streak-milestone']));
		$handler->handle($this->makeEvent(['learnerId' => 'l1', 'tenant_id' => 't1', 'sourceKind' => 'lesson']));

		self::assertNotSame(
			$this->deferred[0]['dedupeKey'],
			$this->deferred[1]['dedupeKey'],
			'a milestone award must not coalesce with an ordinary one'
		);
		self::assertSame('l1|t1|streak-milestone', $this->deferred[0]['dedupeKey']);
		self::assertSame('l1|t1|lesson', $this->deferred[1]['dedupeKey']);
	}//end testTheDedupeKeyDistinguishesAMilestoneAwardFromAnOrdinaryOne()

	/**
	 * An ObjectCreatedEvent on a different schema is ignored entirely.
	 *
	 * @return void
	 */
	/**
	 * An event of another type is ignored before getObject() is reached.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/engagement/spec.md#scenario-a-new-pointaward-recomputes-totals-and-level
	 */
	public function testAnEventOfAnotherTypeIsIgnored(): void {
		$handler = $this->makeHandler(now: new DateTime('2026-07-15 10:00:00', new DateTimeZone('Europe/Amsterdam')));

		$handler->handle(new \OCP\EventDispatcher\GenericEvent());

		self::assertCount(0, $this->deferred);

	}//end testAnEventOfAnotherTypeIsIgnored()

	/**
	 * A PointAward with no learnerId enqueues nothing — the dedupe key and
	 * the roll-up itself are both keyed on it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/engagement/spec.md#scenario-a-new-pointaward-recomputes-totals-and-level
	 */
	public function testAnAwardWithNoLearnerEnqueuesNothing(): void {
		$handler = $this->makeHandler(now: new DateTime('2026-07-15 10:00:00', new DateTimeZone('Europe/Amsterdam')));

		$handler->handle($this->makeEvent(['learnerId' => '', 'tenant_id' => 'tenant-a', 'sourceKind' => 'enrolment']));

		self::assertCount(0, $this->deferred);

	}//end testAnAwardWithNoLearnerEnqueuesNothing()

	public function testUnrelatedSchemaIsIgnored(): void {
		$now = new DateTime('2026-07-15 10:00:00', new DateTimeZone('Europe/Amsterdam'));

		$objectEntity = OrEntityFactory::make(['id' => 'x'], '1281', '9');
		$this->stubResolver('enrolment');

		$event = $this->createMock(ObjectCreatedEvent::class);
		$event->method('getObject')->willReturn($objectEntity);

		$handler = $this->makeHandler(now: $now);
		$handler->handle($event);

		self::assertCount(0, $this->savedObjects);

	}//end testUnrelatedSchemaIsIgnored()
}//end class
