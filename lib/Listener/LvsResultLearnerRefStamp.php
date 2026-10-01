<?php

/**
 * Learniq LvsResult learnerRef Stamp
 *
 * Stamps `learnerRef` (the LearnerProfile UUID, the domain reference to the
 * pupil) onto every LvsResult as it is created or updated, derived from
 * `learnerId` (the Nextcloud user id).
 *
 * Two paths create an LvsResult: the import landing for an integriq
 * `lvs-results` job (a Cito, IEP, Boom or Dia delivery) and a result entered
 * by hand. Neither set `learnerRef`, so a Cito result was linked to its pupil
 * only through the user id. Stamping on the write path covers both, and the
 * next one.
 *
 * Posture, mirroring GradeEntryLearnerRefStamp:
 * - the server derives the value; a `learnerRef` sent by the client is
 *   ignored, so nobody can file a result under another pupil;
 * - a learner without a profile gets `learnerRef: null` (fail-closed);
 * - the stamp never blocks a write. When the lookup fails on create the
 *   value is null; on update the stored value is kept while the result stays
 *   with the same learner.
 *
 * The import runs inside integriq's background job, without a session, so the
 * profile is looked up in the tenant the result carries (`tenant_id`), not in
 * the session's tenant. A result without a tenant falls back to the session.
 *
 * ADR-031 exception: a cross-schema lookup (LvsResult to LearnerProfile by a
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
 * @spec openspec/specs/data-exchange/spec.md#requirement-every-lvsresult-names-its-pupil-by-learnerprofile-reference
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
 * Derives LvsResult.learnerRef from LvsResult.learnerId on every write.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/data-exchange/spec.md#requirement-every-lvsresult-names-its-pupil-by-learnerprofile-reference
 */
class LvsResultLearnerRefStamp implements IEventListener {

	private const LVS_SCHEMA = 'lvs-result';

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
	 * Stamp learnerRef on an LvsResult create or update.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-every-lvsresult-names-its-pupil-by-learnerprofile-reference
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

		if ($slug !== self::LVS_SCHEMA) {
			return;
		}

		$payload = array_merge(($entity->getObject() ?? []), $event->getModifiedData());
		$learnerId = $this->stringOf(value: ($payload['learnerId'] ?? ''));
		$tenantId = $this->stringOf(value: ($payload['tenant_id'] ?? ''));

		if ($event instanceof ObjectUpdatingEvent === true) {
			$stored = $this->storedRef(event: $event, learnerId: $learnerId);
		}

		$event->setModifiedData(
			array_merge(
				$event->getModifiedData(),
				['learnerRef' => $this->derive(learnerId: $learnerId, tenantId: $tenantId, stored: $stored)]
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
	 * A payload value as a string, empty when it is not one.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function stringOf(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return $value;
	}//end stringOf()

	/**
	 * The learnerRef to store: the resolved profile UUID, null when there is
	 * none, and on a lookup error the value already stored (null on create).
	 *
	 * @param string $learnerId Nextcloud user id on the result.
	 * @param string $tenantId The tenant the result belongs to, empty when unknown.
	 * @param string|null $stored learnerRef already stored, null on create.
	 *
	 * @return string|null
	 */
	private function derive(string $learnerId, string $tenantId, ?string $stored): ?string {
		try {
			if ($tenantId !== '') {
				return $this->learnerRefs->resolveInTenant(learnerId: $learnerId, tenantId: $tenantId);
			}

			return $this->learnerRefs->resolve(learnerId: $learnerId);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[LvsResultLearnerRefStamp] Could not resolve the learner profile, keeping {kept}: {msg}',
				['kept' => ($stored ?? 'null'), 'msg' => $exception->getMessage()]
			);
			return $stored;
		}
	}//end derive()

	/**
	 * The learnerRef the result carries before this update, or null. Null too
	 * when the update moves the result to another learner: the old value then
	 * names the wrong profile, so a failed lookup must fail closed.
	 *
	 * @param ObjectUpdatingEvent $event The update event.
	 * @param string $learnerId The learnerId the result will carry.
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
