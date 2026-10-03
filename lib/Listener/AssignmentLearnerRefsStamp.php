<?php

/**
 * Learniq AssignmentLearnerRefsStamp
 *
 * Stamps Assignment.learnerRefs, the LearnerProfile uuids of the pupils
 * enrolled in the assignment's group (cohortId), on every create and update.
 * The guardian portal reads homework through the reverse join on a
 * guardian's children, and that join needs a scope key on the assignment
 * that names the pupil (portal-parent-child-record). The list is never
 * projected to the portal, so no guardian sees another pupil's uuid.
 *
 * A client value is always overwritten. When the group cannot be read the
 * stored list stays, so a passing database hiccup never empties a guardian's
 * homework.
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
 * @spec openspec/changes/portal-parent-child-record/specs/portal-contribution/spec.md#requirement-a-guardian-reads-the-homework-of-their-childs-group
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
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Derives Assignment.learnerRefs from the enrolments of its group.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/portal-parent-child-record/specs/portal-contribution/spec.md#requirement-a-guardian-reads-the-homework-of-their-childs-group
 */
class AssignmentLearnerRefsStamp implements IEventListener {

	private const REGISTER = 'learniq';

	private const ASSIGNMENT_SCHEMA = 'assignment';

	private const ENROLMENT_SCHEMA = 'enrolment';

	/**
	 * The most enrolments one group read returns.
	 */
	private const MAX_ENROLMENTS = 1000;

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Entity schema id to slug.
	 * @param ObjectService $objectService Reads the group's enrolments.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Stamp learnerRefs on an Assignment create or update.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-parent-child-record/specs/portal-contribution/spec.md#requirement-a-guardian-reads-the-homework-of-their-childs-group
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		if ($event->isPropagationStopped() === true) {
			return;
		}

		$entity = $this->entityOf(event: $event);
		try {
			$slug = $this->schemaResolver->guardSchemaSlug(entity: $entity);
		} catch (Throwable $exception) {
			// Not knowing the schema is not knowing it is ours.
			return;
		}

		if ($slug !== self::ASSIGNMENT_SCHEMA) {
			return;
		}

		$payload = array_merge(($entity->getObject() ?? []), $event->getModifiedData());
		$cohortId = $payload['cohortId'] ?? null;

		$refs = [];
		if (is_string($cohortId) === true && $cohortId !== '') {
			$refs = $this->pupilsOf(cohortId: $cohortId, stored: $this->storedRefs(event: $event));
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), ['learnerRefs' => $refs]));
	}//end handle()

	/**
	 * The object being written: the new state on an update.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event.
	 *
	 * @return ObjectEntity
	 */
	private function entityOf(ObjectCreatingEvent|ObjectUpdatingEvent $event): ObjectEntity {
		if ($event instanceof ObjectUpdatingEvent === true) {
			return $event->getNewObject();
		}

		return $event->getObject();
	}//end entityOf()

	/**
	 * The learnerRefs the assignment carried before this update, empty on create.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event.
	 *
	 * @return array<int, string>
	 */
	private function storedRefs(ObjectCreatingEvent|ObjectUpdatingEvent $event): array {
		if ($event instanceof ObjectUpdatingEvent === false) {
			return [];
		}

		$old = ($event->getOldObject()->getObject() ?? []);
		return array_values(array_filter((array)($old['learnerRefs'] ?? []), static fn ($ref): bool => is_string($ref) === true && $ref !== ''));
	}//end storedRefs()

	/**
	 * The profile uuids of the pupils enrolled in a group, each once; the
	 * stored list when the enrolments cannot be read.
	 *
	 * @param string $cohortId The group.
	 * @param array<int, string> $stored The list already stored.
	 *
	 * @return array<int, string>
	 */
	private function pupilsOf(string $cohortId, array $stored): array {
		try {
			$enrolments = $this->objectService->findAll(
				config: [
					'filters' => [
						'register' => self::REGISTER,
						'schema' => self::ENROLMENT_SCHEMA,
						'cohortId' => $cohortId,
					],
					'limit' => self::MAX_ENROLMENTS,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[AssignmentLearnerRefsStamp] The enrolments of group {group} could not be read, keeping {kept} ref(s): {msg}',
				['group' => $cohortId, 'kept' => count($stored), 'msg' => $exception->getMessage()]
			);
			return $stored;
		}

		$refs = [];
		foreach ($enrolments as $enrolment) {
			$row = $enrolment;
			if ($enrolment instanceof ObjectEntity === true) {
				$row = $enrolment->jsonSerialize();
			}

			$ref = null;
			if (is_array($row) === true) {
				$ref = ($row['learnerRef'] ?? null);
			}

			if (is_string($ref) === true && $ref !== '') {
				$refs[$ref] = true;
			}
		}

		return array_keys($refs);
	}//end pupilsOf()
}//end class
