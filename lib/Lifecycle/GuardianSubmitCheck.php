<?php

/**
 * Learniq guardian-or-self submit check.
 *
 * The one authorization rule behind ConferenceSignupGuardianGuard and
 * SubjectChoiceConsentGuard: the caller (resolved server-side from the
 * session, never from the payload) may submit for the object's learner only
 * when they are a linked guardian (their NC user id is in the target
 * LearnerProfile's `parentIds`) or the learner themself (their NC user id is
 * the profile's own `ncUserId`). Fails closed on any lookup miss.
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
 * @spec openspec/specs/parent-conferences/spec.md#requirement-a-guardian-or-self-signup-submission-is-gated-by-a-per-object-authorization-guard
 * @spec openspec/specs/school-structure/spec.md#requirement-guardian-consent-gates-a-minor-s-subject-choice-submission
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Decides whether the session's caller may submit for an object's learner,
 * and why not when they may not. Built by the guard that owns the log lines.
 *
 * @spec openspec/specs/parent-conferences/spec.md#requirement-a-guardian-or-self-signup-submission-is-gated-by-a-per-object-authorization-guard
 */
final class GuardianSubmitCheck {

	/**
	 * OR register slug for Learniq objects.
	 */
	private const LEARNIQ_REGISTER = 'learniq';

	/**
	 * OR schema slug for LearnerProfile.
	 */
	private const LEARNER_PROFILE_SCHEMA = 'learner-profile';

	/**
	 * Constructor.
	 *
	 * @param IUserSession  $userSession   Current NC user session (server-resolved caller identity).
	 * @param ObjectService $objectService OR object access service.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The verdict for one object: allowed, or the first check that failed.
	 *
	 * @param array<string,mixed> $object The object at its target state, transition inputs merged in.
	 *
	 * @return GuardianSubmitVerdict
	 *
	 * @spec openspec/specs/parent-conferences/spec.md#requirement-a-guardian-or-self-signup-submission-is-gated-by-a-per-object-authorization-guard
	 */
	public function verdict(array $object): GuardianSubmitVerdict {
		$learnerId = $object['learnerId'] ?? '';
		if ($learnerId === '') {
			return new GuardianSubmitVerdict(outcome: GuardianSubmitVerdict::NO_LEARNER, context: []);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new GuardianSubmitVerdict(outcome: GuardianSubmitVerdict::NO_CALLER, context: []);
		}

		return $this->callerVerdict(object: $object, user: $user);
	}//end verdict()

	/**
	 * The verdict once the object names a learner and the session a caller.
	 *
	 * @param array<string,mixed> $object The object, its learnerId known to be set.
	 * @param IUser               $user   The session's caller.
	 *
	 * @return GuardianSubmitVerdict
	 */
	private function callerVerdict(array $object, IUser $user): GuardianSubmitVerdict {
		$learnerId = $object['learnerId'];
		$callerUid = $user->getUID();

		$profile = $this->loadLearnerProfile(learnerId: (string)$learnerId, tenantId: (string)($object['tenant_id'] ?? ''));
		if ($profile === null) {
			return new GuardianSubmitVerdict(outcome: GuardianSubmitVerdict::NO_PROFILE, context: ['learnerId' => $learnerId]);
		}

		return $this->relationshipVerdict(profile: $profile, context: ['caller' => $callerUid, 'learnerId' => $learnerId]);
	}//end callerVerdict()

	/**
	 * Allowed when the caller acts for the profile's learner, else refused
	 * as not a guardian.
	 *
	 * @param array<string,mixed>                    $profile The learner's LearnerProfile.
	 * @param array{caller:string,learnerId:mixed}   $context The caller and learner, as logged.
	 *
	 * @return GuardianSubmitVerdict
	 */
	private function relationshipVerdict(array $profile, array $context): GuardianSubmitVerdict {
		if ($this->callerMayActForLearner(profile: $profile, callerUid: $context['caller']) === true) {
			return new GuardianSubmitVerdict(outcome: GuardianSubmitVerdict::ALLOWED, context: []);
		}

		return new GuardianSubmitVerdict(outcome: GuardianSubmitVerdict::NOT_GUARDIAN, context: $context);
	}//end relationshipVerdict()

	/**
	 * Load the LearnerProfile the object is about, scoped to its tenant.
	 *
	 * @param string $learnerId The learner's NC user id.
	 * @param string $tenantId  Tenant UUID, or '' when unknown.
	 *
	 * @return array<string,mixed>|null The profile, or null when it does not resolve.
	 */
	private function loadLearnerProfile(string $learnerId, string $tenantId): ?array {
		$filters = ['ncUserId' => $learnerId];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$profiles = $this->objectService->findAll(
			[
				'filters' => array_merge(
					$filters,
					[
						'register' => self::LEARNIQ_REGISTER,
						'schema' => self::LEARNER_PROFILE_SCHEMA,
					]
				),
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
	 * Whether the caller may act for this learner: either they ARE the
	 * learner (18+ self-signup or self-submission) or they are one of the
	 * profile's linked guardians.
	 *
	 * Fails closed — anything that is not one of those two relationships is a no.
	 *
	 * @param array<string,mixed> $profile   The learner's LearnerProfile.
	 * @param string              $callerUid The authenticated caller's NC user id.
	 *
	 * @return bool True when the caller is authorised to act for this learner.
	 */
	private function callerMayActForLearner(array $profile, string $callerUid): bool {
		// 18+ self-signup: caller IS the learner.
		$profileNcUserId = ($profile['ncUserId'] ?? '');
		if ($profileNcUserId !== '' && $profileNcUserId === $callerUid) {
			return true;
		}

		// Linked guardian: caller's uid is in the LearnerProfile's parentIds.
		$parentIds = ($profile['parentIds'] ?? []);

		return (is_array($parentIds) === true && in_array($callerUid, $parentIds, true) === true);
	}//end callerMayActForLearner()
}//end class
