<?php

/**
 * Learniq Subject Choice Consent Stamp
 *
 * Decides, on the server, who gave the guardian consent on a subject choice.
 *
 * When `guardianConsentGiven` is true, `guardianConsentBy` is the signed-in
 * user who gave it: the stored value when consent was already given, else the
 * caller. When consent is not given, `guardianConsentBy` is cleared. A write
 * without a signed-in user keeps the stored value.
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
 * @spec openspec/specs/school-structure/spec.md#requirement-guardian-consent-gates-a-minor-s-subject-choice-submission
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Throwable;

/**
 * Stamps `guardianConsentBy` on every write of a SubjectChoice.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/school-structure/spec.md#requirement-guardian-consent-gates-a-minor-s-subject-choice-submission
 */
class SubjectChoiceConsentStamp implements IEventListener {

	private const SCHEMA = 'subject-choice';

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Entity schema id to slug.
	 * @param IUserSession           $userSession    The signed-in user.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Stamp who gave the consent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-guardian-consent-gates-a-minor-s-subject-choice-submission
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		if ($event->isPropagationStopped() === true) {
			return;
		}

		$entity = $this->entityOf(event: $event);
		$old = [];
		if ($event instanceof ObjectUpdatingEvent === true) {
			$old = ($event->getOldObject()?->getObject() ?? []);
		}

		try {
			if ($this->schemaResolver->guardSchemaSlug(entity: $entity) !== self::SCHEMA) {
				return;
			}
		} catch (Throwable $exception) {
			return;
		}

		$payload = array_merge(($entity->getObject() ?? []), $event->getModifiedData());
		$event->setModifiedData(
			array_merge($event->getModifiedData(), ['guardianConsentBy' => $this->consentBy(payload: $payload, old: $old)])
		);
	}//end handle()

	/**
	 * Who gave the consent this write stores.
	 *
	 * @param array<string, mixed> $payload The choice being written.
	 * @param array<string, mixed> $old     The stored choice, empty on create.
	 *
	 * @return string|null
	 */
	private function consentBy(array $payload, array $old): ?string {
		$stored = ($old['guardianConsentBy'] ?? null);
		if (is_string($stored) === false || $stored === '') {
			$stored = null;
		}

		if (($payload['guardianConsentGiven'] ?? false) !== true) {
			return null;
		}

		$userId = $this->userSession->getUser()?->getUID();
		if ($userId === null || $userId === '') {
			return $stored;
		}

		if (($old['guardianConsentGiven'] ?? false) === true && $stored !== null) {
			return $stored;
		}

		return $userId;
	}//end consentBy()

	/**
	 * The object being written: an update carries it in getNewObject(), only
	 * a create has getObject().
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
