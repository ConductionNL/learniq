<?php

/**
 * Scholiq EnrolmentProgressRollupHandler unit tests.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Listener
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
 * @spec openspec/changes/learning-progress-and-analytics/specs/enrolment/spec.md#requirement-enrolment-carries-a-declared-lesson-progress-roll-up
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\BackgroundJob\DeferredObjectListenerJob;
use OCA\Scholiq\Listener\DeferredWorkGuard;
use OCA\Scholiq\Listener\EnrolmentProgressRollupHandler;
use OCA\Scholiq\Progress\EnrolmentProgressEvaluator;
use OCA\Scholiq\Service\ListenerSchemaResolver;
use OCA\Scholiq\Tests\Support\OrEntityFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EnrolmentProgressRollupHandler on ObjectCreatedEvent<LessonCompletion>.
 *
 * ADR-078: `handle()` now only queues, and the recompute runs in
 * {@see DeferredObjectListenerJob}. Every behavioural test therefore
 * handles the event AND drains the queue through the real job — draining is
 * what proves the work survived the move, and going through the job (rather
 * than calling `runDeferredWork()` directly) is what exercises the
 * re-entrancy guard production relies on.
 */
class EnrolmentProgressRollupHandlerTest extends TestCase {

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
	 * Recorder standing in for OpenRegister's ListenerDeferralService.
	 *
	 * @var RecordingDeferralService
	 */
	private RecordingDeferralService $deferral;

	/**
	 * LessonCompletion rows the deferred pass can re-read, keyed by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $completions = [];

	/**
	 * Fired after every recorded saveObject(), so a test can reproduce the
	 * event OpenRegister's mapper dispatches for that write.
	 *
	 * @var callable|null
	 */
	private $onSave = null;

	/**
	 * Reset fixtures before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->savedObjects = [];
		$this->completions = [];
		$this->onSave = null;
		$this->schemaResolver = $this->createMock(ListenerSchemaResolver::class);
		$this->deferral = new RecordingDeferralService();
		DeferredWorkGuard::reset();

	}//end setUp()

	/**
	 * Drop any guard claim a failing test may have leaked.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		DeferredWorkGuard::reset();
		parent::tearDown();

	}//end tearDown()

	/**
	 * Stub the resolver the way OpenRegister behaves in production: the entity
	 * carries numeric ids and the resolver turns them into slugs.
	 *
	 * @param string $schemaSlug The slug the resolver resolves the schema id to.
	 *
	 * @return void
	 */
	private function stubResolver(string $schemaSlug): void {
		$this->schemaResolver->method('registerSlug')->willReturn('scholiq');
		$this->schemaResolver->method('schemaSlug')->willReturn($schemaSlug);

	}//end stubResolver()

	/**
	 * Build a handler with mocked collaborators.
	 *
	 * @param array<int, array<string, mixed>> $enrolments Enrolment rows returned for the active-enrolment lookup.
	 * @param array{progressPercent: int, completedLessonCount: int, totalPublishedLessonCount: int} $evaluated Result the mocked evaluator returns.
	 *
	 * @return EnrolmentProgressRollupHandler
	 */
	private function makeHandler(array $enrolments, array $evaluated): EnrolmentProgressRollupHandler {
		$objectService = $this->createMock(ObjectService::class);

		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($enrolments) {
				if ($config['schema'] === 'enrolment') {
					return $enrolments;
				}

				return [];
			}
		);

