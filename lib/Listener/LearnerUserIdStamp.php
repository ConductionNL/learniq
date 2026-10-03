<?php

/**
 * Learniq learner user-id Stamp
 *
 * Stamps the learner's Nextcloud user id next to a LearnerProfile uuid on
 * every create or update of the schemas that name their learner by profile:
 *
 * - ExternalTrainingRecord: learnerId      -> learnerUserId
 * - ExemptionCase:          learnerId      -> learnerUserId
 * - FraudCase:              accusedLearnerId -> accusedLearnerUserId
 *
 * Those fields are declared as a LearnerProfile uuid (format uuid, $ref
 * LearnerProfile), not a user id, so a read rule, a notification or a view
 * that compares them with the signed-in user never matches: a learner could
 * not see their own record and was never told of a decision. The user id is
 * written next to the uuid here, on the write path, so every creator is
 * covered: the bulk recorder, the generic form, an import, and the next one.
 *
 * On an ExternalTrainingRecord it also fills an empty `learnerRef` with the
 * same uuid, so the record reaches the learnerRef-scoped reads (the portal
 * and the learning-record aggregation).
 *
 * Posture, mirroring GradeEntryLearnerRefStamp:
 * - the server derives the value; a user id sent by the client is ignored,
 *   so nobody can hand a record to another user;
 * - a profile that does not exist, is merged away or names no user gives
 *   null, so no user matches (fail-closed);
 * - the stamp never blocks a write. When the lookup fails on create the value
 *   is null; on update the stored value is kept while the record stays with
 *   the same learner.
 *
 * ADR-031 exception: a cross-schema lookup (a record to its LearnerProfile)
 * that no schema calculation can express.
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
 * @spec openspec/specs/external-training-recording/spec.md#requirement-verification-must-be-gated-and-tamper-resistant
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
 * Derives a learner's user id from the LearnerProfile uuid on every write.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/external-training-recording/spec.md#requirement-verification-must-be-gated-and-tamper-resistant
 */
class LearnerUserIdStamp implements IEventListener {

	/**
	 * Per schema slug: the LearnerProfile uuid field and the user id field
	 * the stamp writes.
	 */
	public const FIELDS = [
		'external-training-record' => ['learnerId', 'learnerUserId'],
		'exemption-case' => ['learnerId', 'learnerUserId'],
		'fraud-case' => ['accusedLearnerId', 'accusedLearnerUserId'],
	];

	/**
	 * Schemas whose empty `learnerRef` is filled with the profile uuid.
	 */
	private const FILLS_LEARNER_REF = ['external-training-record'];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Entity schema id to slug.
	 * @param LearnerRefResolver     $profiles       LearnerProfile by uuid.
	 * @param LoggerInterface        $logger         PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LearnerRefResolver $profiles,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Stamp the user id on a create or update of one of the mapped schemas.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/external-training-recording/spec.md#requirement-verification-must-be-gated-and-tamper-resistant
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

		if (isset(self::FIELDS[$slug]) === false) {
			return;
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), $this->stampFor(event: $event, entity: $entity, slug: $slug)));
	}//end handle()

	/**
	 * The fields to stamp on one write of a mapped schema: the user id, and
	 * on an ExternalTrainingRecord without one, learnerRef.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The write event.
	 * @param ObjectEntity                            $entity The object being written.
	 * @param string                                  $slug   A key of FIELDS.
	 *
	 * @return array<string, string|null>
	 */
	private function stampFor(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $entity, string $slug): array {
		[$uuidField, $userField] = self::FIELDS[$slug];
		$payload = array_merge(($entity->getObject() ?? []), $event->getModifiedData());
		$profileId = $payload[$uuidField] ?? '';
		if (is_string($profileId) === false) {
			$profileId = '';
		}

		$stored = null;
		if ($event instanceof ObjectUpdatingEvent === true) {
			$stored = $this->storedUserId(event: $event, fields: self::FIELDS[$slug], profileId: $profileId);
		}

		$stamp = [$userField => $this->derive(profileId: $profileId, stored: $stored)];
		if (in_array($slug, self::FILLS_LEARNER_REF, true) === true && $profileId !== '' && ($payload['learnerRef'] ?? '') === '') {
			$stamp['learnerRef'] = $profileId;
		}

		return $stamp;
	}//end stampFor()

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
	 * The user id to store: the profile's ncUserId, null when there is no
	 * such active profile, and on a lookup error the value already stored
	 * (null on create).
	 *
	 * @param string      $profileId The LearnerProfile uuid on the record.
	 * @param string|null $stored    The user id already stored, null on create.
	 *
	 * @return string|null
	 */
	private function derive(string $profileId, ?string $stored): ?string {
		try {
			return $this->profiles->userIdOf(learnerRef: $profileId);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[LearnerUserIdStamp] Could not read learner profile {learner}, keeping {kept}: {msg}',
				['learner' => $profileId, 'kept' => ($stored ?? 'null'), 'msg' => $exception->getMessage()]
			);
			return $stored;
		}
	}//end derive()

	/**
	 * The user id the record carries before this update, or null. Null too
	 * when the update moves the record to another learner: the old value then
	 * names the wrong user, so a failed lookup must fail closed.
	 *
	 * @param ObjectUpdatingEvent $event     The update event.
	 * @param array{0: string, 1: string} $fields The uuid field and the user id field.
	 * @param string              $profileId The profile uuid the record will carry.
	 *
	 * @return string|null
	 */
	private function storedUserId(ObjectUpdatingEvent $event, array $fields, string $profileId): ?string {
		$old = $event->getOldObject();
		if ($old === null) {
			return null;
		}

		$oldData = ($old->getObject() ?? []);
		if (($oldData[$fields[0]] ?? null) !== $profileId) {
			return null;
		}

		$userId = ($oldData[$fields[1]] ?? null);
		if (is_string($userId) === false || $userId === '') {
			return null;
		}

		return $userId;
	}//end storedUserId()
}//end class
