<?php

/**
 * Learniq OSO Dossier Review Guard
 *
 * Lifecycle guard for the DataExchangeJob schema's `approveDossier`
 * transition (`pending-parent-review → running`). Verifies that the actor
 * approving the dossier is listed as a parent/guardian of the learner whose
 * data is being transferred.
 *
 * The check reads the `scope.filters.learnerId` (or `scope.cohortId` for
 * cohort-wide OSO exports) from the DataExchangeJob, fetches the learner's
 * `LearnerProfile.parentIds`, and returns true only when the approving actor
 * is among them.
 *
 * Referenced from DataExchangeJob.x-openregister-lifecycle.transitions.approveDossier.requires.
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Guards the DataExchangeJob `pending-parent-review → running` transition.
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
	 * Allow the `pending-parent-review → running` transition.
	 *
	 * Returns true only when the actor in the transition context is listed in
	 * the learner's LearnerProfile.parentIds. The learnerId is read from the
	 * job's `scope.filters.learnerId` field. When no learnerId is resolvable
	 * (e.g. a cohort-wide export), this guard returns false and the transition
	 * must be triggered via administrative override outside this guard.
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

		// Resolve learnerId from scope.filters or scope directly.
		$scope = $object['scope'] ?? [];
		$filters = $scope['filters'] ?? [];
		$learnerId = $filters['learnerId'] ?? ($filters['ncUserId'] ?? '');

		if ($learnerId === '') {
			$this->logger->warning(
				'[OsoDossierReviewGuard] Job {id}: no learnerId in scope — cannot verify parent.',
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
				'register' => self::LEARNIQ_REGISTER,
				'schema' => self::LEARNER_PROFILE_SCHEMA,
				'filters' => $profileFilters,
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
