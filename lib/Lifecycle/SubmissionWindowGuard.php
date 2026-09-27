<?php

/**
 * Learniq Submission Window Guard
 *
 * Lifecycle guard for the Submission schema's two hand-in transitions. Enforces the
 * Assignment's submission window: `submit` (draft to submitted) only inside the
 * window, `submitLate` (draft to late) only after it and only when the Assignment
 * allows late work. A guard can not redirect the target state (learniq#983), so late
 * hand-in is its own transition.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run before
 * a state transition and cannot be expressed as a schema declaration." Requires a
 * cross-schema query (Submission → Assignment) and datetime comparison.
 * Referenced from the Submission schema's submit and submitLate transitions
 * in learniq_register.json.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-9
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

/**
 * Guards the Submission `submit` and `submitLate` transitions.
 *
 * Behaviour matrix:
 * - caller not in learnerIds (and not admin or system) -> deny both.
 * - dueAt is null  -> `submit` allowed, `submitLate` denied (there is no late).
 * - now <= dueAt   -> `submit` allowed, `submitLate` denied.
 * - now > dueAt    -> `submit` denied; `submitLate` allowed only with allowLateSubmission.
 */
class SubmissionWindowGuard implements LifecycleGuardInterface {

	/**
	 * OR register slug for Learniq objects.
	 */
	private const LEARNIQ_REGISTER = 'learniq';

	/**
	 * The late hand-in transition.
	 */
	public const LATE_ACTION = 'submitLate';

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService OR object service for fetching the parent Assignment.
	 * @param LoggerInterface $logger        PSR logger.
	 * @param IGroupManager   $groupManager  Resolves whether the caller is an administrator.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Authorise or deny a hand-in (LifecycleGuardInterface).
	 *
	 * @param array<string,mixed> $object The Submission at its target state.
	 * @param string              $action `submit` or `submitLate`.
	 * @param string              $userId The uid of the caller, '' for a system call.
	 *
	 * @return GuardResult Allow, or deny with the reason shown to the caller.
	 *
	 * @spec openspec/specs/assignments/spec.md#requirement-a-learner-hands-in-their-own-work-and-the-teacher-marks-it
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$assignmentId = $object['assignmentId'] ?? null;
		if ($assignmentId === null || $assignmentId === '') {
			return GuardResult::deny('This submission is not linked to an assignment.');
		}

		if ($this->callerMayHandIn(object: $object, userId: $userId) === false) {
			$this->logger->info(
				'[SubmissionWindowGuard] Caller {uid} is not one of the learners of Submission {id}; blocking {action}.',
				['uid' => $userId, 'id' => ($object['id'] ?? ''), 'action' => $action]
			);
			return GuardResult::deny('Only the learners this submission belongs to can hand it in.');
		}

		$assignment = $this->loadAssignment(assignmentId: (string)$assignmentId, tenantId: (string)($object['tenant_id'] ?? ''));
		if ($assignment === null) {
			return GuardResult::deny('The assignment of this submission could not be found.');
		}

		return $this->windowVerdict(assignment: $assignment, late: $action === self::LATE_ACTION);
	}//end check()

	/**
	 * Judge the hand-in against the Assignment's deadline.
	 *
	 * @param array<string,mixed> $assignment The Assignment.
	 * @param bool                $late       Whether this is the `submitLate` transition.
	 *
	 * @return GuardResult Allow, or deny with the reason shown to the caller.
	 */
	private function windowVerdict(array $assignment, bool $late): GuardResult {
		$window = $this->windowState(assignment: $assignment);

		if ($window === 'malformed') {
			return GuardResult::deny('The deadline of this assignment could not be read.');
		}

		if ($window === 'open') {
			if ($late === true) {
				return GuardResult::deny('The deadline has not passed, so hand the work in normally.');
			}

			return GuardResult::allow();
		}

		// The deadline has passed.
		if ((bool)($assignment['allowLateSubmission'] ?? false) === false) {
			return GuardResult::deny('The deadline has passed and this assignment does not accept late work.');
		}

		if ($late === false) {
			return GuardResult::deny('The deadline has passed, so this work can only be handed in late.');
		}

		return GuardResult::allow();
	}//end windowVerdict()

	/**
	 * Load the parent Assignment, scoped to the Submission's tenant.
	 *
	 * @param string $assignmentId The Assignment UUID.
	 * @param string $tenantId     The tenant UUID, '' when unscoped.
	 *
	 * @return array<string,mixed>|null The Assignment, or null when not found.
	 */
	private function loadAssignment(string $assignmentId, string $tenantId): ?array {
		// H1: scope Assignment lookup to the same tenant.
		$filters = ['uuid' => $assignmentId];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$assignments = $this->objectService->findAll(
			[
				'register' => self::LEARNIQ_REGISTER,
				'schema' => 'assignment',
				'filters' => $filters,
				'limit' => 1,
			]
		);

		if (empty($assignments) === true) {
			$this->logger->info('[SubmissionWindowGuard] Assignment {id} not found; blocking hand-in.', ['id' => $assignmentId]);
			return null;
		}

		$assignment = $assignments[0];
		if (is_array($assignment) === false) {
			$assignment = $assignment->jsonSerialize();
		}

		return $assignment;
	}//end loadAssignment()

	/**
	 * Whether the Assignment's window is open, closed, or unreadable.
	 *
	 * @param array<string,mixed> $assignment The Assignment.
	 *
	 * @return string `open` (no deadline, or not yet passed), `closed`, or `malformed`.
	 */
	private function windowState(array $assignment): string {
		$dueAtRaw = $assignment['dueAt'] ?? null;
		if ($dueAtRaw === null || $dueAtRaw === '') {
			// Open-ended assignment: no deadline to enforce.
			return 'open';
		}

		// #202: explicit UTC for both timestamps so DST transitions do not skew the
		// comparison. #219: a malformed dueAt is a refusal, not an unhandled 500.
		try {
			$dueAt = new DateTimeImmutable((string)$dueAtRaw, new DateTimeZone('UTC'));
		} catch (\Exception $e) {
			$this->logger->warning('[SubmissionWindowGuard] Malformed dueAt {due}; blocking hand-in.', ['due' => $dueAtRaw]);
			return 'malformed';
		}

		if (new DateTimeImmutable('now', new DateTimeZone('UTC')) <= $dueAt) {
			return 'open';
		}

		return 'closed';
	}//end windowState()

	/**
	 * Whether the caller may hand this submission in.
	 *
	 * Any signed-in user may create a Submission (OpenRegister checks create
	 * without the object, so the schema cannot narrow it), so the hand-in is
	 * where a submission made in someone else's name is refused: the caller
	 * must be one of its learnerIds. Administrators and system calls (no
	 * caller) are not refused.
	 *
	 * @param array<string, mixed> $object The Submission data.
	 * @param string               $userId The uid of the caller, '' for a system call.
	 *
	 * @return bool True when the caller may submit.
	 *
	 * @spec openspec/specs/assignments/spec.md#requirement-a-learner-hands-in-their-own-work-and-the-teacher-marks-it
	 */
	private function callerMayHandIn(array $object, string $userId): bool {
		if ($userId === '' || $this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		$learnerIds = ($object['learnerIds'] ?? []);

		return is_array($learnerIds) === true && in_array($userId, $learnerIds, true) === true;
	}//end callerMayHandIn()
}//end class
