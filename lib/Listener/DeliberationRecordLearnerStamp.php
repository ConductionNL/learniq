<?php

/**
 * Learniq Deliberation Record Learner Stamp
 *
 * A deliberation record names its pupil only through its support request.
 * On every write this stamps `learnerId` from that support request, so the
 * pupil's dossier timeline can find the record by the pupil. A client value
 * is never kept: a record without a support request, or whose request names
 * no pupil, stores none.
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
 * @spec openspec/changes/dossier-timeline-deliberations/specs/pupil-dossier/spec.md#requirement-a-deliberation-record-names-its-pupil
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
 * Stamps a deliberation record's pupil from its support request.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/dossier-timeline-deliberations/specs/pupil-dossier/spec.md#requirement-a-deliberation-record-names-its-pupil
 */
class DeliberationRecordLearnerStamp implements IEventListener {

	private const SCHEMA = 'deliberation-record';

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Entity schema id to slug.
	 * @param ObjectService          $objectService  Reads the support request.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Stamp `learnerId` on a deliberation record write.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dossier-timeline-deliberations/specs/pupil-dossier/spec.md#requirement-a-deliberation-record-names-its-pupil
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) || $event->isPropagationStopped() === true) {
			return;
		}

		$entity = $this->entityOf(event: $event);
		try {
			if ($this->schemaResolver->guardSchemaSlug(entity: $entity) !== self::SCHEMA) {
				return;
			}
		} catch (Throwable) {
			return;
		}

		$payload = array_merge(($entity->getObject() ?? []), $event->getModifiedData());
		$event->setModifiedData(array_merge($event->getModifiedData(), ['learnerId' => $this->learnerOf(supportRequestId: (string)($payload['supportRequestId'] ?? ''))]));
	}//end handle()

	/**
	 * The pupil of a support request, or null.
	 *
	 * @param string $supportRequestId The support request's uuid.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/dossier-timeline-deliberations/specs/pupil-dossier/spec.md#requirement-a-deliberation-record-names-its-pupil
	 */
	public function learnerOf(string $supportRequestId): ?string {
		if ($supportRequestId === '') {
			return null;
		}

		try {
			$request = $this->objectService->find(id: $supportRequestId, register: 'learniq', schema: 'support-request', _rbac: false, _multitenancy: false);
		} catch (Throwable) {
			return null;
		}

		$learnerId = ($request?->jsonSerialize()['learnerId'] ?? null);
		if (is_string($learnerId) === false || $learnerId === '') {
			return null;
		}

		return $learnerId;
	}//end learnerOf()

	/**
	 * The entity being written.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event.
	 *
	 * @return ObjectEntity
	 */
	private function entityOf(ObjectCreatingEvent|ObjectUpdatingEvent $event): ObjectEntity {
		if ($event instanceof ObjectCreatingEvent === true) {
			return $event->getObject();
		}

		return $event->getNewObject();
	}//end entityOf()
}//end class
