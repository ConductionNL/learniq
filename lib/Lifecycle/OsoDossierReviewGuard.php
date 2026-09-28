<?php

/**
 * Learniq OSO Dossier Review Guard
 *
 * Lifecycle guard for the DossierReview schema's `approve` and `reject`
 * transitions. Verifies that the actor deciding on an OSO or SWV file is
 * listed as a parent of the learner whose data would leave the school.
 *
 * The check reads the review's `learnerUserId`, fetches that learner's
 * `LearnerProfile.parentIds`, and allows only when the actor is among them.
 * The exchange gate refuses the integriq job until a review is approved
 * (data-exchange-to-integriq; before that change this guarded the
 * DataExchangeJob `approveDossier` transition).
 *
 * Referenced from DossierReview.x-openregister-lifecycle.transitions.{approve,reject}.requires.
 * OR resolves guards by fully-qualified class name from the schema — no
 * Application.php registration needed.
 *
 * ADR-031: single-responsibility guard — solely verifies parent identity for
 * the OSO dossier approval. No protocol or serialisation logic.
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-17
 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-the-gate-refuses-an-oso-or-swv-file-until-a-parent-approved-it
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Guards the DossierReview `pending → approved|rejected` transitions.
 *
 * Only a parent listed in the learner's LearnerProfile.parentIds may approve
 * an OSO dossier for transfer.
 */
class OsoDossierReviewGuard implements LifecycleGuardInterface {

	/**
	 * Reason shown to the caller when the transition is refused.
	 *
	 * @var string
	 */
	private const DENIAL = 'Only a parent of this learner can approve the dossier transfer.';

	private const LEARNIQ_REGISTER = 'learniq';
	private const LEARNER_PROFILE_SCHEMA = 'learner-profile';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
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
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-17
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($this->allows(object: $object, userId: $userId) === true) {
			return GuardResult::allow();
		}

		return GuardResult::deny(self::DENIAL);
	}//end check()

	/**
	 * Allow a parent's decision on the review.
	 *
	 * Returns true only when the actor in the transition context is listed in
	 * the learner's LearnerProfile.parentIds. The learner is the review's
	 * `learnerUserId`. Without one (a review opened for a cohort-wide export),
	 * this guard returns false: such a file needs a review per learner.
	 *
	 * @param array<string,mixed> $object The object at its target state, transition inputs merged in.
	 * @param string $userId The uid of the caller.
	 *
	 * @return bool True if the actor is a parent of the learner; false otherwise.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-17
	 */
	private function allows(array $object, string $userId): bool {
		$actor = $userId;
		$tenantId = $object['tenant_id'] ?? '';

		if ($actor === '') {
			$this->logger->warning('[OsoDossierReviewGuard] No acting user — denying approveDossier.');
			return false;
		}

		// The review names the learner's account; a DossierReview is created by
		// learniq together with the exchange request (data-exchange-to-integriq).
		$learnerId = (string)($object['learnerUserId'] ?? '');

		if ($learnerId === '') {
			$this->logger->warning(
				'[OsoDossierReviewGuard] Review {id}: no learnerUserId — cannot verify parent.',
				['id' => $object['id'] ?? '?']
			);
			return false;
		}

		// Fetch the learner's LearnerProfile to read parentIds.
		// H1: scope to the same tenant.
		$profileFilters = ['ncUserId' => $learnerId];
		if ($tenantId !== '') {
			$profileFilters['tenant_id'] = $tenantId;
		}

		$profiles = $this->objectService->findAll(
			[
				'filters' => array_merge(
					$profileFilters,
					[
						'register' => self::LEARNIQ_REGISTER,
						'schema' => self::LEARNER_PROFILE_SCHEMA,
					]
				),
				'limit' => 1,
			]
		);

		if (empty($profiles) === true) {
			$this->logger->warning(
				'[OsoDossierReviewGuard] No LearnerProfile found for learnerId {l} — denying.',
				['l' => $learnerId]
			);
			return false;
		}

		$profile = $profiles[0];
		if (is_array($profiles[0]) === false) {
			$profile = $profiles[0]->jsonSerialize();
		}

		$parentIds = $profile['parentIds'] ?? [];

		if (in_array($actor, $parentIds, true) === false) {
			$this->logger->info(
				'[OsoDossierReviewGuard] Actor {a} is not in parentIds for learner {l} — denying.',
				['a' => $actor, 'l' => $learnerId]
			);
			return false;
		}

		return true;
	}//end allows()
}//end class
