<?php

/**
 * Learniq LessonProgressHandler unit tests.
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
 * @spec openspec/changes/learning-progress-and-analytics/specs/progress-tracking/spec.md#requirement-xapi-completion-statements-are-wired-into-per-lesson-completion-not-duplicated
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\BackgroundJob\XapiStatementFollowUpJob;
use OCA\Learniq\Listener\LessonProgressHandler;
use OCA\Learniq\Service\LessonProgress;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for LessonProgressHandler::handle() on ObjectCreatedEvent<XapiStatement>.
 */
class LessonProgressHandlerTest extends TestCase {

	/**
	 * In-memory fake OR datastore, keyed by schema slug.
	 *
	 * @var array<string, array<int, array<string,mixed>>>
	 */
	private array $db = [];

	/**
	 * Recorded saveObject() calls.
	 *
	 * @var array<int, array{register: string, schema: string, object: array<string, mixed>}>
	 */
	private array $savedObjects = [];

	/**
	 * Entries the handler queued, with the job class and dedupe key.
	 *
	 * @var array<int, array{jobClass: string, entry: array<string, mixed>, dedupeKey: string|null}>
	 */
	private array $queued = [];

	/**
	 * Whether the deferral fake runs each queued entry straight away.
	 *
	 * @var bool
	 */
	private bool $runQueued = true;

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
		$this->queued = [];
		$this->runQueued = true;
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
	 * Build a handler backed by an ObjectService stub over $this->db.
	 *
	 * @param DateTime $now The "now" the injected ITimeFactory reports.
	 *
	 * @return LessonProgressHandler
	 */
	private function makeHandler(DateTime $now): LessonProgressHandler {
		$objectService = $this->createMock(ObjectService::class);

		$objectService->method('findAll')->willReturnCallback(
			function (array $config) {
				$schema = $config['filters']['schema'];
				$records = $this->db[$schema] ?? [];
				$filters = array_diff_key(($config['filters'] ?? []), ['register' => true, 'schema' => true]);

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
				$schema = (string)$schema;
				$object = ($object instanceof ObjectEntity) ? $object->jsonSerialize() : $object;

				if (isset($object['id']) === false) {
					$object['id'] = $schema . '-auto-' . (count($this->db[$schema] ?? []) + 1);
				}

				$this->savedObjects[] = [
					'register' => (string)$register,
					'schema' => $schema,
					'object' => $object,
				];

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

				return OrEntityFactory::make($object, $schema, (string)$register);
			}
		);

		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturn($now);

		$progress = new LessonProgress($objectService, $this->createMock(LoggerInterface::class));
		$test = $this;
		$deferral = new class ($test, $progress) extends ListenerDeferralService {
			/**
			 * Constructor.
			 *
			 * @param LessonProgressHandlerTest $test     The test, to record entries.
			 * @param LessonProgress            $progress The work the job would run.
			 */
			public function __construct(
				private readonly LessonProgressHandlerTest $test,
				private readonly LessonProgress $progress,
			) {
			}//end __construct()

			/**
			 * Record the entry, then run it as the job would.
			 *
			 * @param string               $jobClass  The job class.
			 * @param array<string, mixed> $entry     The entry.
			 * @param int                  $chunkSize Unused.
			 * @param string|null          $dedupeKey The dedupe key.
			 *
			 * @return void
			 */
			public function defer(string $jobClass, array $entry, int $chunkSize = self::DEFAULT_CHUNK_SIZE, ?string $dedupeKey = null): void {
				if ($this->test->recordQueued(jobClass: $jobClass, entry: $entry, dedupeKey: $dedupeKey) === true) {
					$this->progress->record(statement: $entry['statement'], completedAt: $entry['completedAt']);
				}
			}//end defer()
		};

		return new LessonProgressHandler($deferral, $this->schemaResolver, $timeFactory);

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
	 * Fetch every saveObject() call recorded for lesson-completion.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function savedCompletions(): array {
		return array_values(
			array_map(
				static fn (array $s) => $s['object'],
				array_filter($this->savedObjects, static fn (array $s) => $s['schema'] === 'lesson-completion')
			)
		);

	}//end savedCompletions()

	/**
	 * A non-mandatory, non-last lesson's completion statement creates a
	 * LessonCompletion — a case XapiCompletionHandler itself ignores.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learning-progress-and-analytics/specs/progress-tracking/spec.md#scenario-a-non-final-non-mandatory-lessons-completion-statement-is-recorded
	 */
	public function testNonMandatoryNonLastLessonCreatesCompletion(): void {
		$now = new DateTime('2026-07-13 10:00:00', new DateTimeZone('Europe/Amsterdam'));

		$this->seed(
			'lesson',
			[
				'id' => 'lesson-3',
				'courseId' => 'course-1',
				'order' => 3,
				'mandatoryTraining' => false,
				'lifecycle' => 'published',
				'xapiObjectId' => 'https://learniq.test/lessons/lesson-3',
				'tenant_id' => 'tenant-a',
			]
		);

		$handler = $this->makeHandler(now: $now);

		$event = $this->makeXapiEvent(
			[
				'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/completed'],
				'object' => ['id' => 'https://learniq.test/lessons/lesson-3'],
				'verified_actor_id' => 'learner-1',
				'tenant_id' => 'tenant-a',
			]
		);

		$handler->handle($event);

		$saved = $this->savedCompletions();
		self::assertCount(1, $saved);
		self::assertSame('learner-1', $saved[0]['learnerId']);
		self::assertSame('lesson-3', $saved[0]['lessonId']);
		self::assertSame('course-1', $saved[0]['courseId']);
		self::assertSame('xapi', $saved[0]['source']);
		self::assertSame('http://adlnet.gov/expapi/verbs/completed', $saved[0]['verb']);

	}//end testNonMandatoryNonLastLessonCreatesCompletion()

