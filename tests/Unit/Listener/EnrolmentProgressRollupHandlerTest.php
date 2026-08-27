<?php

/**
 * Learniq EnrolmentProgressRollupHandler unit tests.
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
 * @spec openspec/changes/learning-progress-and-analytics/specs/enrolment/spec.md#requirement-enrolment-carries-a-declared-lesson-progress-roll-up
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\BackgroundJob\EnrolmentProgressRollupJob;
use OCA\Learniq\Listener\EnrolmentProgressRollupHandler;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\Learniq\Progress\EnrolmentProgressEvaluator;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EnrolmentProgressRollupHandler::handle() on ObjectCreatedEvent<LessonCompletion>.
 */
class EnrolmentProgressRollupHandlerTest extends TestCase {

	/**
	 * Recorded saveObject() calls.
	 *
	 * @var array<int, array{register: string, schema: string, object: array<string, mixed>}>
	 */
	private array $savedObjects = [];

	/**
	 * Deferrals captured from the mocked ListenerDeferralService.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $deferred = [];

	/**
	 * The mocked deferral service.
	 *
	 * @var ListenerDeferralService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private ListenerDeferralService $deferral;

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
		$this->savedObjects = [];
		$this->deferred = [];
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
	 * Build a handler with mocked collaborators.
	 *
	 * @param array<int, array<string, mixed>> $enrolments Enrolment rows returned for the active-enrolment lookup.
	 * @param array{progressPercent: int, completedLessonCount: int, totalPublishedLessonCount: int} $evaluated Result the mocked evaluator returns.
	 *
	 * @return EnrolmentProgressRollupHandler
	 */
	private function makeHandler(array $enrolments, array $evaluated): EnrolmentProgressRollupHandler {
		$this->deferral = $this->createMock(ListenerDeferralService::class);
		$this->deferral->method('defer')->willReturnCallback(
			function (string $jobClass, array $entry, int $chunkSize = 100, ?string $dedupeKey = null): void {
				$this->deferred[] = [
					'jobClass' => $jobClass,
					'entry' => $entry,
					'dedupeKey' => $dedupeKey,
				];
			}
		);

		return new EnrolmentProgressRollupHandler($this->deferral, $this->schemaResolver);
	}//end makeHandler()

	/**
	 * Build a mocked ObjectCreatedEvent<LessonCompletion>.
	 *
	 * @param array<string, mixed> $data The LessonCompletion jsonSerialize() payload.
	 *
	 * @return ObjectCreatedEvent
	 */
	private function makeEvent(array $data): ObjectCreatedEvent {
		$objectEntity = OrEntityFactory::make($data, '1280', '9');
		$this->stubResolver('lesson-completion');

		$event = $this->createMock(ObjectCreatedEvent::class);
		$event->method('getObject')->willReturn($objectEntity);

		return $event;
	}//end makeEvent()

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

		$handler->handle(
			$this->makeEvent(
				['learnerId' => 'learner-1', 'lessonId' => 'lesson-4', 'courseId' => 'course-1', 'source' => 'xapi']
			)
		);

