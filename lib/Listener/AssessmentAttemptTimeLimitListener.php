<?php

/**
 * Learniq Assessment Attempt Time Limit Listener
 *
 * Holds a learner's attempt on the in-app test screen to its time limit, on
 * the server, the way the portal endpoints hold a portal attempt to it
 * (in-app-test-limits-server-side):
 *
 * - `startedAt` and `attemptNumber` are set by AssessmentAttemptGateListener
 *   when the attempt starts; the learner cannot move them, because the time
 *   limit runs from startedAt;
 * - after the deadline plus the grace (with the learner's extra time) the
 *   answers stop changing. The save, a hand-in included, goes through with
 *   the answers that were stored in time, so a late hand-in still closes
 *   the attempt. Scores the server adds to unchanged answers (the submit
 *   save) are kept.
 *
 * The deadline, the grace and the extra time come from
 * AssessmentAttemptLimits, which uses the portal's PortalAttemptClock and
 * PortalAttemptReader. Nextcloud admins and system context (no session) are
 * not held to it, as with AssessmentResultIntegrityListener.
 *
 * ADR-031 legitimate exception: a write rule that compares the stored object
 * with the incoming one against the clock, which no schema declaration can
 * express.
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
 * @spec openspec/specs/assessment/spec.md#requirement-the-in-app-test-screen-enforces-attempts-and-time-on-the-server
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\AssessmentAttemptLimits;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps an attempt's start fixed and its answers inside the time limit.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/assessment/spec.md#requirement-the-in-app-test-screen-enforces-attempts-and-time-on-the-server
 */
class AssessmentAttemptTimeLimitListener implements IEventListener {

	private const RESULT_SCHEMA = 'assessment-result';

	/**
	 * Fields the attempt gate sets and a learner never moves.
	 */
	private const START_FIELDS = ['startedAt', 'attemptNumber', 'deadlineAt'];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Resolves the event entity's schema slug.
	 * @param IUserSession $userSession The acting user.
	 * @param IGroupManager $groupManager Admin check.
	 * @param AssessmentAttemptLimits $limits The time limit, as the portal endpoints apply it.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly AssessmentAttemptLimits $limits,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an OpenRegister updating event on an AssessmentResult.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-the-in-app-test-screen-enforces-attempts-and-time-on-the-server
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatingEvent === false || $event->isPropagationStopped() === true) {
			return;
		}

		$uid = $this->learner(event: $event);
		$old = $event->getOldObject();
		if ($uid === null || $old === null) {
			return;
		}

		$oldData = ($old->getObject() ?? []);
		$newData = ($event->getNewObject()->getObject() ?? []);

		if ($this->startMoved(old: $oldData, new: $newData) === true) {
			$event->setErrors(
				[
					'reason' => 'assessment-result-start-fixed',
					'message' => 'When an attempt started, its number and its deadline are set by the server.',
				]
			);
			$event->stopPropagation();
			return;
		}

		if ($this->limits->answersLate(old: $oldData, new: $newData, uid: $uid) === true) {
			$event->setModifiedData(array_merge($event->getModifiedData(), ['responses' => ($oldData['responses'] ?? [])]));
			$this->logger->info(
				'[AssessmentAttemptTimeLimitListener] Attempt {id} is past its deadline; its answers were not changed.',
				['id' => ($oldData['id'] ?? ($oldData['uuid'] ?? ''))]
			);
		}
	}//end handle()

	/**
	 * The acting user when this write is held to the rules: an AssessmentResult
	 * written by a signed-in user who is not an admin.
	 *
	 * @param ObjectUpdatingEvent $event The update event.
	 *
	 * @return string|null The user id, or null to let the write through.
	 */
	private function learner(ObjectUpdatingEvent $event): ?string {
		try {
			$slug = $this->schemaResolver->guardSchemaSlug(entity: $event->getNewObject());
		} catch (Throwable $exception) {
			// Not knowing the schema is not knowing it is ours: never break
			// another app's writes.
			return null;
		}

		$user = $this->userSession->getUser();
		if ($slug !== self::RESULT_SCHEMA || $user === null || $this->groupManager->isAdmin($user->getUID()) === true) {
			return null;
		}

		return $user->getUID();
	}//end learner()

	/**
	 * Whether an update on an attempt in progress moves its start or number.
	 *
	 * @param array<string, mixed> $old The stored attempt.
	 * @param array<string, mixed> $new The attempt as it would be saved.
	 *
	 * @return bool
	 */
	private function startMoved(array $old, array $new): bool {
		if (in_array((string)($old['lifecycle'] ?? ''), ['in-progress', ''], true) === false) {
			return false;
		}

		foreach (self::START_FIELDS as $field) {
			if (array_key_exists($field, $new) === true && (string)($new[$field] ?? '') !== (string)($old[$field] ?? '')) {
				return true;
			}
		}

		return false;
	}//end startMoved()
}//end class
