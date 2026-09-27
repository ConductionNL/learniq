<?php

/**
 * Learniq Submission Window Guard
 *
 * Lifecycle guard for the Submission schema's `submit` transition. Enforces the
 * Assignment's submission window: after dueAt, submission is blocked (HTTP 422) unless
 * allowLateSubmission is true, in which case the target lifecycle state is redirected
 * to `late` via the transitionContext.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run before
 * a state transition and cannot be expressed as a schema declaration." Requires a
 * cross-schema query (Submission → Assignment) and datetime comparison.
 * Referenced from the Submission schema's x-openregister-lifecycle.transitions.submit.requires
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
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Guards the Submission `submit` transition.
 *
 * Behaviour matrix:
 * - dueAt is null → always allow (open-ended assignment).
 * - now <= dueAt  → allow; `to` stays `submitted`.
 * - now > dueAt + allowLateSubmission=false → block (return false).
 * - now > dueAt + allowLateSubmission=true  → redirect `to` to `late` and allow.
 */
class SubmissionWindowGuard {

	/**
	 * OR register slug for Learniq objects.
	 */
	private const LEARNIQ_REGISTER = 'learniq';

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService OR object service for fetching the parent Assignment.
	 * @param LoggerInterface $logger        PSR logger.
	 * @param IUserSession    $userSession   Current session (server-resolved caller identity).
	 * @param IGroupManager   $groupManager  Resolves whether the caller is an administrator.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * OR lifecycle guard entry-point.
	 *
	 * Called by OpenRegister's lifecycle engine before executing the `submit`
	 * transition on a Submission object. Looks up the parent Assignment to check
	 * whether the submission window is still open.
	 *
	 * When the deadline has passed and late submission is allowed, this guard mutates
	 * $transitionContext['to'] = 'late' so OpenRegister lands the Submission in the
	 * `late` state rather than `submitted`.
	 *
	 * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
	 *                                               - 'object'     : the Submission data array
	 *                                               - 'transition' : 'submit'
	 *                                               - 'from'       : 'draft'
	 *                                               - 'to'         : 'submitted' (may be mutated to 'late')
	 *
	 * @return bool True to allow the transition; false blocks it (HTTP 422 from OR engine).
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-9
	 */
	public function check(array &$transitionContext): bool {
		$object = $transitionContext['object'] ?? [];
		$assignmentId = $object['assignmentId'] ?? null;
		$tenantId = $object['tenant_id'] ?? '';

		if ($assignmentId === null || $this->callerMayHandIn(object: $object) === false) {
			$this->logger->info(
				'[SubmissionWindowGuard] Submission {id} has no assignmentId or the caller is not one of its learners; blocking submit.',
				['id' => ($object['id'] ?? '')]
			);
			return false;
		}

		// H1: scope Assignment lookup to the same tenant.
		$assignmentFilters = ['uuid' => $assignmentId];
		if ($tenantId !== '') {
			$assignmentFilters['tenant_id'] = $tenantId;
		}

		$assignments = $this->objectService->findAll(
			[
				'register' => self::LEARNIQ_REGISTER,
				'schema' => 'assignment',
				'filters' => $assignmentFilters,
				'limit' => 1,
			]
		);

		if (empty($assignments) === true) {
			$this->logger->info(
				'[SubmissionWindowGuard] Assignment {id} not found; blocking submit.',
				['id' => $assignmentId]
			);
			return false;
		}

		$assignment = $assignments[0];
		$dueAtRaw = $assignment['dueAt'] ?? null;

		if ($dueAtRaw === null) {
			// Open-ended assignment — no deadline to enforce.
			return true;
		}

		// #202: use explicit UTC timezone for both timestamps so DST transitions on the
		// server do not cause inconsistent deadline comparisons. Stored dueAt values must
		// include a timezone offset (ISO 8601); if they don't we default to UTC.
		// #219: wrap DateTimeImmutable construction in a try/catch to surface malformed
		// dueAt values as a guard rejection rather than an unhandled 500.
		try {
			$dueAt = new DateTimeImmutable($dueAtRaw, new DateTimeZone('UTC'));
		} catch (\Exception $e) {
			$this->logger->warning(
				'[SubmissionWindowGuard] Assignment {id} has malformed dueAt value; blocking submit.',
				['id' => $assignmentId]
			);
			return false;
		}

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		if ($now <= $dueAt) {
			// Within the window — normal submit.
			return true;
		}

		$allowLate = (bool)($assignment['allowLateSubmission'] ?? false);

		if ($allowLate === false) {
			$this->logger->info(
				'[SubmissionWindowGuard] Submission after dueAt and late submission not allowed; blocking.'
			);
			return false;
		}

		// Past deadline but late submission is allowed → redirect lifecycle target to `late`.
		$transitionContext['to'] = 'late';
		$this->logger->info(
			'[SubmissionWindowGuard] Submission after dueAt; redirecting lifecycle to `late`.'
		);

		return true;
	}//end check()
	/**
	 * Whether the caller may hand this submission in.
	 *
	 * Any signed-in user may create a Submission (OpenRegister checks create
	 * without the object, so the schema cannot narrow it), so the hand-in is
	 * where a submission made in someone else's name is refused: the caller
	 * must be one of its learnerIds. Administrators and system calls (no
	 * session) are not refused.
	 *
	 * @param array<string, mixed> $object The Submission data.
	 *
	 * @return bool True when the caller may submit.
	 *
	 * @spec openspec/specs/assignments/spec.md#requirement-a-learner-hands-in-their-own-work-and-the-teacher-marks-it
	 */
	private function callerMayHandIn(array $object): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return true;
		}

		$uid = $user->getUID();
		if ($this->groupManager->isAdmin($uid) === true) {
			return true;
		}

		$learnerIds = ($object['learnerIds'] ?? []);

		return is_array($learnerIds) === true && in_array($uid, $learnerIds, true) === true;
	}//end callerMayHandIn()
}//end class