	/**
	 * A duplicate completion statement for the same learner+lesson updates
	 * the existing row rather than duplicating it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learning-progress-and-analytics/specs/progress-tracking/spec.md#scenario-a-duplicate-completion-statement-for-the-same-lesson-updates-not-duplicates
	 */
	public function testDuplicateStatementUpdatesNotDuplicates(): void {
		$now = new DateTime('2026-07-13 10:00:00', new DateTimeZone('Europe/Amsterdam'));

		$this->seed(
			'lesson',
			[
				'id' => 'lesson-3',
				'courseId' => 'course-1',
				'lifecycle' => 'published',
				'xapiObjectId' => 'https://learniq.test/lessons/lesson-3',
				'tenant_id' => 'tenant-a',
			]
		);
		$this->seed(
			'lesson-completion',
			[
				'id' => 'completion-1',
				'learnerId' => 'learner-1',
				'lessonId' => 'lesson-3',
				'courseId' => 'course-1',
				'source' => 'xapi',
				'completedAt' => '2026-07-01T09:00:00+02:00',
			]
		);

		$handler = $this->makeHandler(now: $now);

		$event = $this->makeXapiEvent(
			[
				'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/passed'],
				'object' => ['id' => 'https://learniq.test/lessons/lesson-3'],
				'verified_actor_id' => 'learner-1',
				'tenant_id' => 'tenant-a',
			]
		);

		$handler->handle($event);

