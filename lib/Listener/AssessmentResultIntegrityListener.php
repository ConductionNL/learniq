<?php

/**
 * Learniq AssessmentResult Integrity Listener
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Keeps a learner's attempt trustworthy now that AssessmentResult is no longer
 * `appendOnly` (learniq#948). OpenRegister refuses EVERY update on an
 * append-only schema, lifecycle transitions included, so the flag made it
 * impossible to save answers, submit, write a manual score or grade. This
 * listener replaces the flag with the rules the lifecycle actually needs:
 *
 *   - in-progress: only the learner edits their own attempt, and cannot
 *     score it (no manualScore, no autoScore until the submit save sets them);
 *   - submitted: the answers are frozen; staff may write `manualScore` per
 *     response and fire `grade`, nobody else writes;
 *   - graded: final; only the GradeEntry back-link and a learner merge may
 *     still touch it;
 *   - delete: refused, as appendOnly refused it.
 *
 * Nextcloud admins and system context (no session: background jobs, repair
 * steps) are not policed.
 *
 * ADR-031 legitimate exception: a write rule that compares the stored object
 * with the incoming one, which no schema declaration can express.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Kernel\Contracts\DashboardRoleServiceInterface;
use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Vetoes AssessmentResult writes that would alter a finished attempt.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
 */
class AssessmentResultIntegrityListener implements IEventListener {

	/**
	 * Schema slug this listener guards.
	 */
	private const RESULT_SCHEMA = 'assessment-result';

	/**
	 * Dashboard views that count as staff for scoring.
	 */
	private const STAFF_VIEWS = ['admin', 'teacher'];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolverInterface $schemaResolver Resolves the event entity's schema slug.
	 * @param IUserSession $userSession The acting user.
	 * @param IGroupManager $groupManager Admin check.
	 * @param DashboardRoleServiceInterface $roles Resolves whether the caller is staff.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly DashboardRoleServiceInterface $roles,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an OpenRegister updating or deleting event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectDeletingEvent === true) {
			$this->guardDelete(event: $event);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->guardUpdate(event: $event);
		}
	}//end handle()

	/**
	 * Refuse the delete of an attempt by anyone this listener polices.
	 *
	 * @param ObjectDeletingEvent $event The delete.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
	 */
	private function guardDelete(ObjectDeletingEvent $event): void {
		if ($this->policedUser(entity: $event->getObject()) !== null) {
			$this->reject(
				event: $event,
				reason: 'assessment-result-delete',
				message: 'An assessment attempt is evidence and cannot be deleted.'
			);
		}
	}//end guardDelete()

	/**
	 * Refuse an update the attempt's rules do not allow.
	 *
	 * @param ObjectUpdatingEvent $event The update.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
	 */
	private function guardUpdate(ObjectUpdatingEvent $event): void {
		$user = $this->policedUser(entity: $event->getNewObject());
		if ($user === null) {
			return;
		}

		$old = $event->getOldObject();
		if ($old === null) {
			return;
		}

		$rules = new AssessmentResultRules(old: $old->getObject(), new: $event->getNewObject()->getObject());
		$block = $this->evaluate(user: $user, rules: $rules);
		if ($block !== null) {
			$this->reject(event: $event, reason: $block['reason'], message: $block['message']);
		}
	}//end guardUpdate()

	/**
	 * The acting user when this write must be policed: an AssessmentResult
	 * written by a signed-in user who is not an admin.
	 *
	 * @param object $entity The OpenRegister object being written.
	 *
	 * @return IUser|null The user to police, or null to let the write through.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
	 */
	private function policedUser(object $entity): ?IUser {
		try {
			$slug = $this->schemaResolver->guardSchemaSlug(entity: $entity);
		} catch (Throwable $exception) {
			// Not knowing the schema is not knowing it is ours: never break
			// another app's writes.
			return null;
		}

		$user = $this->userSession->getUser();
		if ($slug !== self::RESULT_SCHEMA || $user === null) {
			return null;
		}

		return $this->unlessAdmin(user: $user);
	}//end policedUser()

	/**
	 * The user, unless they are an admin, whose writes are never policed.
	 *
	 * @param IUser $user The acting user.
	 *
	 * @return IUser|null The user to police, or null for an admin.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
	 */
	private function unlessAdmin(IUser $user): ?IUser {
		if ($this->groupManager->isAdmin($user->getUID()) === true) {
			return null;
		}

		return $user;
	}//end unlessAdmin()

	/**
	 * Decide whether an update is allowed.
	 *
	 * @param IUser                 $user  The acting user (never an admin).
	 * @param AssessmentResultRules $rules The rules over the stored attempt and the attempt as it would be saved.
	 *
	 * @return array{reason: string, message: string}|null The refusal, or null to allow.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
	 */
	private function evaluate(IUser $user, AssessmentResultRules $rules): ?array {
		$state = $rules->storedState();
		if (in_array($state, ['in-progress', ''], true) === true) {
			return $rules->inProgressRefusal(uid: $user->getUID());
		}

		if ($this->isStaff(user: $user) === false) {
			return [
				'reason' => 'assessment-result-finished',
				'message' => 'A submitted attempt can only be scored by a teacher.',
			];
		}

		return $rules->finishedRefusal(state: $state);
	}//end evaluate()

	/**
	 * Whether the caller holds a staff view (teacher or admin dashboard).
	 *
	 * @param IUser $user The acting user.
	 *
	 * @return bool True for staff.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
	 */
	private function isStaff(IUser $user): bool {
		return array_intersect($this->roles->resolveViews(user: $user), self::STAFF_VIEWS) !== [];
	}//end isStaff()

	/**
	 * Refuse the write.
	 *
	 * @param ObjectUpdatingEvent|ObjectDeletingEvent $event The event to stop.
	 * @param string $reason Machine-readable reason.
	 * @param string $message Human-readable message.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
	 */
	private function reject(ObjectUpdatingEvent|ObjectDeletingEvent $event, string $reason, string $message): void {
		$event->setErrors(['reason' => $reason, 'message' => $message]);
		$event->stopPropagation();

		$this->logger->info('[AssessmentResultIntegrityListener] Refused a write: {reason}', ['reason' => $reason]);
	}//end reject()
}//end class
