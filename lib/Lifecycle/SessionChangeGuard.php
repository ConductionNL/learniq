<?php

/**
 * Learniq Session Change Guard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Lifecycle guard for the Session schema's `cancel`, `substitute-teacher`,
 * and `substitute-teacher-in-progress` transitions (timetabling-and-
 * substitution). Refuses the transition unless BOTH hold:
 *   1. Authorization: the caller (resolved server-side via the transition
 *      context's actor — never a client-supplied claim) is listed in the
 *      Session's Cohort.teacherIds, OR is a member of the admin/coordinator
 *      NC group — mirrors MunicipalityFeedbackGuard/ReportPeriodLockGuard's
 *      IGroupManager-based role check.
 *   2. Required fields: `changeReasonKind` MUST be set for both transitions
 *      ("no cancellation without a reason" — the same class of structural
 *      invariant as BsaDecisionGuard); `substituteTeacherId` MUST
 *      additionally be set for substitute-teacher/substitute-teacher-in-
 *      progress.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run
 * before a state transition and cannot be expressed as a schema
 * declaration." Referenced from Session.x-openregister-lifecycle.
 * transitions.{cancel,substitute-teacher,substitute-teacher-in-progress}.
 * requires in learniq_register.json.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/timetabling/spec.md#requirement-substitution-and-cancellation-require-a-reason-and-are-gated-by-sessionchangeguard
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\Learniq\Kernel\Contracts\SessionChangeGuardInterface;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Guards the Session `cancel` / `substitute-teacher` / `substitute-teacher-in-progress` transitions.
 *
 * @spec openspec/specs/timetabling/spec.md#requirement-substitution-and-cancellation-require-a-reason-and-are-gated-by-sessionchangeguard
 */
class SessionChangeGuard implements LifecycleGuardInterface, SessionChangeGuardInterface {

	/**
	 * Reason shown to the caller when the transition is refused.
	 *
	 * @var string
	 */
	private const DENIAL = 'This session change needs a reason, a substitute where required, and a caller who may change the session.';

	private const LEARNIQ_REGISTER = 'learniq';
	private const COHORT_SCHEMA = 'cohort';

	/**
	 * Transition action names that additionally require `substituteTeacherId`
	 * to be set (beyond the `changeReasonKind` requirement shared by every
	 * gated transition).
	 *
	 * @var string[]
	 */
	private const SUBSTITUTE_ACTIONS = ['substitute-teacher', 'substitute-teacher-in-progress'];

	/**
	 * NC groups whose members may cancel/substitute any Session, independent
	 * of Cohort.teacherIds membership.
	 *
	 * @var string[]
	 */
	private const OVERRIDE_GROUPS = ['admin', 'coordinators'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 * @param IGroupManager $groupManager OR/NC group manager to resolve the acting user's role groups.
	 * @param IUserManager $userManager User manager to resolve the acting user object for membership checks.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Authorise or deny the transition this guard is named on (LifecycleGuardInterface).
	 *
	 * @param array<string,mixed> $object The object at its target state, transition inputs merged in.
	 * @param string $action The transition action being applied.
	 * @param string $userId The uid of the caller.
	 *
	 * @return GuardResult Allow, or deny with the reason shown to the caller.
	 *
	 * @spec openspec/specs/timetabling/spec.md#scenario-a-cohort-teacher-cancels-a-session-with-a-reason
	 * @spec openspec/specs/timetabling/spec.md#scenario-cancelling-without-a-reason-is-refused
	 * @spec openspec/specs/timetabling/spec.md#scenario-a-teacher-outside-the-cohort-cannot-substitute-or-cancel
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($this->allows(change: new SessionChangeRequest(object: $object, action: $action), userId: $userId) === true) {
			return GuardResult::allow();
		}

		return GuardResult::deny(self::DENIAL);
	}//end check()

	/**
	 * The rule behind check(), answered as a boolean.
	 *
	 * @param SessionChangeRequest $change The session at its target state and the action.
	 * @param string               $userId The uid of the caller.
	 *
	 * @return bool True when the transition is allowed; false blocks it.
	 *
	 * @spec openspec/specs/timetabling/spec.md#scenario-a-cohort-teacher-cancels-a-session-with-a-reason
	 * @spec openspec/specs/timetabling/spec.md#scenario-cancelling-without-a-reason-is-refused
	 * @spec openspec/specs/timetabling/spec.md#scenario-a-teacher-outside-the-cohort-cannot-substitute-or-cancel
	 */
	private function allows(SessionChangeRequest $change, string $userId): bool {
		if ($userId === '') {
			$this->logger->info('[SessionChangeGuard] No acting user — blocking {a}.', ['a' => $change->action]);
			return false;
		}

		if ($this->hasReason(change: $change) === false || $this->hasSubstitute(change: $change) === false) {
			return false;
		}

		if ($this->actorIsOverrideRole(actor: $userId) === true) {
			return true;
		}

		return $this->isCohortTeacher(change: $change, actor: $userId);
	}//end allows()

	/**
	 * Whether the change names its reason; a missing one is logged.
	 *
	 * @param SessionChangeRequest $change The session and the action.
	 *
	 * @return bool
	 */
	private function hasReason(SessionChangeRequest $change): bool {
		if ($this->isNonEmptyString(value: ($change->object['changeReasonKind'] ?? null)) === false) {
			$this->logger->info(
				'[SessionChangeGuard] {a} refused — changeReasonKind is not set.',
				['a' => $change->action]
			);
			return false;
		}

		return true;
	}//end hasReason()

