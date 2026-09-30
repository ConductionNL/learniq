<?php

/**
 * Learniq regulation exemption request stamp.
 *
 * A regulation exemption is requested by a compliance officer, or by a team
 * lead for one of their own direct reports (the learner's profile names them
 * as `managerId`). The server decides who asked: on create the requester is
 * the session user whatever the client sends, the request starts in
 * `requested`, and no decision fields come in with it. On update the stored
 * requester is put back, so nobody can make themselves eligible to grant
 * their own request by rewriting it.
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
 * @spec openspec/changes/compliance-exemption-record/specs/compliance-exemptions/spec.md#requirement-regulation-exemption-records
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
use OCP\IGroupManager;
use OCP\IUserSession;
use Throwable;

/**
 * Stamps the requester of a RegulationExemption and scopes a team lead's
 * request to their direct reports.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/compliance-exemption-record/specs/compliance-exemptions/spec.md#requirement-regulation-exemption-records
 */
class RegulationExemptionRequestStamp implements IEventListener {

	private const SCHEMA = 'regulation-exemption';

	/**
	 * Groups that may request an exemption for anyone; everyone else only for their direct reports.
	 */
	private const OFFICER_GROUPS = ['admin', 'compliance-officers'];

	/**
	 * Fields only the decision sets; a request never carries them.
	 */
	private const DECISION_FIELDS = ['decisionRationale', 'policyReference', 'decidedBy', 'decidedAt', 'validFrom', 'validUntil'];

	/**
	 * The refusals this listener can give, keyed by reason.
	 */
	private const REFUSALS = [
		'exemption-no-session' => 'Sign in to request an exemption.',
		'exemption-not-your-report' => 'You can request an exemption only for someone who reports to you.',
		'exemption-requester-missing' => 'This exemption has lost who requested it and can not be changed.',
	];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Entity schema id to slug.
	 * @param LearnerRefResolver     $profiles       LearnerProfile by uuid.
	 * @param IUserSession           $userSession    The signed-in user.
	 * @param IGroupManager          $groupManager   Group membership.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LearnerRefResolver $profiles,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Stamp the requester on a create, restore it on an update.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-exemption-record/specs/compliance-exemptions/spec.md#scenario-a-manager-requests-an-exemption
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
			$this->keepRequester(event: $event);
			return;
		}

		$userId = (string)($this->userSession->getUser()?->getUID() ?? '');
		if ($userId === '') {
			$this->refuse(event: $event, reason: 'exemption-no-session');
			return;
		}

		$learnerId = (string)($event->getObject()->getObject()['learnerId'] ?? '');
		if ($this->isOfficer(userId: $userId) === false && $this->managesLearner(userId: $userId, learnerId: $learnerId) === false) {
			$this->refuse(event: $event, reason: 'exemption-not-your-report');
			return;
		}

		$event->setModifiedData(
			array_merge(
				$event->getModifiedData(),
				array_fill_keys(self::DECISION_FIELDS, null),
				['requestedBy' => $userId, 'lifecycle' => 'requested']
			)
		);
	}//end handle()

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
	 * Whether the user may request an exemption for anyone.
	 *
	 * @param string $userId Nextcloud user id.
	 *
	 * @return bool
	 */
	private function isOfficer(string $userId): bool {
		foreach (self::OFFICER_GROUPS as $group) {
			if ($this->groupManager->isInGroup($userId, $group) === true) {
				return true;
			}
		}

		return false;
	}//end isOfficer()

	/**
	 * Whether the learner's profile names the user as their manager. A failed
	 * lookup answers no: the request is refused rather than let through.
	 *
	 * @param string $userId    Nextcloud user id of the requester.
	 * @param string $learnerId LearnerProfile uuid.
	 *
	 * @return bool
	 */
	private function managesLearner(string $userId, string $learnerId): bool {
		try {
			$profile = $this->profiles->byRef(learnerRef: $learnerId);
		} catch (Throwable) {
			return false;
		}

		return ($profile['managerId'] ?? null) === $userId;
	}//end managesLearner()

	/**
	 * Put the stored requester back over whatever the update sends.
	 *
	 * @param ObjectUpdatingEvent $event The update event.
	 *
	 * @return void
	 */
	private function keepRequester(ObjectUpdatingEvent $event): void {
		$old = ($event->getOldObject()?->getObject() ?? []);
		$requester = ($old['requestedBy'] ?? '');
		if (is_string($requester) === false || $requester === '') {
			$this->refuse(event: $event, reason: 'exemption-requester-missing');
			return;
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), ['requestedBy' => $requester]));
	}//end keepRequester()

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
