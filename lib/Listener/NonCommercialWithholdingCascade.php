<?php

/**
 * Learniq NonCommercialWithholdingCascade
 *
 * When a course, one of its fee items or one of its materials is saved or
 * deleted, queues NonCommercialWithholdingJob for that course, so a course that
 * becomes paid or corporate stops serving its non-commercial (NC) material to
 * learners and one that becomes free serves it again. The writes run after the
 * request (ADR-078), never inside the triggering save.
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
 * @spec openspec/changes/open-learning-material
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\BackgroundJob\NonCommercialWithholdingJob;
use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Throwable;

/**
 * Queues the withheld-mark sync of the course a saved object belongs to.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/open-learning-material/spec.md#scenario-non-commercial-material-is-flagged-in-a-paid-course
 */
class NonCommercialWithholdingCascade implements IEventListener {

	/**
	 * Per schema, the property naming the course; null means the object is the course.
	 */
	private const COURSE_PROPERTY = [
		'course'   => null,
		'fee-item' => 'linkedCourseId',
		'material' => 'courseId',
	];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolverInterface $schemaResolver Entity schema id to slug.
	 * @param ListenerDeferralService         $deferral       Queues the sync for after the request.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly ListenerDeferralService $deferral,
	) {
	}//end __construct()

	/**
	 * Queue the sync of the course(s) the written object belongs to, before and after the write.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#scenario-non-commercial-material-is-flagged-in-a-paid-course
	 */
	public function handle(Event $event): void {
		$entities = $this->entitiesOf(event: $event);
		if ($entities === []) {
			return;
		}

		try {
			$slug = $this->schemaResolver->guardSchemaSlug(entity: $entities[0]);
		} catch (Throwable $exception) {
			return;
		}

		if (array_key_exists($slug, self::COURSE_PROPERTY) === false) {
			return;
		}

		$courses = [];
		foreach ($entities as $entity) {
			$courseId = $this->courseIdOf(entity: $entity, property: self::COURSE_PROPERTY[$slug]);
			if ($courseId !== '') {
				$courses[$courseId] = true;
			}
		}

		foreach (array_keys($courses) as $courseId) {
			$this->deferral->defer(
				jobClass: NonCommercialWithholdingJob::class,
				entry: ['courseId' => (string)$courseId],
				dedupeKey: 'nc-withholding:' . $courseId
			);
		}
	}//end handle()

	/**
	 * The objects an event concerns: the new and the old one for an update.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array<int, ObjectEntity> The entities, empty for another event.
	 */
	private function entitiesOf(Event $event): array {
		if ($event instanceof ObjectUpdatedEvent === true) {
			return array_values(array_filter([$event->getNewObject(), $event->getOldObject()]));
		}

		if ($event instanceof ObjectCreatedEvent === true || $event instanceof ObjectDeletedEvent === true) {
			return [$event->getObject()];
		}

		return [];
	}//end entitiesOf()

	/**
	 * The course an object belongs to.
	 *
	 * @param ObjectEntity $entity   The object.
	 * @param string|null  $property The property naming the course; null when the object is the course.
	 *
	 * @return string The course UUID, or '' when there is none.
	 */
	private function courseIdOf(ObjectEntity $entity, ?string $property): string {
		if ($property === null) {
			return (string)$entity->getUuid();
		}

		$courseId = (($entity->getObject() ?? [])[$property] ?? null);

		if (is_string($courseId) === false) {
			return '';
		}

		return $courseId;
	}//end courseIdOf()
}//end class