	/**
	 * Whether the change names the substitute its action requires; a missing one is logged.
	 *
	 * @param SessionChangeRequest $change The session and the action.
	 *
	 * @return bool
	 */
	private function hasSubstitute(SessionChangeRequest $change): bool {
		if ($this->hasRequiredSubstitute(object: $change->object, action: $change->action) === false) {
			$this->logger->info(
				'[SessionChangeGuard] {a} refused — substituteTeacherId is not set.',
				['a' => $change->action]
			);
			return false;
		}

		return true;
	}//end hasSubstitute()

	/**
	 * Whether the actor teaches the session's cohort; a refusal is logged.
	 *
	 * @param SessionChangeRequest $change The session and the action.
	 * @param string               $actor  The uid of the caller.
	 *
	 * @return bool
	 */
	private function isCohortTeacher(SessionChangeRequest $change, string $actor): bool {
		$cohort = $this->cohortOf(change: $change);
		if ($cohort === null) {
			return false;
		}

		if ($this->listsTeacher(cohort: $cohort, actor: $actor) === true) {
			return true;
		}

		$this->logger->info(
			'[SessionChangeGuard] Actor {u} is neither a Cohort.teacherIds member nor admin/coordinator; blocking {a}.',
			['u' => $actor, 'a' => $change->action]
		);
		return false;
	}//end isCohortTeacher()

	/**
	 * The session's cohort, tenant-scoped, or null (logged) when the session
	 * names none or it cannot be found: the guard fails closed.
	 *
	 * @param SessionChangeRequest $change The session and the action.
	 *
	 * @return array<string,mixed>|null
	 */
	private function cohortOf(SessionChangeRequest $change): ?array {
		$cohortId = (string)($change->object['cohortId'] ?? '');
		if ($cohortId === '') {
			$this->logger->warning('[SessionChangeGuard] Session has no cohortId; blocking {a}.', ['a' => $change->action]);
			return null;
		}

		$cohort = $this->loadCohort(cohortId: $cohortId, tenantId: (string)($change->object['tenant_id'] ?? ''));
		if ($cohort === null) {
			$this->logger->warning(
				'[SessionChangeGuard] Cohort {c} not found for Session; blocking {a} (fail closed).',
				['c' => $cohortId, 'a' => $change->action]
			);
		}

		return $cohort;
	}//end cohortOf()

	/**
	 * Whether a cohort's teacher list holds the actor.
	 *
	 * @param array<string,mixed> $cohort The cohort.
	 * @param string              $actor  The uid of the caller.
	 *
	 * @return bool
	 */
	private function listsTeacher(array $cohort, string $actor): bool {
		$teacherIds = $cohort['teacherIds'] ?? [];

		return is_array($teacherIds) === true && in_array($actor, $teacherIds, true) === true;
	}//end listsTeacher()

	/**
	 * Whether a context value is present as a non-empty string.
	 *
	 * The lifecycle context is untyped, so 'set' has to mean 'a string with
	 * something in it' rather than merely 'not null'.
	 *
	 * @param mixed $value The value from the transition context or object.
	 *
	 * @return bool True when the value is a non-empty string.
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-substitution-and-cancellation-require-a-reason-and-are-gated-by-sessionchangeguard
	 */
	private function isNonEmptyString(mixed $value): bool {
		return (is_string($value) === true && $value !== '');
	}//end isNonEmptyString()

	/**
	 * Whether the transition carries the substitute teacher it requires.
	 *
	 * Only the substitute actions demand one; every other action is unaffected
	 * and passes this check trivially.
	 *
	 * @param array<string,mixed> $object The Session being transitioned.
	 * @param string $action The transition name.
	 *
	 * @return bool True when the action does not need a substitute, or names one.
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-substitution-and-cancellation-require-a-reason-and-are-gated-by-sessionchangeguard
	 */
	private function hasRequiredSubstitute(array $object, string $action): bool {
		if (in_array($action, self::SUBSTITUTE_ACTIONS, true) === false) {
			return true;
		}

		return $this->isNonEmptyString(value: ($object['substituteTeacherId'] ?? null));
	}//end hasRequiredSubstitute()

	/**
	 * Whether the actor is a member of one of OVERRIDE_GROUPS.
	 *
	 * @param string $actor NC user ID of the requester.
	 *
	 * @return bool True when the user is in admin/coordinator.
	 */
	private function actorIsOverrideRole(string $actor): bool {
		$groups = new ActorGroups(userManager: $this->userManager, groupManager: $this->groupManager);

		return $groups->isInAny(actor: $actor, groups: self::OVERRIDE_GROUPS);
	}//end actorIsOverrideRole()

	/**
	 * Load a Cohort by UUID, tenant-scoped.
	 *
	 * @param string $cohortId UUID of the Cohort.
	 * @param string $tenantId Tenant ID to enforce as a mandatory filter.
	 *
	 * @return array<string,mixed>|null The cohort data, or null when not found.
	 */
	private function loadCohort(string $cohortId, string $tenantId): ?array {
		$filters = [];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$results = $this->objectService->findAll(
			[
				'ids' => [$cohortId],
				'filters' => array_merge(
					$filters,
					[
						'register' => self::LEARNIQ_REGISTER,
						'schema' => self::COHORT_SCHEMA,
					]
				),
				'limit' => 1,
			]
		);

		if (empty($results) === true) {
			return null;
		}

		$cohort = $results[0];
		if (is_array($cohort) === false) {
			$cohort = $cohort->jsonSerialize();
		}

		return $cohort;
	}//end loadCohort()
}//end class
