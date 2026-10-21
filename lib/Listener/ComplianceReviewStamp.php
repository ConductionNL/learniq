<?php

/**
 * Learniq ComplianceReviewStamp
 *
 * Writes the audit fields of the Compliance record on the server: who saved it
 * last and when, and the date the processing agreement was signed. The admin
 * page sends none of them, and a value it does send is replaced.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md#scenario-a-compliance-officer-records-the-signed-privacyconvenant-agreement
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use DateTimeImmutable;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Throwable;

/**
 * Stamps lastReviewedBy/At on every write of the Compliance record, and keeps
 * privacyconvenantSignedAt at the moment the agreement was first marked signed.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md#scenario-a-compliance-officer-records-the-signed-privacyconvenant-agreement
 */
class ComplianceReviewStamp implements IEventListener {

	private const SCHEMA = 'compliance';

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Entity schema id to slug.
	 * @param IUserSession           $userSession    The person saving.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Stamp the review and agreement fields of a Compliance write.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md#scenario-a-compliance-officer-records-the-signed-privacyconvenant-agreement
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$entity = $this->entityOf(event: $event);
		if ($event->isPropagationStopped() === true || $this->isCompliance(entity: $entity) === false) {
			return;
		}

		$payload = array_merge(($entity->getObject() ?? []), $event->getModifiedData());
		$before  = [];
		if ($event instanceof ObjectUpdatingEvent === true && $event->getOldObject() !== null) {
			$before = ($event->getOldObject()->getObject() ?? []);
		}

		$now   = (new DateTimeImmutable())->format('Y-m-d\TH:i:sP');
		$stamp = [
			'lastReviewedBy' => ($this->userSession->getUser()?->getUID()),
			'lastReviewedAt' => $now,
			'privacyconvenantSignedAt' => $this->signedAt(payload: $payload, before: $before, now: $now),
		];

		$event->setModifiedData(array_merge($event->getModifiedData(), $stamp));
	}//end handle()

	/**
	 * When the agreement was signed: kept while it stays signed, now when it
	 * becomes signed, and null while it is not signed.
	 *
	 * @param array<string, mixed> $payload The record as it will be saved.
	 * @param array<string, mixed> $before  The record as stored, or [] on a create.
	 * @param string               $now     The current moment.
	 *
	 * @return string|null
	 */
	private function signedAt(array $payload, array $before, string $now): ?string {
		if (($payload['privacyconvenantSigned'] ?? false) !== true) {
			return null;
		}

		$stored = ($before['privacyconvenantSignedAt'] ?? null);
		if (($before['privacyconvenantSigned'] ?? false) === true && is_string($stored) === true && $stored !== '') {
			return $stored;
		}

		return $now;
	}//end signedAt()

	/**
	 * Whether the entity being written is the Compliance record.
	 *
	 * @param ObjectEntity $entity The entity.
	 *
	 * @return bool
	 */
	private function isCompliance(ObjectEntity $entity): bool {
		try {
			return $this->schemaResolver->guardSchemaSlug(entity: $entity) === self::SCHEMA;
		} catch (Throwable $exception) {
			// Not knowing the schema is not knowing it is ours.
			return false;
		}
	}//end isCompliance()

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
}//end class