		// The deferred pass re-reads the LessonCompletion by uuid; an unknown
		// uuid is a stale entry and must resolve to null, not to a fixture.
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null): ?ObjectEntity {
				$row = ($this->completions[(string)$id] ?? null);
				if ($row === null) {
					return null;
				}

				return OrEntityFactory::make($row, (string)$schema, (string)$register, (string)$id);
			}
		);

		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null): ObjectEntity {
				$this->savedObjects[] = [
					'register' => (string)$register,
					'schema' => (string)$schema,
					'object' => $object,
				];

				if ($this->onSave !== null) {
					($this->onSave)();
				}

				return OrEntityFactory::make($object, (string)$schema, (string)$register);
			}
		);

		$evaluator = $this->createMock(EnrolmentProgressEvaluator::class);
		$evaluator->method('evaluate')->willReturn($evaluated);

		return new EnrolmentProgressRollupHandler($objectService, $evaluator, $this->schemaResolver, $this->deferral);
	}//end makeHandler()

	/**
	 * Build a mocked ObjectCreatedEvent<LessonCompletion> and make its payload
	 * readable to the deferred pass.
	 *
	 * @param array<string, mixed> $data The LessonCompletion jsonSerialize() payload.
	 * @param string $uuid The LessonCompletion uuid.
	 *
	 * @return ObjectCreatedEvent
	 */
	private function makeEvent(array $data, string $uuid = 'completion-1'): ObjectCreatedEvent {
		$objectEntity = OrEntityFactory::make($data, '1280', '9', $uuid);
		$this->completions[$uuid] = $data;
		$this->stubResolver('lesson-completion');

		$event = $this->createMock(ObjectCreatedEvent::class);
		$event->method('getObject')->willReturn($objectEntity);

		return $event;
	}//end makeEvent()

	/**
	 * Handle an event and then run whatever it queued, through the real job.
	 *
	 * @param EnrolmentProgressRollupHandler $handler The handler under test.
	 * @param ObjectCreatedEvent $event The event to hand it.
	 *
	 * @return void
	 */
	private function handleAndDrain(EnrolmentProgressRollupHandler $handler, ObjectCreatedEvent $event): void {
		$handler->handle($event);
		DeferredJobDrain::run(test: $this, deferral: $this->deferral, listener: $handler);

	}//end handleAndDrain()

	/**
	 * A new LessonCompletion for a learner with an active Enrolment triggers
	 * a recompute and saves progressPercent onto that Enrolment.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learning-progress-and-analytics/specs/enrolment/spec.md#scenario-progress-percentage-recomputes-when-a-lesson-is-completed
	 */
	public function testNewCompletionTriggersRecompute(): void {
		$enrolment = ['id' => 'enrolment-1', 'learnerId' => 'learner-1', 'courseId' => 'course-1', 'lifecycle' => 'active'];

		$handler = $this->makeHandler(
			enrolments: [$enrolment],
			evaluated: ['progressPercent' => 40, 'completedLessonCount' => 4, 'totalPublishedLessonCount' => 10]
		);

		$this->handleAndDrain(
			$handler,
			$this->makeEvent(
				['learnerId' => 'learner-1', 'lessonId' => 'lesson-4', 'courseId' => 'course-1', 'source' => 'xapi']
			)
		);

		self::assertCount(1, $this->savedObjects);
		self::assertSame('enrolment', $this->savedObjects[0]['schema']);
		self::assertSame('enrolment-1', $this->savedObjects[0]['object']['id']);
		self::assertSame(40, $this->savedObjects[0]['object']['progressPercent']);

	}//end testNewCompletionTriggersRecompute()

	/**
	 * ADR-078: the create request itself writes nothing. The recompute exists
	 * only as a queued entry until the job runs it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-listener-work-placement/spec.md#requirement-deferred-post-event-work-runs-in-one-actor-forwarded-job
	 */
	public function testHandleQueuesTheWorkAndWritesNothing(): void {
		$handler = $this->makeHandler(
			enrolments: [['id' => 'enrolment-1', 'learnerId' => 'learner-1', 'courseId' => 'course-1', 'lifecycle' => 'active']],
			evaluated: ['progressPercent' => 40, 'completedLessonCount' => 4, 'totalPublishedLessonCount' => 10]
		);

		$handler->handle(
			$this->makeEvent(
				['learnerId' => 'learner-1', 'lessonId' => 'lesson-4', 'courseId' => 'course-1'],
				'completion-9'
			)
		);

		self::assertCount(0, $this->savedObjects, 'the write must not happen on the request path');
		self::assertSame([DeferredObjectListenerJob::class], $this->deferral->jobClasses);
		self::assertSame(
			[['handler' => EnrolmentProgressRollupHandler::HANDLER_KEY, 'uuid' => 'completion-9']],
			$this->deferral->entries
		);
		self::assertSame(
			[EnrolmentProgressRollupHandler::HANDLER_KEY . '|completion-9'],
			$this->deferral->dedupeKeys
		);

		// And the queued entry is what does the work.
		DeferredJobDrain::run(test: $this, deferral: $this->deferral, listener: $handler);
		self::assertCount(1, $this->savedObjects);

	}//end testHandleQueuesTheWorkAndWritesNothing()

	/**
	 * ADR-078 Rule 7: an entry whose LessonCompletion is gone by the time the
	 * job runs is a stale no-op, not an error — and specifically NOT a
	 * recompute driven by the dispatch-time payload.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-listener-work-placement/spec.md#requirement-deferred-work-reconciles-against-current-state
	 */
	public function testADeletedCompletionIsAStaleNoOp(): void {
		$handler = $this->makeHandler(
			enrolments: [['id' => 'enrolment-1', 'learnerId' => 'learner-1', 'courseId' => 'course-1', 'lifecycle' => 'active']],
			evaluated: ['progressPercent' => 40, 'completedLessonCount' => 4, 'totalPublishedLessonCount' => 10]
		);

		$handler->handle(
			$this->makeEvent(
				['learnerId' => 'learner-1', 'lessonId' => 'lesson-4', 'courseId' => 'course-1'],
				'completion-gone'
			)
		);

		// The row disappears between the write and the cron turn.
		unset($this->completions['completion-gone']);

		DeferredJobDrain::run(test: $this, deferral: $this->deferral, listener: $handler);

		self::assertCount(0, $this->savedObjects);

	}//end testADeletedCompletionIsAStaleNoOp()

	/**
	 * THE LOOP TEST. The Enrolment write the deferred pass makes is itself an
	 * object write, so OpenRegister's mapper dispatches for it and this
	 * listener sees the event again. Without the guard the listener would
	 * queue another entry, whose job would write again, for ever — and
	 * `cron.php` runs one job per web call, so that starves the whole queue.
	 *
	 * Removing the `DeferredWorkGuard::isRunning()` test from `handle()` makes
	 * the final assertion here fail (verified by reverting it).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-listener-work-placement/spec.md#requirement-a-listeners-own-write-must-not-re-queue-it
	 */
	public function testTheDeferredWriteDoesNotReQueueTheListener(): void {
		$enrolment = ['id' => 'enrolment-1', 'learnerId' => 'learner-1', 'courseId' => 'course-1', 'lifecycle' => 'active'];

		$handler = $this->makeHandler(
			enrolments: [$enrolment],
			evaluated: ['progressPercent' => 40, 'completedLessonCount' => 4, 'totalPublishedLessonCount' => 10]
		);

		$event = $this->makeEvent(
			['learnerId' => 'learner-1', 'lessonId' => 'lesson-4', 'courseId' => 'course-1'],
			'completion-loop'
		);

		// Every saveObject() re-dispatches this listener's event, exactly as
		// OpenRegister's mapper does for the object it just wrote.
		$this->onSave = function () use ($handler, $event): void {
			$handler->handle($event);
		};

		$handler->handle($event);
		self::assertCount(1, $this->deferral->entries);

		$passes = DeferredJobDrain::drain(test: $this, deferral: $this->deferral, listener: $handler, maxPasses: 4);

		self::assertSame(1, $passes, 'the deferred pass must run exactly once, not once per re-entry');
		self::assertCount(1, $this->savedObjects, 'the deferred pass still does its one write');
		self::assertSame([], $this->deferral->entries, 'the re-entrant dispatch must NOT queue another job');

	}//end testTheDeferredWriteDoesNotReQueueTheListener()

	/**
	 * A learner with no active Enrolment for the completion's course is
	 * skipped without error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learning-progress-and-analytics/specs/enrolment/spec.md#requirement-enrolment-carries-a-declared-lesson-progress-roll-up
	 */
	public function testNoActiveEnrolmentIsSkipped(): void {
		$handler = $this->makeHandler(
			enrolments: [],
			evaluated: ['progressPercent' => 0, 'completedLessonCount' => 0, 'totalPublishedLessonCount' => 0]
		);

		$this->handleAndDrain(
			$handler,
			$this->makeEvent(
				['learnerId' => 'learner-1', 'lessonId' => 'lesson-4', 'courseId' => 'course-1', 'source' => 'xapi']
			)
		);

		self::assertCount(0, $this->savedObjects);

	}//end testNoActiveEnrolmentIsSkipped()

	/**
	 * An event on a different schema is ignored entirely.
	 *
	 * @return void
	 */
	public function testUnrelatedSchemaIsIgnored(): void {
		$handler = $this->makeHandler(
			enrolments: [['id' => 'enrolment-1', 'learnerId' => 'learner-1', 'courseId' => 'course-1']],
			evaluated: ['progressPercent' => 50, 'completedLessonCount' => 5, 'totalPublishedLessonCount' => 10]
		);

		$objectEntity = OrEntityFactory::make(['id' => 'x'], '1281', '9');
		$this->stubResolver('xapi-statement');

		$event = $this->createMock(ObjectCreatedEvent::class);
		$event->method('getObject')->willReturn($objectEntity);

		$handler->handle($event);

		self::assertCount(0, $this->savedObjects);
		self::assertSame([], $this->deferral->entries, 'an unrelated schema must not even cost a job row');

	}//end testUnrelatedSchemaIsIgnored()
}//end class
