<?php

/**
 * Learniq Placement Qualification Cascade
 *
 * A pupil's page header shows the qualification and crebo of the running
 * placement. The header reads the learner profile, so whenever a placement
 * is created, changed or moves through its lifecycle, its student's profile
 * gets the line again (QualificationLabel) as `qualificationLabel`, written
 * only when it differs. Never fatal to the placement's own write.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T3
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Learniq\Service\Pupil\PupilPageReader;
use OCA\Learniq\Service\Pupil\QualificationLabel;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps the student's qualification line on her profile.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T3
 */
class PlacementQualificationCascade implements IEventListener {

	/**
	 * The profile field this cascade writes.
	 */
	public const FIELD = 'qualificationLabel';

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolverInterface $schemas       Resolves the event's schema.
	 * @param PupilPageReader                 $reader        Reads the profile and the placements.
	 * @param ObjectService                   $objectService Writes the profile.
	 * @param LoggerInterface                 $logger        Logs a failed write.
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T3
	 */
	public function __construct(
		private readonly ListenerSchemaResolverInterface $schemas,
		private readonly PupilPageReader $reader,
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * A placement was written: restamp its student.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T3
	 */
	public function handle(Event $event): void {
		$placement = $this->placementOf(event: $event);
		if ($placement === null) {
			return;
		}

		$this->restamp(learnerId: (string)($placement['learnerId'] ?? ''));
	}//end handle()

	/**
	 * Write the student's qualification line when it differs.
	 *
	 * @param string $learnerId The student's Nextcloud user id.
	 *
	 * @return bool True when the profile was written.
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T3
	 */
	public function restamp(string $learnerId): bool {
		if ($learnerId === '') {
			return false;
		}

		try {
			$profile = $this->reader->profileOf(learnerId: $learnerId);
			if ($profile === null) {
				return false;
			}

			$label = QualificationLabel::of(placements: $this->reader->ofPupil(schema: 'bpv-placement', learnerId: $learnerId));
			if (($profile[self::FIELD] ?? null) === $label) {
				return false;
			}

			$uuid   = $this->reader->idOf(row: $profile);
			$object = array_merge($profile, [self::FIELD => $label]);
			unset($object['@self'], $object['id']);
			$this->objectService->saveObject(
				object: $object,
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: 'learniq', schema: 'learner-profile'), uuid: $uuid),
				_rbac: false,
				_multitenancy: false
			);
			return true;
		} catch (Throwable $exception) {
			$this->logger->warning('[PlacementQualificationCascade] No qualification line for {user}: {msg}', ['user' => $learnerId, 'msg' => $exception->getMessage()]);
			return false;
		}//end try
	}//end restamp()

	/**
	 * The written placement, or null for any other event or schema.
	 *
	 * @param Event $event The event.
	 *
	 * @return array<string, mixed>|null
	 */
	private function placementOf(Event $event): ?array {
		try {
			if ($event instanceof ObjectTransitionedEvent) {
				$ours = $this->schemas->eventRegister(event: $event) === 'learniq' && $this->schemas->eventSchema(event: $event) === 'bpv-placement';
				return ($ours === true ? $event->getObject()->jsonSerialize() : null);
			}

			$entity = null;
			if ($event instanceof ObjectCreatedEvent) {
				$entity = $event->getObject();
			}

			if ($event instanceof ObjectUpdatedEvent) {
				$entity = $event->getNewObject();
			}

			if ($entity === null) {
				return null;
			}

			$ours   = $this->schemas->registerSlug(entity: $entity) === 'learniq' && $this->schemas->schemaSlug(entity: $entity) === 'bpv-placement';
			return ($ours === true ? $entity->jsonSerialize() : null);
		} catch (Throwable) {
			// Not knowing the schema is not knowing it is ours.
			return null;
		}
	}//end placementOf()
}//end class