		// Still exactly one row in the datastore — updated, not duplicated.
		self::assertCount(1, $this->db['lesson-completion']);
		self::assertSame('completion-1', $this->db['lesson-completion'][0]['id']);
		self::assertSame('http://adlnet.gov/expapi/verbs/passed', $this->db['lesson-completion'][0]['verb']);
		self::assertNotSame('2026-07-01T09:00:00+02:00', $this->db['lesson-completion'][0]['completedAt']);

	}//end testDuplicateStatementUpdatesNotDuplicates()

	/**
	 * A retake does not reuse the completion of the earlier enrolment: the
	 * statement in the new enrolment adds a row for that enrolment and leaves
	 * the old row, and its enrolmentId, as they were (learniq#945).
	 *
	 * @return void
	 */
	public function testARetakeAddsACompletionForTheNewEnrolmentAndKeepsTheOldRow(): void {
		$now = new DateTime('2027-07-13 10:00:00', new DateTimeZone('Europe/Amsterdam'));

		$this->seed(
			'lesson',
			[
				'id' => 'lesson-3',
				'courseId' => 'course-1',
				'lifecycle' => 'published',
				'xapiObjectId' => 'https://learniq.test/lessons/lesson-3',
				'tenant_id' => 'tenant-a',
			]
		);
		$this->seed('enrolment', ['id' => 'enrol-1', 'learnerId' => 'learner-1', 'courseId' => 'course-1', 'lifecycle' => 'completed', 'tenant_id' => 'tenant-a']);
		$this->seed('enrolment', ['id' => 'enrol-2', 'learnerId' => 'learner-1', 'courseId' => 'course-1', 'lifecycle' => 'active', 'tenant_id' => 'tenant-a']);
		$this->seed(
			'lesson-completion',
			[
				'id' => 'completion-1',
				'learnerId' => 'learner-1',
				'lessonId' => 'lesson-3',
				'courseId' => 'course-1',
				'enrolmentId' => 'enrol-1',
				'source' => 'xapi',
				'completedAt' => '2026-07-01T09:00:00+02:00',
			]
		);

		$handler = $this->makeHandler(now: $now);
		$handler->handle(
			$this->makeXapiEvent(
				[
					'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/completed'],
					'object' => ['id' => 'https://learniq.test/lessons/lesson-3'],
					'verified_actor_id' => 'learner-1',
					'tenant_id' => 'tenant-a',
				]
			)
		);

		self::assertCount(2, $this->db['lesson-completion']);
		self::assertSame('enrol-1', $this->db['lesson-completion'][0]['enrolmentId']);
		self::assertSame('2026-07-01T09:00:00+02:00', $this->db['lesson-completion'][0]['completedAt']);
		self::assertSame('enrol-2', $this->db['lesson-completion'][1]['enrolmentId']);
		self::assertNotSame('completion-1', $this->db['lesson-completion'][1]['id']);

	}//end testARetakeAddsACompletionForTheNewEnrolmentAndKeepsTheOldRow()

	/**
	 * A completion while the new enrolment is still pending belongs to it.
	 *
	 * @return void
	 */
	public function testAPendingEnrolmentIsUsedWhenNoneIsActive(): void {
		$now = new DateTime('2027-07-13 10:00:00', new DateTimeZone('Europe/Amsterdam'));

		$this->seed('lesson', ['id' => 'lesson-3', 'courseId' => 'course-1', 'lifecycle' => 'published', 'xapiObjectId' => 'https://learniq.test/lessons/lesson-3', 'tenant_id' => 'tenant-a']);
		$this->seed('enrolment', ['id' => 'enrol-2', 'learnerId' => 'learner-1', 'courseId' => 'course-1', 'lifecycle' => 'pending', 'tenant_id' => 'tenant-a']);

		$handler = $this->makeHandler(now: $now);
		$handler->handle(
			$this->makeXapiEvent(
				[
					'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/completed'],
					'object' => ['id' => 'https://learniq.test/lessons/lesson-3'],
					'verified_actor_id' => 'learner-1',
					'tenant_id' => 'tenant-a',
				]
			)
		);

		self::assertSame('enrol-2', $this->savedCompletions()[0]['enrolmentId']);

	}//end testAPendingEnrolmentIsUsedWhenNoneIsActive()

	/**
	 * A statement with no resolvable Lesson is skipped without error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learning-progress-and-analytics/specs/progress-tracking/spec.md#requirement-xapi-completion-statements-are-wired-into-per-lesson-completion-not-duplicated
	 */
	public function testUnresolvableLessonIsSkippedWithoutError(): void {
		$now = new DateTime('2026-07-13 10:00:00', new DateTimeZone('Europe/Amsterdam'));
		$handler = $this->makeHandler(now: $now);

		$event = $this->makeXapiEvent(
			[
				'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/completed'],
				'object' => ['id' => 'https://learniq.test/lessons/does-not-exist'],
				'verified_actor_id' => 'learner-1',
				'tenant_id' => 'tenant-a',
			]
		);

		$handler->handle($event);

		self::assertCount(0, $this->savedCompletions());

	}//end testUnresolvableLessonIsSkippedWithoutError()

	/**
	 * An unknown xAPI verb is ignored entirely.
	 *
	 * @return void
	 */
	public function testUnknownVerbIsIgnored(): void {
		$now = new DateTime('2026-07-13 10:00:00', new DateTimeZone('Europe/Amsterdam'));
		$this->seed(
			'lesson',
			[
				'id' => 'lesson-3',
				'courseId' => 'course-1',
				'lifecycle' => 'published',
				'xapiObjectId' => 'https://learniq.test/lessons/lesson-3',
			]
		);

		$handler = $this->makeHandler(now: $now);

		$event = $this->makeXapiEvent(
			[
				'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/launched'],
				'object' => ['id' => 'https://learniq.test/lessons/lesson-3'],
				'verified_actor_id' => 'learner-1',
				'tenant_id' => 'tenant-a',
			]
		);

		$handler->handle($event);

		self::assertCount(0, $this->savedCompletions());

	}//end testUnknownVerbIsIgnored()

	/**
	 * A statement missing verified_actor_id is skipped without error (C6
	 * trust boundary — never falls back to payload.actor.*).
	 *
	 * @return void
	 */
	public function testMissingVerifiedActorIdIsSkipped(): void {
		$now = new DateTime('2026-07-13 10:00:00', new DateTimeZone('Europe/Amsterdam'));
		$this->seed(
			'lesson',
			[
				'id' => 'lesson-3',
				'courseId' => 'course-1',
				'lifecycle' => 'published',
				'xapiObjectId' => 'https://learniq.test/lessons/lesson-3',
			]
		);

		$handler = $this->makeHandler(now: $now);

		$event = $this->makeXapiEvent(
			[
				'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/completed'],
				'object' => ['id' => 'https://learniq.test/lessons/lesson-3'],
				'actor' => ['account' => ['name' => 'attacker-controlled']],
				'tenant_id' => 'tenant-a',
			]
		);

		$handler->handle($event);

		self::assertCount(0, $this->savedCompletions());

	}//end testMissingVerifiedActorIdIsSkipped()

	/**
	 * An event on a different schema is ignored entirely.
	 *
	 * @return void
	 */
	public function testUnrelatedSchemaIsIgnored(): void {
		$now = new DateTime('2026-07-13 10:00:00', new DateTimeZone('Europe/Amsterdam'));
		$handler = $this->makeHandler(now: $now);

		$objectEntity = OrEntityFactory::make(['id' => 'x'], '1281', '9');
		$this->stubResolver('grade-entry');

		$event = $this->createMock(ObjectCreatedEvent::class);
		$event->method('getObject')->willReturn($objectEntity);

		$handler->handle($event);

		self::assertCount(0, $this->savedCompletions());

	}//end testUnrelatedSchemaIsIgnored()

	/**
	 * Record one queued entry (called by the deferral fake).
	 *
	 * @param string               $jobClass  The job class.
	 * @param array<string, mixed> $entry     The entry.
	 * @param string|null          $dedupeKey The dedupe key.
	 *
	 * @return bool Whether the fake should run the entry now.
	 */
	public function recordQueued(string $jobClass, array $entry, ?string $dedupeKey): bool {
		$this->queued[] = ['jobClass' => $jobClass, 'entry' => $entry, 'dedupeKey' => $dedupeKey];
		return $this->runQueued;
	}//end recordQueued()

	/**
	 * The handler reads and writes nothing inside the statement's save: it
	 * queues the statement, stamped with its completion time, for
	 * XapiStatementFollowUpJob (hydra gate 61, ADR-078).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/progress-tracking/spec.md#scenario-a-completion-statement-is-queued-not-processed-inline
	 */
	public function testTheHandlerQueuesTheWorkAndWritesNothingItself(): void {
		$now = new DateTime('2026-07-13 10:00:00', new DateTimeZone('Europe/Amsterdam'));
		$this->seed('lesson', ['id' => 'lesson-3', 'courseId' => 'course-1', 'xapiObjectId' => 'https://learniq.test/lessons/lesson-3']);
		$this->runQueued = false;
		$handler = $this->makeHandler(now: $now);

		$statement = [
			'id' => 'stmt-1',
			'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/completed'],
			'object' => ['id' => 'https://learniq.test/lessons/lesson-3'],
			'verified_actor_id' => 'learner-1',
		];
		$handler->handle($this->makeXapiEvent($statement));
		$handler->handle($this->makeXapiEvent(['id' => 'stmt-2', 'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/attempted']]));

		self::assertCount(0, $this->savedObjects);
		self::assertCount(1, $this->queued, 'Only a completion statement is queued.');
		self::assertSame(XapiStatementFollowUpJob::class, $this->queued[0]['jobClass']);
		self::assertSame(XapiStatementFollowUpJob::LESSON_PROGRESS, $this->queued[0]['entry']['kind']);
		self::assertSame('stmt-1', $this->queued[0]['entry']['statement']['id']);
		self::assertSame($now->format(\DATE_ATOM), $this->queued[0]['entry']['completedAt']);
		self::assertSame('lesson-progress|stmt-1', $this->queued[0]['dedupeKey']);
	}//end testTheHandlerQueuesTheWorkAndWritesNothingItself()
}//end class
