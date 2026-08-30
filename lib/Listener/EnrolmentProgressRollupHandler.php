<?php

/**
 * Learniq Enrolment Progress Rollup Handler
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
 * @package  OCA\Learniq\Listener
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

namespace OCA\Learniq\Listener;

use OCA\Learniq\BackgroundJob\EnrolmentProgressRollupJob;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Recomputes Enrolment.progressPercent whenever a LessonCompletion is created.
 *
 * @implements IEventListener<Event>
 */
class EnrolmentProgressRollupHandler implements IEventListener {

	private const LEARNIQ_REGISTER = 'learniq';
	private const LESSON_COMPLETION_SCHEMA = 'lesson-completion';

	/**
	 * Constructor.
	 *
	 * @param ListenerDeferralService $deferral Buffers the roll-up for after the request.
	 * @param ListenerSchemaResolver $schemaResolver Resolves the entity's register/schema ids to slugs.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerDeferralService $deferral,
		private readonly ListenerSchemaResolver $schemaResolver,
	) {
	}//end __construct()

	/**
	 * Handle an ObjectCreatedEvent.
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

		if ($this->schemaResolver->registerSlug(entity: $objectEntity) !== self::LEARNIQ_REGISTER
			|| $this->schemaResolver->schemaSlug(entity: $objectEntity) !== self::LESSON_COMPLETION_SCHEMA
		) {
			return;
		}

		$completion = $objectEntity->jsonSerialize();
		$learnerId = $completion['learnerId'] ?? '';
		// LessonCompletion already denormalizes courseId (mirrors
		// XapiStatement.courseId/.lessonId) — no extra Lesson lookup needed
		// to resolve the course scope.
		$courseId = $completion['courseId'] ?? '';

		if ($learnerId === '' || $courseId === '') {
			return;
		}

		// DEFERRED, not done here. This used to read the active Enrolment,
		// evaluate the learner's lesson progress and issue a second
		// saveObject() — all inside the LessonCompletion write that triggered
		// it. ADR-078 makes post-`*ed` work async by default, and nothing reads
		// `progressPercent` back in the same request, so inline bought only a
		// slower write.
		//
		// Deduped per learner+course: a batch of lesson completions for one
		// learner coalesces into ONE roll-up, because the roll-up recomputes
		// from scratch and repeating it yields the same number.
		$this->deferral->defer(
			jobClass: EnrolmentProgressRollupJob::class,
			entry: [
				'learnerId' => $learnerId,
				'courseId' => $courseId,
			],
			dedupeKey: $learnerId . '|' . $courseId
		);

	}//end handle()

}//end class
