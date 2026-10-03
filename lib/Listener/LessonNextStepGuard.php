<?php

/**
 * Learniq Lesson Next Step Guard
 *
 * Refuses a lesson whose next step rules or default next lesson point at a
 * lesson of another course (or at no lesson at all). A rule belongs to its
 * course: a learner may not be sent out of the course they are enrolled in.
 * The check reads other rows, so it is a pre-write veto, not a schema rule.
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
 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#scenario-a-rule-cannot-leave-the-course
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Throwable;

/**
 * Keeps a lesson's next steps inside its own course.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#scenario-a-rule-cannot-leave-the-course
 */
class LessonNextStepGuard implements IEventListener {

	private const LESSON_SCHEMA = 'lesson';

	private const REGISTER = 'learniq';

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Resolves the event entity's schema slug.
	 * @param ObjectService          $objectService  Reads the target lessons.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Handle an OpenRegister creating or updating event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#scenario-a-rule-cannot-leave-the-course
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$entity = $this->writtenEntity(event: $event);

		if ($this->isLesson(entity: $entity) === false) {
			return;
		}

		$lesson = ($entity->getObject() ?? []);
		$courseId = (string)($lesson['courseId'] ?? '');
		foreach ($this->targets(lesson: $lesson) as $targetId) {
			if ($this->courseOf(lessonId: $targetId) !== $courseId || $courseId === '') {
				$event->setErrors(
					[
						'reason'  => 'next-step-outside-course',
						'message' => 'A next step can only go to a lesson of the same course.',
					]
				);
				$event->stopPropagation();
				return;
			}
		}
	}//end handle()

	/**
	 * Every lesson id the rules and the default point at.
	 *
	 * @param array<string, mixed> $lesson The lesson as it will be stored.
	 *
	 * @return list<string>
	 */
	private function targets(array $lesson): array {
		$targets = [];
		$rules = ($lesson['nextStepRules'] ?? []);
		if (is_array($rules) === true) {
			foreach ($rules as $rule) {
				if (is_array($rule) === true && (string)($rule['goToLessonId'] ?? '') !== '') {
					$targets[] = (string)$rule['goToLessonId'];
				}
			}
		}

		$default = (string)($lesson['defaultNextLessonId'] ?? '');
		if ($default !== '') {
			$targets[] = $default;
		}

		return array_values(array_unique($targets));
	}//end targets()

	/**
	 * The course of a lesson, or null when it cannot be read.
	 *
	 * @param string $lessonId The lesson uuid.
	 *
	 * @return string|null
	 */
	private function courseOf(string $lessonId): ?string {
		try {
			$object = $this->objectService->find(id: $lessonId, register: self::REGISTER, schema: self::LESSON_SCHEMA, _rbac: false);
		} catch (Throwable) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return (string)($object->jsonSerialize()['courseId'] ?? '');
	}//end courseOf()

	/**
	 * The object being written, read through the accessor each event really has.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-write event.
	 *
	 * @return ObjectEntity The object as it will be stored.
	 */
	private function writtenEntity(ObjectCreatingEvent|ObjectUpdatingEvent $event): ObjectEntity {
		if ($event instanceof ObjectUpdatingEvent === true) {
			return $event->getNewObject();
		}

		return $event->getObject();
	}//end writtenEntity()

	/**
	 * Whether the entity is a lesson.
	 *
	 * @param ObjectEntity $entity The object being written.
	 *
	 * @return bool
	 */
	private function isLesson(ObjectEntity $entity): bool {
		try {
			return $this->schemaResolver->guardSchemaSlug(entity: $entity) === self::LESSON_SCHEMA;
		} catch (Throwable) {
			// Not knowing the schema is not knowing it is ours: never break
			// another app's writes.
			return false;
		}
	}//end isLesson()
}//end class
