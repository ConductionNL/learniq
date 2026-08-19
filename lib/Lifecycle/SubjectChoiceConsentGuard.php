<?php

/**
 * Scholiq Subject Choice Consent Guard
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
 * scholiq_register.json.
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
 * @spec openspec/changes/admissions-and-subject-choice/specs/school-structure/spec.md#requirement-guardian-consent-gates-a-minor-s-subject-choice-submission
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

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
 * @spec openspec/changes/admissions-and-subject-choice/specs/school-structure/spec.md#requirement-guardian-consent-gates-a-minor-s-subject-choice-submission
 */
class SubjectChoiceConsentGuard {

	/**
	 * OR register slug for Scholiq objects.
	 */
	private const SCHOLIQ_REGISTER = 'scholiq';

	/**
	 * OR schema slug for LearnerProfile.
	 */
	private const LEARNER_PROFILE_SCHEMA = 'learner-profile';

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
	 * OR lifecycle guard entry-point.
	 *
	 * Called by OpenRegister's lifecycle engine before executing the `submit`
	 * transition on a SubjectChoice object. Resolves the caller's NC user id
	 * from the session (never from the request payload) and passes only when
	 * that user is a linked guardian of the choice's learnerId, or is the
	 * learner themselves.
	 *
	 * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
	 *                                               - 'object'     : the SubjectChoice data array
	 *                                               - 'transition' : 'submit'
	 *                                               - 'from'       : 'draft'
	 *                                               - 'to'         : 'submitted'
	 *
	 * @return bool True if the caller may submit for this learner; false blocks the transition.
	 *
	 * @spec openspec/changes/admissions-and-subject-choice/specs/school-structure/spec.md#requirement-guardian-consent-gates-a-minor-s-subject-choice-submission
	 */
	public function check(array &$transitionContext): bool {
		$object = $transitionContext['object'] ?? [];
		$learnerId = $object['learnerId'] ?? '';

		if ($learnerId === '') {
			$this->logger->warning(
				'[SubjectChoiceConsentGuard] SubjectChoice has no learnerId; blocking submit.'
			);
			return false;
		}

		$user = $this->userSession->getUser();

		if ($user === null) {
			$this->logger->info(
				'[SubjectChoiceConsentGuard] No authenticated user in session; blocking submit.'
			);
			return false;
		}

		$callerUid = $user->getUID();

		$profile = $this->loadLearnerProfile(learnerId: (string)$learnerId, tenantId: (string)($object['tenant_id'] ?? ''));
		if ($profile === null) {
			$this->logger->warning(
				'[SubjectChoiceConsentGuard] No LearnerProfile found for learnerId {learnerId}; blocking submit (fail closed).',
				['learnerId' => $learnerId]
			);
			return false;
		}

		if ($this->callerMayActForLearner(profile: $profile, callerUid: $callerUid) === true) {
			return true;
		}

		$this->logger->info(
			'[SubjectChoiceConsentGuard] Caller {caller} is not a linked guardian of learner '
			. '{learnerId} and is not the learner; blocking submit.',
			['caller' => $callerUid, 'learnerId' => $learnerId]
		);

		return false;
	}//end check()

	/**
	 * Load the LearnerProfile the subject choice is about, scoped to its tenant.
	 *
	 * @param string $learnerId The learner's NC user id.
	 * @param string $tenantId Tenant UUID, or '' when unknown.
	 *
	 * @return array<string,mixed>|null The profile, or null when it does not resolve.
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-guardian-consent-gates-a-minor-s-subject-choice-submission
	 */
	private function loadLearnerProfile(string $learnerId, string $tenantId): ?array {
		$filters = ['ncUserId' => $learnerId];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$profiles = $this->objectService->findAll(
			[
				'register' => self::SCHOLIQ_REGISTER,
				'schema' => self::LEARNER_PROFILE_SCHEMA,
				'filters' => $filters,
				'limit' => 1,
			]
		);

		if (empty($profiles) === true) {
			return null;
		}

		$profile = $profiles[0];
		if (is_array($profile) === false) {
			$profile = $profile->jsonSerialize();
		}

		return (array)$profile;
	}//end loadLearnerProfile()

	/**
	 * Whether the caller may submit for this learner: either they ARE the
	 * learner (18+ self-submission) or they are one of the linked guardians.
	 *
	 * Fails closed — anything that is not one of those two relationships is a no.
	 *
	 * @param array<string,mixed> $profile The learner's LearnerProfile.
	 * @param string $callerUid The authenticated caller's NC user id.
	 *
	 * @return bool True when the caller is authorised to act for this learner.
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-guardian-consent-gates-a-minor-s-subject-choice-submission
	 */
	private function callerMayActForLearner(array $profile, string $callerUid): bool {
		// 18+ self-submission: caller IS the learner.
		$profileNcUserId = ($profile['ncUserId'] ?? '');
		if ($profileNcUserId !== '' && $profileNcUserId === $callerUid) {
			return true;
		}

		// Linked guardian: caller's uid is in the LearnerProfile's parentIds.
		$parentIds = ($profile['parentIds'] ?? []);

		return (is_array($parentIds) === true && in_array($callerUid, $parentIds, true) === true);
	}//end callerMayActForLearner()
}//end class
