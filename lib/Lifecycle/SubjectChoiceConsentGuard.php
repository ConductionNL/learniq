<?php

/**
 * Learniq Subject Choice Consent Guard
 *
 * Lifecycle guard for the SubjectChoice schema's `submit` transition
 * (`draft → submitted`). Enforces that the caller is authorised to submit a
 * vakkenpakket for the named learner: either the caller's NC user id is
 * listed in the target LearnerProfile's `parentIds` (a linked guardian), or
 * the caller's NC user id equals the LearnerProfile's own `ncUserId` (an 18+
 * learner submitting for themselves).
 *
 * This is a deliberate reapplication, not a reimplementation:
 * design.md "SubjectChoiceConsentGuard is a genuine lifecycle guard ... its
 * body is copy-identical to ConferenceSignupGuardianGuard" — the identical
 * guardian-authorization rule already proven for conference sign-ups
 * (`lib/Lifecycle/ConferenceSignupGuardianGuard.php`), reapplied to a new
 * schema rather than a second authorization mechanism being invented.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run
 * before a state transition and cannot be expressed as a schema
 * declaration." The check needs the *caller's* identity (resolved
 * server-side via IUserSession, never a client-supplied claim).
 *
 * Referenced from SubjectChoice's
 * x-openregister-lifecycle.transitions.submit.requires in
 * learniq_register.json.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
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
 * @spec openspec/specs/school-structure/spec.md#requirement-guardian-consent-gates-a-minor-s-subject-choice-submission
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Guards the SubjectChoice `submit` transition.
 *
 * Passes only when the caller is a linked guardian of the target learner
 * (caller's NC user id in LearnerProfile.parentIds) or the caller IS the
 * target learner (18+ self-submission). Fails closed on any lookup miss.
 *
 * @spec openspec/specs/school-structure/spec.md#requirement-guardian-consent-gates-a-minor-s-subject-choice-submission
 */
class SubjectChoiceConsentGuard implements LifecycleGuardInterface {

	/**
	 * Reason shown to the caller when the transition is refused.
	 *
	 * @var string
	 */
	private const DENIAL = 'Only a guardian of this learner, or the adult learner, can submit this subject choice.';

	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession Current NC user session (server-resolved caller identity).
	 * @param ObjectService $objectService OR object access service.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly ObjectService $objectService,
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
	 * @spec openspec/specs/school-structure/spec.md#requirement-guardian-consent-gates-a-minor-s-subject-choice-submission
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($this->allows(object: $object) === true) {
			return GuardResult::allow();
		}

		return GuardResult::deny(self::DENIAL);
	}//end check()

	/**
	 * The rule behind check(), answered as a boolean.
	 *
	 * Called by OpenRegister's lifecycle engine before executing the `submit`
	 * transition on a SubjectChoice object. Resolves the caller's NC user id
	 * from the session (never from the request payload) and passes only when
	 * that user is a linked guardian of the choice's learnerId, or is the
	 * learner themselves.
	 *
	 * @param array<string,mixed> $object The object at its target state, transition inputs merged in.
	 *
	 * @return bool True if the caller may submit for this learner; false blocks the transition.
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-guardian-consent-gates-a-minor-s-subject-choice-submission
	 */
	private function allows(array $object): bool {
		$check = new GuardianSubmitCheck(userSession: $this->userSession, objectService: $this->objectService);
		$verdict = $check->verdict(object: $object);
		$log = new GuardianSubmitLog(logger: $this->logger, guard: 'SubjectChoiceConsentGuard');
		$log->refusal(verdict: $verdict, subject: 'SubjectChoice');

		return $verdict->isAllowed();
	}//end allows()
}//end class
