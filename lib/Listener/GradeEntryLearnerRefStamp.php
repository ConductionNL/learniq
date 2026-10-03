<?php

/**
 * Learniq GradeEntry learnerRef Stamp
 *
 * Stamps `learnerRef` (the LearnerProfile UUID the portal scopes on) onto
 * every GradeEntry as it is created or updated, derived from `learnerId`.
 *
 * Eight code paths create a GradeEntry (the assessment and portfolio grade
 * bridges, marking a submission, the cohort gradebook, the LTI score poll,
 * exemptions, werkproces assessments, the generic form) and none of them sets
 * `learnerRef`, so no grade ever reached a pupil or parent through the portal.
 * Stamping on the write path covers every one of them, and the next one.
 *
 * Posture, mirroring AssessmentResultAudience:
 * - the server derives the value; a `learnerRef` sent by the client is
 *   ignored, so nobody can route a grade to another pupil's parents;
 * - a learner without a profile gets `learnerRef: null`, which keeps the
 *   grade out of the portal (fail-closed);
 * - the stamp never blocks a write. When the lookup fails on create the
 *   value is null; on update the stored value is kept, so a transient error
 *   never hides a grade that was visible.
 *
 * ADR-031 exception: a cross-schema lookup (GradeEntry to LearnerProfile by a
 * non-key field) that no schema calculation can express.
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
 * @spec openspec/specs/grading/spec.md#requirement-every-gradeentry-carries-a-server-stamped-learnerref
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Derives GradeEntry.learnerRef from GradeEntry.learnerId on every write.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/grading/spec.md#requirement-every-gradeentry-carries-a-server-stamped-learnerref
 */
class GradeEntryLearnerRefStamp implements IEventListener {

	private const GRADE_ENTRY_SCHEMA = 'grade-entry';

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Entity schema id to slug.
	 * @param LearnerRefResolver $learnerRefs Nextcloud user id to LearnerProfile UUID.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LearnerRefResolver $learnerRefs,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Stamp learnerRef on a GradeEntry create or update.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/grading/spec.md#requirement-every-gradeentry-carries-a-server-stamped-learnerref
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		if ($event->isPropagationStopped() === true) {
			return;
		}

		$stored = null;
		$entity = $this->entityOf(event: $event);

		try {
			$slug = $this->schemaResolver->guardSchemaSlug(entity: $entity);
		} catch (Throwable $exception) {
			// Not knowing the schema is not knowing it is ours: never touch
			// another app's writes.
			return;
		}

		if ($slug !== self::GRADE_ENTRY_SCHEMA) {
			return;
		}

		$payload = array_merge(($entity->getObject() ?? []), $event->getModifiedData());
		$learnerId = $payload['learnerId'] ?? '';
		if (is_string($learnerId) === false) {
			$learnerId = '';
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$stored = $this->storedRef(event: $event, learnerId: $learnerId);
		}

		$event->setModifiedData(
			array_merge(
				$event->getModifiedData(),
				['learnerRef' => $this->derive(learnerId: $learnerId, stored: $stored)]
			)
		);
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
	 * The learnerRef to store: the resolved profile UUID, null when there is
	 * none, and on a lookup error the value already stored (null on create).
	 *
	 * @param string $learnerId Nextcloud user id on the entry.
	 * @param string|null $stored learnerRef already stored, null on create.
	 *
	 * @return string|null
	 */
	private function derive(string $learnerId, ?string $stored): ?string {
		try {
			return $this->learnerRefs->resolve(learnerId: $learnerId);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[GradeEntryLearnerRefStamp] Could not resolve the learner profile, keeping {kept}: {msg}',
				['kept' => ($stored ?? 'null'), 'msg' => $exception->getMessage()]
			);
			return $stored;
		}
	}//end derive()

	/**
	 * The learnerRef the entry carries before this update, or null. Null too
	 * when the update moves the entry to another learner: the old value then
	 * names the wrong profile, so a failed lookup must fail closed.
	 *
	 * @param ObjectUpdatingEvent $event The update event.
	 * @param string $learnerId The learnerId the entry will carry.
	 *
	 * @return string|null
	 */
	private function storedRef(ObjectUpdatingEvent $event, string $learnerId): ?string {
		$old = $event->getOldObject();
		if ($old === null) {
			return null;
		}

		$oldData = ($old->getObject() ?? []);
		if (($oldData['learnerId'] ?? null) !== $learnerId) {
			return null;
		}

		$ref = ($oldData['learnerRef'] ?? null);
		if (is_string($ref) === false || $ref === '') {
			return null;
		}

		return $ref;
	}//end storedRef()
}//end class
