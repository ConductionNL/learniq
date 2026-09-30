<?php

/**
 * Learniq Data Correction Request Stamp
 *
 * Pre-write listener for the DataCorrectionRequest schema. On a create it
 * stamps the requester from the session, the grade's current value from
 * the grade entry, and the requested state, and drops any decision fields
 * the caller sent. On an update it puts back what the request asked for
 * (grade entry, proposed value, reason, current value, requester), so an
 * approver approves exactly what was asked and nobody rewrites it after.
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
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-audit-of-corrections
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
use OCP\IUserSession;
use Throwable;

/**
 * Stamps the requester and the value before the change on a correction
 * request, and keeps the request as asked on every later write.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-audit-of-corrections
 */
class DataCorrectionRequestStamp implements IEventListener {

	private const SCHEMA = 'data-correction-request';

	/**
	 * What the requester asked for; no later write changes it.
	 */
	private const REQUEST_FIELDS = ['gradeEntryId', 'proposedValue', 'reason', 'currentValue', 'requestedBy'];

	/**
	 * Fields only the decision and the application set.
	 */
	private const LATER_FIELDS = ['decisionNote', 'decidedBy', 'decidedAt', 'appliedBy', 'appliedAt'];

	/**
	 * Grade entry states a correction can be asked for: approved data.
	 */
	private const CORRECTABLE = ['published', 'revised'];

	/**
	 * The refusals this listener can give, keyed by reason.
	 */
	private const REFUSALS = [
		'correction-no-session'     => 'Sign in to ask for a correction.',
		'correction-no-entry'       => 'The grade this correction is for can not be found.',
		'correction-not-published'  => 'A correction is for a published grade. A grade that is not published yet can be changed directly.',
		'correction-request-lost'   => 'This correction has lost who asked for it and can not be changed.',
	];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Entity schema id to slug.
	 * @param ObjectService          $objects        OpenRegister object access.
	 * @param IUserSession           $userSession    The signed-in user.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly ObjectService $objects,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Stamp the request on a create, keep it on an update.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#scenario-an-auditor-reads-the-trail
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		if ($event->isPropagationStopped() === true) {
			return;
		}

		try {
			$slug = $this->schemaResolver->guardSchemaSlug(entity: $this->entityOf(event: $event));
		} catch (Throwable) {
			// Not knowing the schema is not knowing it is ours.
			return;
		}

		if ($slug !== self::SCHEMA) {
			return;
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->keepRequest(event: $event);
			return;
		}

		$this->stampRequest(event: $event);
	}//end handle()

	/**
	 * Stamp a new request: the session user asks, the value before the change
	 * comes from the grade entry, the state is requested, no decision comes in.
	 *
	 * @param ObjectCreatingEvent $event The create event.
	 *
	 * @return void
	 */
	private function stampRequest(ObjectCreatingEvent $event): void {
		$userId = (string)($this->userSession->getUser()?->getUID() ?? '');
		if ($userId === '') {
			$this->refuse(event: $event, reason: 'correction-no-session');
			return;
		}

		$entryId = (string)($event->getObject()->getObject()['gradeEntryId'] ?? '');
		$entry   = $this->gradeEntry(entryId: $entryId);
		if ($entry === null) {
			$this->refuse(event: $event, reason: 'correction-no-entry');
			return;
		}

		if (in_array(($entry['lifecycle'] ?? null), self::CORRECTABLE, true) === false) {
			$this->refuse(event: $event, reason: 'correction-not-published');
			return;
		}

		$event->setModifiedData(
			array_merge(
				$event->getModifiedData(),
				array_fill_keys(self::LATER_FIELDS, null),
				[
					'requestedBy'  => $userId,
					'currentValue' => ($entry['value'] ?? null),
					'lifecycle'    => 'requested',
				]
			)
		);
	}//end stampRequest()

	/**
	 * The grade entry, read with the requester's own rights: a correction can
	 * only be asked for a grade the requester can see.
	 *
	 * @param string $entryId The grade entry uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function gradeEntry(string $entryId): ?array {
		if ($entryId === '') {
			return null;
		}

		try {
			return $this->objects->find(id: $entryId, register: 'learniq', schema: 'grade-entry')?->jsonSerialize();
		} catch (Throwable) {
			return null;
		}
	}//end gradeEntry()

	/**
	 * Put the stored request back over whatever the update sends.
	 *
	 * @param ObjectUpdatingEvent $event The update event.
	 *
	 * @return void
	 */
	private function keepRequest(ObjectUpdatingEvent $event): void {
		$old = ($event->getOldObject()?->getObject() ?? []);
		if (is_string($old['requestedBy'] ?? null) === false || $old['requestedBy'] === '') {
			$this->refuse(event: $event, reason: 'correction-request-lost');
			return;
		}

		$kept = [];
		foreach (self::REQUEST_FIELDS as $field) {
			$kept[$field] = ($old[$field] ?? null);
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), $kept));
	}//end keepRequest()

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

	/**
	 * Stop the write with a reason the client can show.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The write event.
	 * @param string                                  $reason A key of REFUSALS.
	 *
	 * @return void
	 */
	private function refuse(ObjectCreatingEvent|ObjectUpdatingEvent $event, string $reason): void {
		$event->setErrors(['reason' => $reason, 'message' => self::REFUSALS[$reason]]);
		$event->stopPropagation();
	}//end refuse()
}//end class
