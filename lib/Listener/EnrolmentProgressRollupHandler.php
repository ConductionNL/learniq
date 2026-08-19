<?php

/**
 * Scholiq Enrolment Progress Rollup Handler
 *
 * Listens for OR's ObjectCreatedEvent on LessonCompletion objects and
 * recomputes the matching Enrolment's progressPercent via
 * EnrolmentProgressEvaluator. Mirrors GradeRollupHandler's role for
 * FinalGrade — a cross-schema roll-up write-bridge, not a TimedJob
 * (ADR-022).
 *
 * ADR-031 legitimate exception: event-to-object-write bridge that cannot be
 * expressed as a schema declaration — no division operator exists in this
 * register's calculation DSL (see EnrolmentProgressEvaluator).
 *
 * @category Listener
 * @package  OCA\Scholiq\Listener
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

namespace OCA\Scholiq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\BackgroundJob\DeferredObjectListenerJob;
use OCA\Scholiq\Progress\EnrolmentProgressEvaluator;
use OCA\Scholiq\Service\ListenerSchemaResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Recomputes Enrolment.progressPercent whenever a LessonCompletion is created.
 *
 * ADR-078: `ObjectCreatedEvent` is a POST event. The LessonCompletion is
 * already written and nothing this listener does can change that, so the
 * Enrolment recompute no longer runs inside the learner's write — it is
 * deferred to {@see DeferredObjectListenerJob} under the acting user. The
 * deferred pass re-reads the LessonCompletion, so an entry whose row is gone is
 * simply a no-op.
 *
 * @implements IEventListener<Event>
 */
class EnrolmentProgressRollupHandler implements IEventListener, DeferredObjectWork {

	private const SCHOLIQ_REGISTER = 'scholiq';
	private const LESSON_COMPLETION_SCHEMA = 'lesson-completion';
	private const ENROLMENT_SCHEMA = 'enrolment';

	/**
	 * Identifies this listener's entries in the deferral job.
	 *
	 * @var string
	 */
	public const HANDLER_KEY = 'enrolment-progress-rollup';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access.
	 * @param EnrolmentProgressEvaluator $evaluator progressPercent calculation engine.
	 * @param ListenerSchemaResolver $schemaResolver Resolves the entity's register/schema ids to slugs.
	 * @param ListenerDeferralService $deferral The actor-forwarding deferral service.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly EnrolmentProgressEvaluator $evaluator,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly ListenerDeferralService $deferral,
	) {
	}//end __construct()

	/**
	 * Handle an ObjectCreatedEvent.
	 *
	 * Does no work: filters to the LessonCompletion schema and queues the
	 * recompute.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learning-progress-and-analytics/specs/enrolment/spec.md#scenario-progress-percentage-recomputes-when-a-lesson-is-completed
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatedEvent === false) {
			return;
		}

		$objectEntity = $event->getObject();

		if ($this->schemaResolver->registerSlug(entity: $objectEntity) !== self::SCHOLIQ_REGISTER
			|| $this->schemaResolver->schemaSlug(entity: $objectEntity) !== self::LESSON_COMPLETION_SCHEMA
		) {
			return;
		}

		$uuid = (string)$objectEntity->getUuid();
		if ($uuid === '') {
			return;
		}

		// Our own deferred Enrolment write re-enters this listener when the
		// mapper dispatches for it. Deferring again would enqueue another job
		// whose write re-enters again — a cron loop.
		if (DeferredWorkGuard::isRunning(key: DeferredWorkGuard::key(handler: self::HANDLER_KEY, uuid: $uuid)) === true) {
			return;
		}

		$this->deferral->defer(
			jobClass: DeferredObjectListenerJob::class,
			entry: [
				'handler' => self::HANDLER_KEY,
				'uuid' => $uuid,
			],
			dedupeKey: self::HANDLER_KEY . '|' . $uuid
		);

	}//end handle()

	/**
	 * Recompute the Enrolment's progressPercent against CURRENT state.
	 *
	 * Re-reads the LessonCompletion rather than trusting the dispatch-time
	 * payload: delivery is at-least-once and the row may have been edited or
	 * removed since (ADR-078 Rule 7).
	 *
	 * @param array<string, mixed> $entry The entry captured at dispatch time.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learning-progress-and-analytics/specs/enrolment/spec.md#scenario-progress-percentage-recomputes-when-a-lesson-is-completed
	 */
	public function runDeferredWork(array $entry): void {
		$completion = $this->readCompletion(uuid: (string)($entry['uuid'] ?? ''));
		if ($completion === null) {
			return;
		}

		$learnerId = $completion['learnerId'] ?? '';
		// LessonCompletion already denormalizes courseId (mirrors
		// XapiStatement.courseId/.lessonId) — no extra Lesson lookup needed
		// to resolve the course scope.
		$courseId = $completion['courseId'] ?? '';

		if ($learnerId === '' || $courseId === '') {
			return;
		}

		$enrolment = $this->findActiveEnrolment(learnerId: $learnerId, courseId: $courseId);
		if ($enrolment === null) {
			// No active Enrolment for this learner+course — nothing to
			// recompute onto. Skipped without error.
			return;
		}

		$result = $this->evaluator->evaluate(learnerId: $learnerId, courseId: $courseId);

		$this->objectService->saveObject(
			register: self::SCHOLIQ_REGISTER,
			schema: self::ENROLMENT_SCHEMA,
			object: array_merge($enrolment, ['progressPercent' => $result['progressPercent']])
		);

	}//end runDeferredWork()

	/**
	 * Re-read the LessonCompletion the entry refers to.
	 *
	 * @param string $uuid The LessonCompletion UUID.
	 *
	 * @return array<string, mixed>|null The current data, or null when it is gone.
	 */
	private function readCompletion(string $uuid): ?array {
		if ($uuid === '') {
			return null;
		}

		$object = $this->objectService->find(
			id: $uuid,
			register: self::SCHOLIQ_REGISTER,
			schema: self::LESSON_COMPLETION_SCHEMA
		);

		if ($object === null) {
			return null;
		}

		return $object->jsonSerialize();
	}//end readCompletion()

	/**
	 * Find the learner's active Enrolment for a course.
	 *
	 * @param string $learnerId NC user ID of the learner.
	 * @param string $courseId UUID of the Course.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findActiveEnrolment(string $learnerId, string $courseId): ?array {
		$results = $this->objectService->findAll(
			[
				'register' => self::SCHOLIQ_REGISTER,
				'schema' => self::ENROLMENT_SCHEMA,
				'filters' => [
					'learnerId' => $learnerId,
					'courseId' => $courseId,
					'lifecycle' => 'active',
				],
				'limit' => 1,
			]
		);

		if (empty($results) === true) {
			return null;
		}

		$enrolment = $results[0];
		if (is_array($enrolment) === false) {
			$enrolment = $enrolment->jsonSerialize();
		}

		return $enrolment;
	}//end findActiveEnrolment()
}//end class
