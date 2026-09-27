<?php

/**
 * Learniq Submission resubmission date listener
 *
 * Keeps `Submission.resubmissionDueAt` in staff hands. The date moves the
 * hand-in deadline (SubmissionWindowGuard judges the window against it), and a
 * learner may both create a Submission and update their own draft. Without
 * this listener a pupil could give themselves any date and hand in "on time"
 * forever.
 *
 * Posture: never refuses a write. For a caller outside the staff groups the
 * value is dropped on create and put back to the stored one on update, so the
 * rest of the learner's write goes through. Staff, admins and system context
 * (no user) write it freely; the `reopen` transition, which only staff may
 * fire, is how it is set.
 *
 * ADR-031 exception: a per-field write rule the register cannot express
 * (`x-property-rbac` is documentation only here).
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
 * @spec openspec/changes/submission-resubmission-action/specs/assignments/spec.md#requirement-only-staff-set-a-resubmission-date
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lets only staff write Submission.resubmissionDueAt.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/submission-resubmission-action/specs/assignments/spec.md#requirement-only-staff-set-a-resubmission-date
 */
class SubmissionResubmissionDateListener implements IEventListener {

	private const SUBMISSION_SCHEMA = 'submission';
	private const FIELD = 'resubmissionDueAt';

	/**
	 * Groups that may set a resubmission date: the groups that may reopen.
	 */
	private const STAFF_GROUPS = ['instructors', 'compliance-officers', 'team-leads'];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Entity schema id to slug.
	 * @param IUserSession $userSession The signed-in user, if any.
	 * @param IGroupManager $groupManager Group membership and admin checks.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Drop or restore a resubmission date written by someone outside staff.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/submission-resubmission-action/specs/assignments/spec.md#requirement-only-staff-set-a-resubmission-date
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		if ($event->isPropagationStopped() === true) {
			return;
		}

		$entity = $this->entityOf(event: $event);
		$uid = $this->policedUid(entity: $entity);
		if ($uid === null) {
			return;
		}

		$written = array_merge(($entity->getObject() ?? []), $event->getModifiedData());
		$stored = null;
		if ($event instanceof ObjectUpdatingEvent === true) {
			$stored = (($event->getOldObject()?->getObject() ?? [])[self::FIELD] ?? null);
		}

		if (($written[self::FIELD] ?? null) === $stored) {
			return;
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), [self::FIELD => $stored]));
		$this->logger->info(
			'[SubmissionResubmissionDateListener] Kept the resubmission date out of a write by {uid}.',
			['uid' => $uid]
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
	 * The caller's uid when this is a Submission write by someone outside
	 * staff, else null (not a Submission, system context, admin or staff).
	 *
	 * @param ObjectEntity $entity The object being written.
	 *
	 * @return string|null
	 */
	private function policedUid(ObjectEntity $entity): ?string {
		try {
			$slug = $this->schemaResolver->guardSchemaSlug(entity: $entity);
		} catch (Throwable $exception) {
			// Not knowing the schema is not knowing it is ours: never touch
			// another app's writes.
			return null;
		}

		$user = $this->userSession->getUser();
		if ($slug !== self::SUBMISSION_SCHEMA || $user === null || $this->groupManager->isAdmin($user->getUID()) === true) {
			return null;
		}

		foreach (self::STAFF_GROUPS as $group) {
			if ($this->groupManager->isInGroup($user->getUID(), $group) === true) {
				return null;
			}
		}

		return $user->getUID();
	}//end policedUid()
}//end class
