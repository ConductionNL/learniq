<?php

/**
 * Learniq Proctoring Flag Review Guard
 *
 * Only staff decide a proctoring flag, and the server records who did. A
 * learner holds an update right on their own active `proctoring-session`,
 * because native test mode appends flags from the learner's browser, so the
 * schema's authorization cannot keep them away from `reviewDecision`. This
 * pre-write veto hands the stored and incoming `flags` to FlagReview, refuses
 * the write when it says so, and otherwise writes the flags back with
 * `reviewedBy` and `reviewedAt` set by the server.
 *
 * Staff are `instructors` and `compliance-officers`. A write with no user
 * (the system, a provider adapter) or by an admin is not checked, as in the
 * other pre-write listeners. OpenRegister runs no lifecycle guard on a plain
 * update, which is why this is a listener (the ElectiveSignUpRules pattern)
 * and not a transition guard.
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
 * @spec openspec/changes/proctoring-flag-review-page/specs/assessment/spec.md#requirement-only-staff-decide-a-flag-and-the-server-records-who-did
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Proctoring\FlagReview;
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
 * Refuses a flag decision by anyone but staff and stamps who decided.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/proctoring-flag-review-page/specs/assessment/spec.md#requirement-only-staff-decide-a-flag-and-the-server-records-who-did
 */
class ProctoringFlagReviewGuard implements IEventListener {

	/**
	 * Schema slug this listener guards.
	 */
	public const SCHEMA = 'proctoring-session';

	/**
	 * Groups that decide flags.
	 */
	public const STAFF_GROUPS = ['instructors', 'compliance-officers'];

	/**
	 * Constructor.
	 *
	 * @param FlagReview             $rules          The flag rules.
	 * @param ListenerSchemaResolver $schemaResolver Which schema an event is about.
	 * @param IUserSession           $userSession    The writer.
	 * @param IGroupManager          $groupManager   Admin and staff checks.
	 */
	public function __construct(
		private readonly FlagReview $rules,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Check the flags of a proctoring session before it is written.
	 *
	 * @param Event $event The creating or updating event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/proctoring-flag-review-page/specs/assessment/spec.md#requirement-only-staff-decide-a-flag-and-the-server-records-who-did
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$entity = $this->entityOf(event: $event);
		$uid = $this->policedUid(event: $event, entity: $entity);
		if ($uid === null) {
			return;
		}

		$stored = [];
		if ($event instanceof ObjectUpdatingEvent) {
			$stored = $this->flagsOf(object: ($event->getOldObject()?->getObject() ?? []));
		}

		$incoming = $this->flagsOf(object: ($entity->getObject() ?? []));
		$result = $this->rules->review(
			stored: $stored,
			incoming: $incoming,
			uid: $uid,
			staff: $this->isStaff(uid: $uid),
			now: new DateTimeImmutable('now', new DateTimeZone('UTC'))
		);

		if (is_string($result) === true) {
			$event->setErrors(['reason' => 'proctoring-flag-review', 'message' => $result]);
			$event->stopPropagation();
			return;
		}

		if ($incoming !== [] || $stored !== []) {
			$event->setModifiedData(array_merge($event->getModifiedData(), ['flags' => $result]));
		}
	}//end handle()

	/**
	 * The writer to police: a signed-in non-admin writing a proctoring session.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The event.
	 * @param ObjectEntity                            $entity The object being written.
	 *
	 * @return string|null The writer's uid, or null to let the write through.
	 */
	private function policedUid(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $entity): ?string {
		if ($event->isPropagationStopped() === true) {
			return null;
		}

		try {
			$slug = $this->schemaResolver->guardSchemaSlug(entity: $entity);
		} catch (Throwable $exception) {
			// Not knowing the schema is not knowing it is ours: never touch
			// another app's writes.
			return null;
		}

		$user = $this->userSession->getUser();
		if ($slug !== self::SCHEMA || $user === null || $this->groupManager->isAdmin($user->getUID()) === true) {
			return null;
		}

		return $user->getUID();
	}//end policedUid()

	/**
	 * The flags of a session payload, keeping only the entries that are objects.
	 *
	 * @param array<string, mixed> $object The session payload.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function flagsOf(array $object): array {
		$flags = ($object['flags'] ?? []);
		if (is_array($flags) === false) {
			return [];
		}

		return array_values(array_filter($flags, static fn ($flag): bool => is_array($flag)));
	}//end flagsOf()

	/**
	 * Whether the writer is in one of the groups that decide flags.
	 *
	 * @param string $uid The writer.
	 *
	 * @return bool
	 */
	private function isStaff(string $uid): bool {
		foreach (self::STAFF_GROUPS as $group) {
			if ($this->groupManager->isInGroup($uid, $group) === true) {
				return true;
			}
		}

		return false;
	}//end isStaff()

	/**
	 * The object an event carries: the new object of an update.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The event.
	 *
	 * @return ObjectEntity
	 */
	private function entityOf(ObjectCreatingEvent|ObjectUpdatingEvent $event): ObjectEntity {
		if ($event instanceof ObjectUpdatingEvent) {
			return $event->getNewObject();
		}

		return $event->getObject();
	}//end entityOf()
}//end class
