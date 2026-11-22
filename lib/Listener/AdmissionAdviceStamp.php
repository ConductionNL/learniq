<?php

/**
 * Learniq Admission Advice Stamp
 *
 * A review panel member's advice on an application (`admission-advice`) is
 * given by that member and nobody else. On create and update this listener:
 *
 * - refuses advice from anyone not on the application's round panel
 *   (`AdmissionsRound.panelMemberIds`);
 * - refuses advice once the application has left `intake-completed`, so the
 *   advice a decision rested on stays as it was;
 * - refuses a second advice by the same member on one application, and a
 *   change by anyone but the member who gave it;
 * - writes `memberId` and `memberName` from the session, whatever the client
 *   sent, and the application's tenant when the client sent none.
 *
 * A rule across rows (the application, its round, earlier advice), so a
 * pre-write veto (ADR-031 guard exception). A system write (no user) is let
 * through.
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
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#requirement-a-review-panel-decides-an-application-together
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Learniq\Lifecycle\ReviewPanelDecision;
use OCA\Learniq\Service\AdmissionPanelReads;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lets only the round's panel members give advice, once each, before the decision.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#requirement-a-review-panel-decides-an-application-together
 */
class AdmissionAdviceStamp implements IEventListener {

	private const ADVICE_SCHEMA = 'admission-advice';

	/**
	 * The application state in which the panel gives its advice.
	 */
	private const AWAITING_DECISION = 'intake-completed';

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolverInterface $schemaResolver Resolves the event entity's schema slug.
	 * @param IUserSession                    $userSession    The acting user.
	 * @param AdmissionPanelReads             $reads          Reads the application, its round and earlier advice.
	 * @param LoggerInterface                 $logger         PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly IUserSession $userSession,
		private readonly AdmissionPanelReads $reads,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an OpenRegister creating or updating event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#requirement-a-review-panel-decides-an-application-together
	 */
	public function handle(Event $event): void {
		$isWrite = ($event instanceof ObjectCreatingEvent || $event instanceof ObjectUpdatingEvent);
		$user = $this->userSession->getUser();
		if ($isWrite === false || $user === null) {
			// Not a write, or a system write (import, repair) with nobody to police.
			return;
		}

		$entity = (new WrittenObject())->entityOf(event: $event);
		if ($this->isAdvice(entity: $entity) === false) {
			return;
		}

		$change = ['advice' => ($entity->getObject() ?? []), 'previous' => null];
		if ($event instanceof ObjectUpdatingEvent === true) {
			$change['previous'] = ($event->getOldObject()?->getObject() ?? []);
		}

		$refusal = $this->refusal(uid: $user->getUID(), change: $change);
		if ($refusal !== null) {
			$event->setErrors(['reason' => $refusal[0], 'message' => $refusal[1]]);
			$event->stopPropagation();
			$this->logger->info('[AdmissionAdviceStamp] Refused advice by {uid}: {reason}.', ['uid' => $user->getUID(), 'reason' => $refusal[0]]);
			return;
		}

		$name = $user->getDisplayName();
		if ($name === '') {
			$name = $user->getUID();
		}

		$entity->setObject($this->stamped(advice: $change['advice'], member: ['id' => $user->getUID(), 'name' => $name]));
	}//end handle()

	/**
	 * Why this advice may not be written, as [reason code, sentence], or null.
	 *
	 * @param string                                                                 $uid    The caller.
	 * @param array{advice: array<string,mixed>, previous: array<string,mixed>|null} $change The advice to store, and the stored one on an update.
	 *
	 * @return array{0: string, 1: string}|null
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#requirement-a-review-panel-decides-an-application-together
	 */
	private function refusal(string $uid, array $change): ?array {
		$applicationId = (string)($change['advice']['applicationId'] ?? '');
		if ($change['previous'] !== null) {
			$ownRefusal = $this->updateRefusal(uid: $uid, change: $change);
			if ($ownRefusal !== null) {
				return $ownRefusal;
			}
		}

		$application = $this->reads->object(schema: 'admission', id: $applicationId);
		if ($application === null) {
			return ['admission-advice-unknown-application', 'This advice names no application that exists.'];
		}

		if ((string)($application['lifecycle'] ?? '') !== self::AWAITING_DECISION) {
			return ['admission-advice-not-awaiting-decision', 'Advice is given while the application waits for its decision, after the intake.'];
		}

		$round = $this->reads->object(schema: 'admissions-round', id: (string)($application['admissionsRoundId'] ?? ''));
		$members = (new ReviewPanelDecision(panelMemberIds: ($round['panelMemberIds'] ?? [])))->members();
		if (in_array($uid, $members, true) === false) {
			return ['admission-advice-not-on-panel', 'Only the members of this round\'s review panel give advice.'];
		}

		if ($change['previous'] === null && $this->hasAdvised(uid: $uid, applicationId: $applicationId) === true) {
			return ['admission-advice-already-given', 'You already gave your advice on this application. Change that advice instead.'];
		}

		return null;
	}//end refusal()

	/**
	 * On an update: only the member who gave the advice changes it, and it stays on its application.
	 *
	 * @param string                                                                 $uid    The caller.
	 * @param array{advice: array<string,mixed>, previous: array<string,mixed>|null} $change The new and the stored advice.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private function updateRefusal(string $uid, array $change): ?array {
		$previous = ($change['previous'] ?? []);
		$owner = (string)($previous['memberId'] ?? '');
		if ($owner !== '' && $owner !== $uid) {
			return ['admission-advice-not-yours', 'Only the panel member who gave this advice can change it.'];
		}

		$previousApplication = (string)($previous['applicationId'] ?? '');
		if ($previousApplication !== '' && $previousApplication !== (string)($change['advice']['applicationId'] ?? '')) {
			return ['admission-advice-other-application', 'Advice stays on the application it was given for.'];
		}

		return null;
	}//end updateRefusal()

	/**
	 * Whether the member already gave advice on this application.
	 *
	 * @param string $uid           The member.
	 * @param string $applicationId The application.
	 *
	 * @return bool
	 */
	private function hasAdvised(string $uid, string $applicationId): bool {
		foreach ($this->reads->adviceOn(applicationId: $applicationId) as $row) {
			if ((string)($row['memberId'] ?? '') === $uid) {
				return true;
			}
		}

		return false;
	}//end hasAdvised()

	/**
	 * The advice with the member written by the server, and the tenant of its application when none was sent.
	 *
	 * @param array<string,mixed>              $advice The advice.
	 * @param array{id: string, name: string} $member The member: user id and display name.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#requirement-a-review-panel-decides-an-application-together
	 */
	private function stamped(array $advice, array $member): array {
		$advice['memberId'] = $member['id'];
		$advice['memberName'] = $member['name'];

		if ((string)($advice['tenant_id'] ?? '') === '') {
			$application = $this->reads->object(schema: 'admission', id: (string)($advice['applicationId'] ?? ''));
			$tenant = (string)($application['tenant_id'] ?? '');
			if ($tenant !== '') {
				$advice['tenant_id'] = $tenant;
			}
		}

		return $advice;
	}//end stamped()

	/**
	 * Whether the entity is an admission advice.
	 *
	 * @param ObjectEntity $entity The object being written.
	 *
	 * @return bool
	 */
	private function isAdvice(ObjectEntity $entity): bool {
		try {
			return $this->schemaResolver->guardSchemaSlug(entity: $entity) === self::ADVICE_SCHEMA;
		} catch (Throwable $exception) {
			// Not knowing the schema is not knowing it is ours: never break another app's writes.
			return false;
		}
	}//end isAdvice()
}//end class