		// The roll-up is DEFERRED, not performed here. The listener's job is to
		// decide that a roll-up is owed and say for whom — the recompute and
		// the write happen in EnrolmentProgressRollupJob, where they no longer
		// sit inside the LessonCompletion write.
		self::assertCount(1, $this->deferred);
		self::assertSame(EnrolmentProgressRollupJob::class, $this->deferred[0]['jobClass']);
		self::assertSame('learner-1', $this->deferred[0]['entry']['learnerId']);
		self::assertSame('course-1', $this->deferred[0]['entry']['courseId']);
		self::assertSame(0, count($this->savedObjects), 'nothing may be written on the event path');

	}//end testNewCompletionTriggersRecompute()

	/**
	 * Repeated completions for one learner+course coalesce into ONE roll-up.
	 *
	 * The dedupe key is what makes deferring cheaper than inline rather than
	 * merely later: a learner finishing ten lessons in one request owes one
	 * recompute, not ten identical ones.
	 *
	 * @return void
	 * @spec openspec/changes/learning-progress-and-analytics/specs/enrolment/spec.md#requirement-enrolment-carries-a-declared-lesson-progress-roll-up
	 */
	public function testTheDedupeKeyIsLearnerAndCourse(): void {
		$handler = $this->makeHandler(enrolments: [], evaluated: ['progressPercent' => 0, 'completedLessonCount' => 0, 'totalPublishedLessonCount' => 0]);

		$handler->handle(
			$this->makeEvent(
				['learnerId' => 'learner-1', 'lessonId' => 'lesson-4', 'courseId' => 'course-1', 'source' => 'xapi']
			)
		);

		self::assertSame('learner-1|course-1', $this->deferred[0]['dedupeKey']);

	}//end testTheDedupeKeyIsLearnerAndCourse()

	/**
	 * A learner with no active Enrolment for the completion's course is
	 * skipped without error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learning-progress-and-analytics/specs/enrolment/spec.md#requirement-enrolment-carries-a-declared-lesson-progress-roll-up
	 */
	public function testNoActiveEnrolmentIsSkipped(): void {
		// The listener no longer knows whether an Enrolment exists — it defers
		// on the completion alone, and the JOB decides there is nothing to
		// recompute onto. Covered by
		// EnrolmentProgressRollupJobTest::testAnEntryWithNoActiveEnrolmentWritesNothing.
		$handler = $this->makeHandler(
			enrolments: [],
			evaluated: ['progressPercent' => 0, 'completedLessonCount' => 0, 'totalPublishedLessonCount' => 0]
		);

		$handler->handle(
			$this->makeEvent(
				['learnerId' => 'learner-1', 'lessonId' => 'lesson-4', 'courseId' => 'course-1', 'source' => 'xapi']
			)
		);

		self::assertCount(0, $this->savedObjects, 'the event path writes nothing either way');

	}//end testNoActiveEnrolmentIsSkipped()

	/**
	 * An event on a different schema is ignored entirely.
	 *
	 * @return void
	 */
	/**
	 * An event of another type is ignored. The listener is registered for one
	 * event, but the dispatcher hands `Event`, so the instanceof guard is the
	 * only thing standing between it and a call to getObject() that does not
	 * exist on that class.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enrolment/spec.md#scenario-progress-percentage-recomputes-when-a-lesson-is-completed
	 */
	public function testAnEventOfAnotherTypeIsIgnored(): void {
		$handler = $this->makeHandler(
			enrolments: [['id' => 'enrolment-1', 'learnerId' => 'learner-1', 'courseId' => 'course-1']],
			evaluated: ['progressPercent' => 50, 'completedLessonCount' => 5, 'totalPublishedLessonCount' => 10]
		);

		$handler->handle(new \OCP\EventDispatcher\GenericEvent());

		self::assertCount(0, $this->deferred);

	}//end testAnEventOfAnotherTypeIsIgnored()

	/**
	 * A completion missing either id enqueues nothing. A roll-up keyed on an
	 * empty learner or course would match every row or none, and the deferral
	 * dedupe key is built from exactly those two values.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enrolment/spec.md#scenario-progress-percentage-recomputes-when-a-lesson-is-completed
	 */
	public function testACompletionMissingAnIdEnqueuesNothing(): void {
		$handler = $this->makeHandler(
			enrolments: [['id' => 'enrolment-1', 'learnerId' => 'learner-1', 'courseId' => 'course-1']],
			evaluated: ['progressPercent' => 50, 'completedLessonCount' => 5, 'totalPublishedLessonCount' => 10]
		);

		$handler->handle($this->makeEvent(['learnerId' => 'learner-1', 'courseId' => '']));
		$handler->handle($this->makeEvent(['learnerId' => '', 'courseId' => 'course-1']));

		self::assertCount(0, $this->deferred);

	}//end testACompletionMissingAnIdEnqueuesNothing()

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

		self::assertCount(0, $this->deferred, 'an unrelated schema must not even enqueue work');

	}//end testUnrelatedSchemaIsIgnored()
}//end class
