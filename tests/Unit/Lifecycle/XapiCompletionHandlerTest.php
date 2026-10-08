<?php

/**
 * Tests for XapiCompletionHandler and XapiEnrolmentCompletion: the handler
 * only queues, the job's work completes the enrolment.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
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
 * @spec openspec/specs/progress-tracking/spec.md#requirement-the-follow-up-of-an-xapi-statement-runs-outside-the-save-that-records-it
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\BackgroundJob\XapiStatementFollowUpJob;
use OCA\Learniq\Lifecycle\XapiCompletionHandler;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Service\XapiEnrolmentCompletion;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The handler over a recording deferral; the work over an in-memory store.
 */
class XapiCompletionHandlerTest extends TestCase {

	/**
	 * Entries the handler queued.
	 *
	 * @var array<int, array{jobClass: string, entry: array<string, mixed>, dedupeKey: string|null}>
	 */
	public array $queued = [];

	/**
	 * Rows by schema slug.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * How many times the store was read.
	 *
	 * @var int
	 */
	private int $reads = 0;

	/**
	 * Transitions run, as `<id>:<action>`.
	 *
	 * @var array<int, string>
	 */
	private array $transitions = [];

	/**
	 * A completed statement for the second lesson.
	 *
	 * @var array<string, mixed>
	 */
	private const STATEMENT = [
		'id' => 'stmt-1',
		'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/passed'],
		'object' => ['id' => 'https://learniq.test/lessons/2'],
		'lessonId' => 'l-2',
		'verified_actor_id' => 'learner-1',
	];

	/**
	 * An in-memory ObjectService.
	 *
	 * @return ObjectService
	 */
	private function objects(): ObjectService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				$this->reads++;
				$filters = array_diff_key($config['filters'], ['register' => true, 'schema' => true]);
				$ids     = ($config['ids'] ?? null);
				return array_values(
					array_filter(
						($this->rows[$config['filters']['schema']] ?? []),
						static function (array $row) use ($filters, $ids): bool {
							if ($ids !== null && in_array(($row['id'] ?? ($row['uuid'] ?? null)), $ids, true) === false) {
								return false;
							}

							foreach ($filters as $field => $value) {
								if (($row[$field] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		);
		return $objects;
	}//end objects()

	/**
	 * The enrolment completion work.
	 *
	 * @return XapiEnrolmentCompletion
	 */
	private function completion(): XapiEnrolmentCompletion {
		$engine = $this->createMock(TransitionEngine::class);
		$engine->method('transition')->willReturnCallback(
			function (string $id, string $action): mixed {
				$this->transitions[] = $id . ':' . $action;
				return OrEntityFactory::make(['id' => $id], 'enrolment');
			}
		);
		return new XapiEnrolmentCompletion($this->objects(), $engine, new NullLogger());
	}//end completion()

	/**
	 * The handler, with a deferral that records instead of queueing.
	 *
	 * @param string $schemaSlug The schema the created object resolves to.
	 *
	 * @return XapiCompletionHandler
	 */
	private function handler(string $schemaSlug='xapi-statement'): XapiCompletionHandler {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('registerSlug')->willReturn('learniq');
		$resolver->method('schemaSlug')->willReturn($schemaSlug);

		$test = $this;
		$deferral = new class ($test) extends ListenerDeferralService {
			/**
			 * Constructor.
			 *
			 * @param XapiCompletionHandlerTest $test The test, to record entries.
			 */
			public function __construct(private readonly XapiCompletionHandlerTest $test) {
			}//end __construct()

			/**
			 * Record the entry.
			 *
			 * @param string               $jobClass  The job class.
			 * @param array<string, mixed> $entry     The entry.
			 * @param int                  $chunkSize Unused.
			 * @param string|null          $dedupeKey The dedupe key.
			 *
			 * @return void
			 */
			public function defer(string $jobClass, array $entry, int $chunkSize = self::DEFAULT_CHUNK_SIZE, ?string $dedupeKey = null): void {
				$this->test->queued[] = ['jobClass' => $jobClass, 'entry' => $entry, 'dedupeKey' => $dedupeKey];
			}//end defer()
		};

		return new XapiCompletionHandler($deferral, $resolver);
	}//end handler()

	/**
	 * A created event for a statement.
	 *
	 * @param array<string, mixed> $statement The statement.
	 *
	 * @return ObjectCreatedEvent
	 */
	private function event(array $statement): ObjectCreatedEvent {
		$event = $this->createMock(ObjectCreatedEvent::class);
		$event->method('getObject')->willReturn(OrEntityFactory::make($statement, '1280', '9'));
		return $event;
	}//end event()

	/**
	 * Two published mandatory lessons and an active enrolment.
	 *
	 * @return void
	 */
	private function course(): void {
		$this->rows['lesson'] = [
			['uuid' => 'l-1', 'courseId' => 'c-1', 'order' => 1, 'lifecycle' => 'published', 'mandatoryTraining' => true],
			['uuid' => 'l-2', 'courseId' => 'c-1', 'order' => 2, 'lifecycle' => 'published', 'mandatoryTraining' => true],
		];
		$this->rows['enrolment'] = [['uuid' => 'en-1', 'learnerId' => 'learner-1', 'courseId' => 'c-1', 'lifecycle' => 'active']];
	}//end course()

	/**
	 * The handler queues a completion statement and reads nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/progress-tracking/spec.md#scenario-a-completion-statement-is-queued-not-processed-inline
	 */
	public function testTheHandlerQueuesACompletionStatementAndReadsNothing(): void {
		$this->course();
		$handler = $this->handler();

		$handler->handle($this->event(self::STATEMENT));
		$handler->handle($this->event(['id' => 'stmt-2', 'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/attempted']]));

		$this->assertSame(0, $this->reads);
		$this->assertSame([], $this->transitions);
		$this->assertCount(1, $this->queued);
		$this->assertSame(XapiStatementFollowUpJob::class, $this->queued[0]['jobClass']);
		$this->assertSame(XapiStatementFollowUpJob::ENROLMENT_COMPLETION, $this->queued[0]['entry']['kind']);
		$this->assertSame('enrolment-completion|stmt-1', $this->queued[0]['dedupeKey']);
	}//end testTheHandlerQueuesACompletionStatementAndReadsNothing()

	/**
	 * A statement on another schema is not queued.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsNotQueued(): void {
		$this->handler('grade-entry')->handle($this->event(self::STATEMENT));

		$this->assertSame([], $this->queued);
	}//end testAnotherSchemaIsNotQueued()

	/**
	 * The job's work completes the enrolment on the final mandatory lesson.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/progress-tracking/spec.md#scenario-the-job-completes-the-enrolment-on-the-final-mandatory-lesson
	 */
	public function testTheJobCompletesTheEnrolmentOnTheFinalMandatoryLesson(): void {
		$this->course();

		$this->completion()->complete(statement: self::STATEMENT);

		$this->assertSame(['en-1:complete'], $this->transitions);
	}//end testTheJobCompletesTheEnrolmentOnTheFinalMandatoryLesson()

	/**
	 * A lesson that is not the last, or a statement without a verified actor, completes nothing.
	 *
	 * @return void
	 */
	public function testNotTheLastLessonOrNoVerifiedActorCompletesNothing(): void {
		$this->course();
		$completion = $this->completion();

		$completion->complete(statement: array_merge(self::STATEMENT, ['object' => ['id' => 'https://learniq.test/lessons/1'], 'lessonId' => 'l-1']));
		$completion->complete(statement: array_diff_key(self::STATEMENT, ['verified_actor_id' => true]));

		$this->assertSame([], $this->transitions);
	}//end testNotTheLastLessonOrNoVerifiedActorCompletesNothing()
}//end class
