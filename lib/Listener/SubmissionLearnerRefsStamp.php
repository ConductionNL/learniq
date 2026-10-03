<?php

/**
 * Learniq Submission learnerRefs Stamp
 *
 * Stamps `learnerRefs` (the LearnerProfile UUIDs the portal scopes on) onto
 * every Submission as it is created or updated, derived from `learnerIds`.
 *
 * The portal's student submissions collection matches the pupil's
 * LearnerProfile UUID against `Submission.learnerRefs`
 * (`PortalContributionProvider::studentActivityCollections()`), but no code
 * path set it, so the collection stayed empty for every pupil. Stamping on the
 * write path covers the portal upload, the learner's own form and every
 * teacher-side create, and the next one.
 *
 * Posture, mirroring GradeEntryLearnerRefStamp:
 * - the server derives the value; `learnerRefs` sent by the client is
 *   ignored, so nobody can route a submission to another pupil;
 * - a learner without a profile adds no entry, so a submission whose
 *   learners have no profile stays out of the portal (fail-closed);
 * - the stamp never blocks a write. When a lookup fails on create the value
 *   is an empty list; on update the stored list is kept while the learners
 *   are unchanged, so a transient error never hides a visible submission.
 *
 * ADR-031 exception: a cross-schema lookup (Submission to LearnerProfile by a
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
 * @spec openspec/specs/assignments/spec.md#requirement-every-submission-carries-server-stamped-learnerrefs
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
 * Derives Submission.learnerRefs from Submission.learnerIds on every write.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/assignments/spec.md#requirement-every-submission-carries-server-stamped-learnerrefs
 */
class SubmissionLearnerRefsStamp implements IEventListener {

	private const SUBMISSION_SCHEMA = 'submission';

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
	 * Stamp learnerRefs on a Submission create or update.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/assignments/spec.md#requirement-every-submission-carries-server-stamped-learnerrefs
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
			// Not knowing the schema is not knowing it is ours: never touch
			// another app's writes.
			return;
		}

		if ($slug !== self::SUBMISSION_SCHEMA) {
			return;
		}

		$payload = array_merge(($entity->getObject() ?? []), $event->getModifiedData());
		$learnerIds = $this->learnerIdsOf(value: ($payload['learnerIds'] ?? []));

		$stored = [];
		if ($event instanceof ObjectUpdatingEvent === true) {
			$stored = $this->storedRefs(event: $event, learnerIds: $learnerIds);
		}

		$event->setModifiedData(
			array_merge(
				$event->getModifiedData(),
				['learnerRefs' => $this->derive(learnerIds: $learnerIds, stored: $stored)]
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
	 * The learner ids on the submission, as a list of non-empty strings.
	 *
	 * @param mixed $value Raw `learnerIds` value.
	 *
	 * @return array<int, string>
	 */
	private function learnerIdsOf(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$ids = [];
		foreach ($value as $learnerId) {
			if (is_string($learnerId) === true && $learnerId !== '') {
				$ids[] = $learnerId;
			}
		}

		return array_values(array_unique($ids));
	}//end learnerIdsOf()

	/**
	 * The learnerRefs to store: one resolved profile UUID per learner that
	 * has one, and on a lookup error the list already stored (empty on create).
	 *
	 * @param array<int, string> $learnerIds Nextcloud user ids on the submission.
	 * @param array<int, string> $stored learnerRefs already stored, empty on create.
	 *
	 * @return array<int, string>
	 */
	private function derive(array $learnerIds, array $stored): array {
		$refs = [];
		try {
			foreach ($learnerIds as $learnerId) {
				$ref = $this->learnerRefs->resolve(learnerId: $learnerId);
				if ($ref !== null) {
					$refs[] = $ref;
				}
			}
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[SubmissionLearnerRefsStamp] Could not resolve a learner profile, keeping {kept} ref(s): {msg}',
				['kept' => count($stored), 'msg' => $exception->getMessage()]
			);
			return $stored;
		}

		return array_values(array_unique($refs));
	}//end derive()

	/**
	 * The learnerRefs the submission carries before this update. Empty when
	 * the update changes its learners: the old list then names the wrong
	 * profiles, so a failed lookup must fail closed.
	 *
	 * @param ObjectUpdatingEvent $event The update event.
	 * @param array<int, string> $learnerIds The learner ids the submission will carry.
	 *
	 * @return array<int, string>
	 */
	private function storedRefs(ObjectUpdatingEvent $event, array $learnerIds): array {
		$old = $event->getOldObject();
		if ($old === null) {
			return [];
		}

		$oldData = ($old->getObject() ?? []);
		$oldIds = $this->learnerIdsOf(value: ($oldData['learnerIds'] ?? []));
		sort($oldIds);
		$newIds = $learnerIds;
		sort($newIds);
		if ($oldIds !== $newIds) {
			return [];
		}

		return $this->learnerIdsOf(value: ($oldData['learnerRefs'] ?? []));
	}//end storedRefs()
}//end class
