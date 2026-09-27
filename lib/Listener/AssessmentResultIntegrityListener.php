<?php

/**
 * Learniq AssessmentResult Integrity Listener
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
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\DashboardRoleService;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Db\ObjectEntity;
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
	 * Fields a finished attempt never changes. `learnerId` is absent on purpose:
	 * a learner merge re-points it (LearnerMergeService), and only staff get
	 * past the finished-attempt check at all.
	 */
	private const FROZEN_FIELDS = [
		'assessmentId',
		'attemptNumber',
		'drawnItemRefs',
		'startedAt',
		'submittedAt',
		'proctoringSessionId',
		'tenant_id',
	];

	/**
	 * Refusal for a change to a finished attempt's answers.
	 */
	private const FROZEN = [
		'reason' => 'assessment-result-answers-frozen',
		'message' => 'The answers of a submitted attempt cannot be changed.',
	];

	/**
	 * Refusal for any scoring or state change after grading.
	 */
	private const GRADED = [
		'reason' => 'assessment-result-graded',
		'message' => 'A graded attempt is final.',
	];

	/**
	 * Fields a learner never changes on their own attempt.
	 */
	private const OWNER_FIELDS = ['assessmentId', 'learnerId', 'tenant_id'];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Resolves the event entity's schema slug.
	 * @param IUserSession $userSession The acting user.
	 * @param IGroupManager $groupManager Admin check.
	 * @param DashboardRoleService $roles Resolves whether the caller is staff.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly DashboardRoleService $roles,
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
			if ($this->policedUser(entity: $event->getObject()) !== null) {
				$this->reject(
					event: $event,
					reason: 'assessment-result-delete',
					message: 'An assessment attempt is evidence and cannot be deleted.'
				);
			}

			return;
		}

		if ($event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$user = $this->policedUser(entity: $event->getNewObject());
		if ($user === null) {
			return;
		}

		$old = $event->getOldObject();
		if ($old === null) {
			return;
		}

		$block = $this->evaluate(
			user: $user,
			old: $old->getObject(),
			new: $event->getNewObject()->getObject()
		);
		if ($block !== null) {
			$this->reject(event: $event, reason: $block['reason'], message: $block['message']);
		}
	}//end handle()

	/**
	 * The acting user when this write must be policed: an AssessmentResult
	 * written by a signed-in user who is not an admin.
	 *
	 * @param ObjectEntity $entity The object being written.
	 *
	 * @return IUser|null The user to police, or null to let the write through.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
	 */
	private function policedUser(ObjectEntity $entity): ?IUser {
		try {
			$slug = $this->schemaResolver->guardSchemaSlug(entity: $entity);
		} catch (Throwable $exception) {
			// Not knowing the schema is not knowing it is ours: never break
			// another app's writes.
			return null;
		}

		$user = $this->userSession->getUser();
		if ($slug !== self::RESULT_SCHEMA || $user === null || $this->groupManager->isAdmin($user->getUID()) === true) {
			return null;
		}

		return $user;
	}//end policedUser()

	/**
	 * Decide whether an update is allowed.
	 *
	 * @param IUser $user The acting user (never an admin).
	 * @param array<string,mixed> $old The stored attempt.
	 * @param array<string,mixed> $new The attempt as it would be saved.
	 *
	 * @return array{reason: string, message: string}|null The refusal, or null to allow.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
	 */
	private function evaluate(IUser $user, array $old, array $new): ?array {
		$state = (string)($old['lifecycle'] ?? '');
		if (in_array($state, ['in-progress', ''], true) === true) {
			return $this->evaluateInProgress(uid: $user->getUID(), old: $old, new: $new);
		}

		if ($this->isStaff(user: $user) === false) {
			return [
				'reason' => 'assessment-result-finished',
				'message' => 'A submitted attempt can only be scored by a teacher.',
			];
		}

		return $this->evaluateFinished(state: $state, old: $old, new: $new);
	}//end evaluate()

	/**
	 * Rules for an attempt the learner is still taking.
	 *
	 * @param string $uid The acting user id.
	 * @param array<string,mixed> $old The stored attempt.
	 * @param array<string,mixed> $new The attempt as it would be saved.
	 *
	 * @return array{reason: string, message: string}|null The refusal, or null to allow.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
	 */
	private function evaluateInProgress(string $uid, array $old, array $new): ?array {
		if ((string)($old['learnerId'] ?? '') !== $uid || $this->changed(old: $old, new: $new, fields: self::OWNER_FIELDS) === true) {
			return [
				'reason' => 'assessment-result-not-yours',
				'message' => 'Only the learner taking this attempt can change it.',
			];
		}

		// The submit save is where AssessmentScoringHandler sets autoScores, so
		// only a save that stays in progress must carry no scores at all.
		$submitting = (($new['lifecycle'] ?? '') === 'submitted');
		foreach ($this->responses(data: $new) as $response) {
			$selfScored = (($response['manualScore'] ?? null) !== null)
				|| ($submitting === false && ($response['autoScore'] ?? null) !== null);
			if ($selfScored === true) {
				return [
					'reason' => 'assessment-result-self-scored',
					'message' => 'A learner cannot score their own answers.',
				];
			}
		}

		return null;
	}//end evaluateInProgress()

	/**
	 * Rules for a submitted or graded attempt, written by staff.
	 *
	 * @param string $state The stored lifecycle state.
	 * @param array<string,mixed> $old The stored attempt.
	 * @param array<string,mixed> $new The attempt as it would be saved.
	 *
	 * @return array{reason: string, message: string}|null The refusal, or null to allow.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
	 */
	private function evaluateFinished(string $state, array $old, array $new): ?array {
		if ($this->changed(old: $old, new: $new, fields: self::FROZEN_FIELDS) === true) {
			return self::FROZEN;
		}

		$oldResponses = $this->responses(data: $old);
		$newResponses = $this->responses(data: $new);
		if (count($oldResponses) !== count($newResponses)) {
			return self::FROZEN;
		}

		foreach ($oldResponses as $index => $oldResponse) {
			$block = $this->evaluateResponse(state: $state, old: $oldResponse, new: ($newResponses[$index] ?? []));
			if ($block !== null) {
				return $block;
			}
		}

		$newState = (string)($new['lifecycle'] ?? $state);
		if ($newState !== $state && ($state !== 'submitted' || $newState !== 'graded')) {
			return self::GRADED;
		}

		return null;
	}//end evaluateFinished()

	/**
	 * Rules for one response of a finished attempt: only manualScore may
	 * change, only while submitted, and only to a number of zero or more.
	 *
	 * @param string $state The stored lifecycle state.
	 * @param array<string,mixed> $old The stored response.
	 * @param array<string,mixed> $new The response as it would be saved.
	 *
	 * @return array{reason: string, message: string}|null The refusal, or null to allow.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
	 */
	private function evaluateResponse(string $state, array $old, array $new): ?array {
		$oldManual = ($old['manualScore'] ?? null);
		$newManual = ($new['manualScore'] ?? null);
		unset($old['manualScore'], $new['manualScore']);

		if ($this->same(left: $old, right: $new) === false) {
			return self::FROZEN;
		}

		if ($this->same(left: $oldManual, right: $newManual) === true) {
			return null;
		}

		if ($state !== 'submitted') {
			return self::GRADED;
		}

		if ($newManual !== null && (is_numeric($newManual) === false || (float)$newManual < 0)) {
			return [
				'reason' => 'assessment-result-invalid-score',
				'message' => 'A score must be a number of zero or more.',
			];
		}

		return null;
	}//end evaluateResponse()

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
	 * The responses array of an attempt, re-indexed.
	 *
	 * @param array<string,mixed> $data The attempt.
	 *
	 * @return array<int,array<string,mixed>> The responses.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
	 */
	private function responses(array $data): array {
		return array_values(array_filter((array)($data['responses'] ?? []), 'is_array'));
	}//end responses()

	/**
	 * Whether any of the named fields differs between two versions.
	 *
	 * @param array<string,mixed> $old The stored attempt.
	 * @param array<string,mixed> $new The attempt as it would be saved.
	 * @param array<int,string> $fields The fields to compare.
	 *
	 * @return bool True when at least one differs.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
	 */
	private function changed(array $old, array $new, array $fields): bool {
		foreach ($fields as $field) {
			if ($this->same(left: ($old[$field] ?? null), right: ($new[$field] ?? null)) === false) {
				return true;
			}
		}

		return false;
	}//end changed()

	/**
	 * Compare two stored values the way they round-trip through JSON, so an
	 * integer 3 and a float 3.0, or reordered object keys, are the same value.
	 *
	 * @param mixed $left One value.
	 * @param mixed $right The other value.
	 *
	 * @return bool True when equal.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
	 */
	private function same(mixed $left, mixed $right): bool {
		return $this->normalise(value: $left) === $this->normalise(value: $right);
	}//end same()

	/**
	 * Normalise a value for comparison: numbers as floats, maps key-sorted.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The normalised value.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
	 */
	private function normalise(mixed $value): mixed {
		if (in_array(gettype($value), ['integer', 'double'], true) === true) {
			return (float)$value;
		}

		if (is_array($value) === false) {
			return $value;
		}

		// Sorting a list by key keeps its order, so one ksort covers maps and lists.
		$normalised = array_map(fn (mixed $item): mixed => $this->normalise(value: $item), $value);
		ksort($normalised);

		return $normalised;
	}//end normalise()

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
